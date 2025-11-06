<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;

use App\Models\WixStore;
use App\Helpers\WixHelper;
use App\Models\WixOrderMigration;
// <<< contact mapping model
use App\Models\WixContactMigration;

class WixOrderController extends Controller
{
    /**
     * Small in-memory cache for per-auth settings lookups.
     * Prevents repeated orders-settings fetches and keeps logic deterministic.
     */
    private array $ordersSettingsCache = [];

    // ========================================================= Automatic Migrator (EXPORT side from New, IMPORT side kept + fixed) =========================================================
    public function migrateAuto(Request $request)
    {
        $request->validate([
            'from_store'   => 'required|string',
            'to_store'     => 'required|string|different:from_store',
            // optional filters (date + limit)
            'limit'        => 'nullable|integer|min:1',
            'created_from' => 'nullable|string', // YYYY-MM-DD or ISO8601
            'created_to'   => 'nullable|string', // YYYY-MM-DD or ISO8601
        ]);

        $userId = Auth::id() ?: 1;
        $fromId = $request->string('from_store');
        $toId   = $request->string('to_store');

        $fromStore = WixStore::where('instance_id', $fromId)->first();
        $toStore   = WixStore::where('instance_id', $toId)->first();

        $fromLabel = $fromStore?->store_name ?: $fromId;
        $toLabel   = $toStore?->store_name   ?: $toId;

        WixHelper::log('Auto Order Migration', "Start: {$fromLabel} → {$toLabel}.", 'info');

        // --- Tokens & auth headers
        $fromToken = WixHelper::getAccessToken($fromId);
        $toToken   = WixHelper::getAccessToken($toId);

        if (!$fromToken || !$toToken) {
            WixHelper::log('Auto Order Migration', 'Missing access token(s).', 'error');
            return back()->with('error', 'Could not get Wix access token(s).');
        }
        $fromAuth = $this->authHeader($fromToken);
        $toAuth   = $this->authHeader($toToken);

        // --- Optional controls
        $max         = $request->integer('limit') ?: null;
        $createdFrom = $request->input('created_from');
        $createdTo   = $request->input('created_to');

        // --- Source refs for enrichment
        $tagsIndexSrc   = $this->listOrderTagsIndex($fromAuth);   // [tagId => meta]
        $orderSettings  = $this->getOrderSettings($fromAuth);     // for optional copy

        // --- Fetch IDs with filters
        $orderIds = $this->getWixOrderIds($fromAuth, $max, $createdFrom, $createdTo);
        if (isset($orderIds['error'])) {
            WixHelper::log('Auto Order Migration', "Order IDs fetch error: " . ($orderIds['raw'] ?? $orderIds['error']) . '.', 'error');
            return back()->with('error', 'Failed to fetch source orders.');
        }

        // Optional: apply source order settings to destination (enables manual numbering if source had it)
        if (is_array($orderSettings)) {
            $this->updateOrderSettings($toAuth, $orderSettings);
        }

        // --- Batch invoices (context)
        $invoicesByOrder = $this->listInvoicesForOrders($fromAuth, $orderIds);

        // --- Row claim/resolve helpers
        $claimPendingRow = function (?string $sourceOrderId) use ($userId, $fromId) {
            return DB::transaction(function () use ($userId, $fromId, $sourceOrderId) {
                $row = null;
                if ($sourceOrderId) {
                    $row = WixOrderMigration::where('user_id', $userId)
                        ->where('from_store_id', $fromId)
                        ->where('status', 'pending')
                        ->where(function ($q) use ($sourceOrderId) {
                            $q->where('source_order_id', $sourceOrderId)
                              ->orWhereNull('source_order_id');
                        })
                        ->orderByRaw("CASE WHEN source_order_id = ? THEN 0 ELSE 1 END", [$sourceOrderId])
                        ->orderBy('created_at', 'asc')
                        ->lockForUpdate()
                        ->first();
                }
                if (!$row) {
                    $row = WixOrderMigration::where('user_id', $userId)
                        ->where('from_store_id', $fromId)
                        ->where('status', 'pending')
                        ->orderBy('created_at', 'asc')
                        ->lockForUpdate()
                        ->first();
                }
                return $row;
            }, 3);
        };

        $resolveTargetRow = function (?WixOrderMigration $claimed, ?string $sourceOrderId) use ($userId, $fromId, $toId) {
            if ($sourceOrderId) {
                $existing = WixOrderMigration::where('user_id', $userId)
                    ->where('from_store_id', $fromId)
                    ->where('to_store_id', $toId)
                    ->where('source_order_id', $sourceOrderId)
                    ->orderBy('created_at', 'asc')
                    ->first();

                if ($existing) {
                    if ($claimed && $claimed->id !== $existing->id && $claimed->status === 'pending') {
                        $claimed->update([
                            'status'        => 'skipped',
                            'error_message' => 'Merged into existing migration row id ' . $existing->id,
                        ]);
                    }
                    return $existing;
                }
            }
            return $claimed;
        };

        // --- Fetch full orders oldest→newest (and stage rows as pending)
        $fullOrders     = [];
        $refundsByOrder = [];

        foreach ($orderIds as $oid) {
            $o = $this->getWixFullOrder($fromAuth, $oid);
            if (!$o) continue;

            // Map tagIds → tagNames
            $tagIds = $o['tags']['privateTags']['tagIds'] ?? [];
            if ($tagIds) {
                $o['tags']['privateTags']['tagNames'] = array_values(array_filter(array_map(
                    fn($id) => $tagsIndexSrc[$id]['name'] ?? null, $tagIds
                )));
            }

            // Refundability
            $ref = $this->getOrderRefundability($fromAuth, $oid);
            if ($ref !== null) $o['refundability'] = $ref;

            // Aggregate refunds via transactions + refunds API
            $payments       = $o['transactions']['payments'] ?? [];
            $orderRefunds   = $this->collectRefundsFromPayments($payments);
            foreach ($payments as $p) {
                $chargeId = $p['regularPaymentDetails']['chargeId'] ?? ($p['chargeId'] ?? null);
                if ($chargeId) {
                    $found = $this->queryRefunds($fromAuth, ['chargeId' => $chargeId]);
                    foreach ($found as &$rf) {
                        $full = $this->getRefundById($fromAuth, $rf['id'] ?? '');
                        if ($full) $rf = $full['refund'] ?? $rf;
                    }
                    unset($rf);
                    $orderRefunds = array_merge($orderRefunds, $found);
                }
            }
            if ($orderRefunds) $refundsByOrder[$oid] = $orderRefunds;

            // Stage append-only pending row
            try {
                WixOrderMigration::create([
                    'user_id'               => $userId,
                    'from_store_id'         => $fromId,
                    'to_store_id'           => $toId,
                    'source_order_id'       => $o['id'],
                    'order_number'          => $o['number'] ?? null,
                    'destination_order_id'  => null,
                    'status'                => 'pending',
                    'error_message'         => null,
                ]);
            } catch (\Illuminate\Database\QueryException $e) {
                // duplicate pending is fine
            }

            $fullOrders[] = $o;
        }

        // Sort by created/purchased date ASC
        usort($fullOrders, function ($a, $b) {
            return strtotime($a['createdDate'] ?? $a['purchasedDate'] ?? '')
                <=> strtotime($b['createdDate'] ?? $b['purchasedDate'] ?? '');
        });

        // ---- Determine if manual numbering is honored on destination
        $manualAllowedDest = $this->isManualNumberingAllowed($this->getOrderSettingsCached($toAuth));

        // ---- Migrate to destination
        $imported = 0; $failed = 0; $processed = 0; $total = count($fullOrders);

        foreach ($fullOrders as $srcOrder) {
            $processed++;

            $sourceOrderId = $srcOrder['id'] ?? null;
            $orderNumber   = $srcOrder['number'] ?? null;

            // Prepare order payload
            $order = $srcOrder;

            $exportPaymentsRaw  = $srcOrder['transactions']['payments'] ?? [];
            $exportPaymentsFlat = $this->normalizePaymentsArray(['payments' => $exportPaymentsRaw]);
            $exportPayments     = array_map(fn($p) => $this->shapePaymentForAddPayment($p), $exportPaymentsFlat);

            $exportFulfillments = $order['fulfillments']['fulfillments'] ?? [];
            $exportRefunds      = $refundsByOrder[$sourceOrderId] ?? ($this->collectRefundsFromPayments($exportPaymentsRaw) ?: []);

            // Resolve/export tags into destination IDs
            $order['tags'] = $this->resolveOrderTagsForCreate($toAuth, $order['tags'] ?? [], $tagsIndexSrc);

            // Strip incompatible fields
            unset(
                $order['createdDate'], $order['updatedDate'],
                $order['siteLanguage'], $order['isInternalOrderCreate'], $order['seenByAHuman'],
                $order['transactions'], $order['fulfillments'], $order['refundability']
            );
            if (isset($srcOrder['purchasedDate'])) {
                $order['purchasedDate'] = $srcOrder['purchasedDate'];
            }
            if (!empty($order['lineItems'])) {
                foreach ($order['lineItems'] as &$li) {
                    unset($li['id'], $li['rootCatalogItemId'], $li['priceUndetermined'], $li['fixedQuantity'], $li['modifierGroups']);
                }
                unset($li);
            }
            if (isset($order['activities'])) {
                foreach ($order['activities'] as &$a) unset($a['id']);
                unset($a);
            }

            // Attach migrated contactId by matching buyer email
            $buyerEmail    = $this->extractBuyerEmail($srcOrder);
            $destContactId = $this->findDestContactId($userId, $fromId, $toId, $buyerEmail);
            if ($destContactId) {
                $order['buyerInfo'] = $order['buyerInfo'] ?? [];
                $order['buyerInfo']['contactId'] = $destContactId;
            }

            // Create order trying to preserve NUMBER ONLY IF ALLOWED; otherwise one-shot auto to avoid double increments
            $createRes = $this->createOrderInWixPreserveNumberIfAllowed(
                $toAuth,
                $order,
                $manualAllowedDest ? ($srcOrder['number'] ?? null) : null
            );

            if (!isset($createRes['order']['id'])) {
                $errMsg   = json_encode(['sent' => $this->redactLarge($order), 'response' => $createRes]);
                $claimed  = $claimPendingRow($sourceOrderId);
                $targetRow= $resolveTargetRow($claimed, $sourceOrderId);

                if ($targetRow) {
                    DB::transaction(function () use ($targetRow, $toId, $sourceOrderId, $orderNumber, $errMsg) {
                        $targetRow->update([
                            'to_store_id'          => $toId,
                            'source_order_id'      => $targetRow->source_order_id ?: ($sourceOrderId ?? null),
                            'order_number'         => $orderNumber ?? $targetRow->order_number,
                            'destination_order_id' => null,
                            'status'               => 'failed',
                            'error_message'        => $errMsg,
                        ]);
                    }, 3);
                } else {
                    $this->createRowSafely($userId, $fromId, $toId, $sourceOrderId, $orderNumber, null, 'failed', $errMsg);
                }

                WixHelper::log('Auto Order Migration', "Create failed #{$orderNumber}: {$errMsg}.", 'error');
                $failed++;
                if ($processed % 10 === 0) {
                    WixHelper::log('Auto Order Migration', "Progress: {$processed}/{$total}; imported={$imported}; failed={$failed}.", 'debug');
                }
                continue;
            }

            $destOrderId = $createRes['order']['id'];
            $destOrderNo = $createRes['order']['number'] ?? null;
            if ($orderNumber && $destOrderNo && $destOrderNo !== $orderNumber) {
                WixHelper::log('Auto Order Migration', "Order number requested {$orderNumber} but created as {$destOrderNo} (taken / auto-assigned).", 'warn');
            } else {
                WixHelper::log('Auto Order Migration', "Created order {$destOrderNo} → {$destOrderId}.", 'success');
            }

            // Post-create enrichment (payments, fulfillments, refunds)
            $destOrderData       = $this->getOrderByIdLight($toAuth, $destOrderId);
            $destLineItems       = $destOrderData['lineItems'] ?? [];
            $lineItemMap         = $this->buildLineItemMap($srcOrder['lineItems'] ?? [], $destLineItems);

            // Payments delta
            $existingPayments = $this->listPaymentsForOrder($toAuth, $destOrderId);
            $newPayments      = $this->filterNewPayments($existingPayments, $exportPayments);
            if ($newPayments) {
                $addRes = $this->addPaymentsToOrderInWix($toAuth, $destOrderId, $newPayments);
                if (!$addRes['success']) {
                    WixHelper::log('Auto Order Migration', "Add payments failed #{$destOrderNo}: " . $addRes['error'] . '.', 'error');
                } else {
                    $existingPayments = $this->listPaymentsForOrder($toAuth, $destOrderId);
                }
            }

            // Fulfillments delta
            $existingFulfillments = $this->listFulfillmentsForOrder($toAuth, $destOrderId);
            $toCreateFulfillments = $this->computeFulfillmentDeltas($exportFulfillments, $existingFulfillments, $lineItemMap, $destLineItems);
            foreach ($toCreateFulfillments as $fPayload) {
                $fRes = $this->createFulfillmentInWix($toAuth, $destOrderId, $fPayload);
                if (!$fRes['success']) {
                    WixHelper::log('Auto Order Migration', "Fulfillment failed #{$destOrderNo}: " . $fRes['error'] . '.', 'error');
                }
            }

            // Refunds
            if (!empty($exportRefunds)) {
                $refundErr = $this->performRefundsIdempotent($toAuth, $destOrderId, $exportRefunds, $existingPayments, $lineItemMap);
                if ($refundErr) {
                    WixHelper::log('Auto Order Migration', "Refunds error #{$destOrderNo}: {$refundErr}.", 'warn');
                }
            }

            // Mark row success
            $claimed   = $claimPendingRow($sourceOrderId);
            $targetRow = $resolveTargetRow($claimed, $sourceOrderId);
            if ($targetRow) {
                DB::transaction(function () use ($targetRow, $toId, $sourceOrderId, $orderNumber, $destOrderId) {
                    $targetRow->update([
                        'to_store_id'          => $toId,
                        'source_order_id'      => $targetRow->source_order_id ?: ($sourceOrderId ?? null),
                        'order_number'         => $orderNumber ?? $targetRow->order_number,
                        'destination_order_id' => $destOrderId,
                        'status'               => 'success',
                        'error_message'        => null,
                    ]);
                }, 3);
            } else {
                $this->createRowSafely($userId, $fromId, $toId, $sourceOrderId, $orderNumber, $destOrderId, 'success', null);
            }

            $imported++;
            if ($processed % 10 === 0) {
                WixHelper::log('Auto Order Migration', "Progress: {$processed}/{$total}; imported={$imported}; failed={$failed}.", 'debug');
            }
        }

        $summary = "Orders: imported={$imported}, failed={$failed}.";

        if ($imported > 0) {
            WixHelper::log('Auto Order Migration', "Done. {$summary}", $failed ? 'warn' : 'success');
            $resp = back()->with('success', "Auto migration completed. {$summary}");
            if ($failed > 0) $resp = $resp->with('warning', 'Some orders failed to migrate. Check logs for details.');
            return $resp;
        }

        if ($failed > 0) {
            WixHelper::log('Auto Order Migration', "Done. {$summary}", 'error');
            return back()->with('error', "No orders imported. {$summary}");
        }

        WixHelper::log('Auto Order Migration', 'Done. Nothing to import.', 'info');
        return back()->with('success', 'Nothing to import.');
    }

    // ========================================================= Manual Migrator =========================================================
    // ========================= EXPORT (KEEP from NEW) =========================
    public function export(WixStore $store, Request $request)
    {
        $userId      = Auth::id() ?: 1;
        $fromStoreId = $store->instance_id;

        WixHelper::log('Export Orders', "Start: {$store->store_name} ({$fromStoreId}).", 'info');

        $accessToken = WixHelper::getAccessToken($fromStoreId);
        if (!$accessToken) {
            WixHelper::log('Export Orders', 'Failed: Could not get access token.', 'error');
            return response()->json(['error' => 'Could not get Wix access token.'], 401);
        }
        $auth = $this->authHeader($accessToken);

        // optional controls
        $max         = $request->integer('limit') ?: null;
        $createdFrom = $request->input('created_from');
        $createdTo   = $request->input('created_to');

        // 0) Tags index
        $tagsIndex = $this->listOrderTagsIndex($auth);

        // 1) Order IDs (with filters)
        $orderIds = $this->getWixOrderIds($auth, $max, $createdFrom, $createdTo);
        if (isset($orderIds['error'])) {
            WixHelper::log('Export Orders', "Order IDs fetch error: " . ($orderIds['raw'] ?? $orderIds['error']) . '.', 'error');
            return response()->json(['error' => $orderIds['error']], 500);
        }
        WixHelper::log('Export Orders', 'Found ' . count($orderIds) . ' order ID(s).', 'info');

        // 2) Invoices by order (batch)
        $invoicesByOrder = $this->listInvoicesForOrders($auth, $orderIds);

        $fullOrders       = [];
        $refundsByOrder   = [];

        foreach ($orderIds as $orderId) {
            $order = $this->getWixFullOrder($auth, $orderId);
            if (!$order) continue;

            // Refundability
            $refundability = $this->getOrderRefundability($auth, $orderId);
            if ($refundability !== null) $order['refundability'] = $refundability;

            // Map tag IDs -> names
            $tagIds = $order['tags']['privateTags']['tagIds'] ?? [];
            if (is_array($tagIds) && $tagIds) {
                $order['tags']['privateTags']['tagNames'] = array_values(
                    array_map(function($id) use ($tagsIndex) { return $tagsIndex[$id]['name'] ?? null; }, $tagIds)
                );
            }

            // Refunds enrichment
            $payments     = $order['transactions']['payments'] ?? [];
            $orderRefunds = [];
            foreach ($payments as $p) {
                if (!empty($p['refunds'])) {
                    foreach ($p['refunds'] as $rf) $orderRefunds[] = $rf;
                }
                $chargeId = $p['regularPaymentDetails']['chargeId'] ?? ($p['chargeId'] ?? null);
                if ($chargeId) {
                    $refundsFromApi = $this->queryRefunds($auth, ['chargeId' => $chargeId]);
                    if ($refundsFromApi) {
                        foreach ($refundsFromApi as &$rf) {
                            $full = $this->getRefundById($auth, $rf['id'] ?? '');
                            if ($full) $rf = $full['refund'] ?? $rf;
                        }
                        unset($rf);
                        $orderRefunds = array_merge($orderRefunds, $refundsFromApi);
                    }
                }
            }
            if ($orderRefunds) $refundsByOrder[$orderId] = $orderRefunds;

            // Append-only pending row (export phase)
            try {
                WixOrderMigration::create([
                    'user_id'                => $userId,
                    'from_store_id'          => $fromStoreId,
                    'to_store_id'            => null,
                    'source_order_id'        => $order['id'],
                    'order_number'           => $order['number'] ?? null,
                    'destination_order_id'   => null,
                    'status'                 => 'pending',
                    'error_message'          => null,
                ]);
            } catch (QueryException $e) {
                WixHelper::log('Export Orders', 'Pending row exists for source_order_id=' . $order['id'] . ' (skipped).', 'debug');
            }

            $fullOrders[] = $order;
        }

        // 4) Draft Orders (kept; harmless if unused)
        $draftFilter = $request->input('draft_filter', []);
        $draftOrders = $this->queryDraftOrders($auth, $draftFilter);

        // 5) Order settings
        $orderSettings = $this->getOrderSettings($auth);

        $payload = [
            'from_store_id'      => $fromStoreId,
            'orders'             => $fullOrders,
            'draftOrders'        => $draftOrders,
            'orderSettings'      => $orderSettings,
            'tagsIndex'          => $tagsIndex,
            'invoicesByOrder'    => $invoicesByOrder,
            'refundsByOrder'     => $refundsByOrder,
            'generated_at'       => now()->toIso8601String(),
        ];

        WixHelper::log(
            'Export Orders',
            'Exported ' . count($fullOrders) . ' orders, ' . count($draftOrders) . ' draft orders, '
            . count($refundsByOrder) . ' orders with refunds, invoices for ' . count($invoicesByOrder) . ' orders.',
            'success'
        );

        return response()->streamDownload(function () use ($payload) {
            echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }, 'orders_full_export.json', [
            'Content-Type' => 'application/json'
        ]);
    }

    // ========================= IMPORT (KEEP from OLD, with numbering fix to avoid gaps) =========================
    public function import(Request $request, WixStore $store)
    {
        $userId    = Auth::id() ?: 1;
        $toStoreId = $store->instance_id;

        WixHelper::log('Import Orders', "Start: {$store->store_name} ({$toStoreId}).", 'info');

        $accessToken = WixHelper::getAccessToken($toStoreId);
        if (!$accessToken) {
            WixHelper::log('Import Orders', 'Failed: Could not get access token.', 'error');
            return back()->with('error', 'Could not get Wix access token.');
        }
        $auth = $this->authHeader($accessToken);

        if (!$request->hasFile('orders_json')) {
            WixHelper::log('Import Orders', 'No file uploaded.', 'error');
            return back()->with('error', 'No file uploaded.');
        }

        $json = file_get_contents($request->file('orders_json')->getRealPath());
        $data = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data) || !isset($data['orders'])) {
            WixHelper::log('Import Orders', "Uploaded file is not valid JSON or missing 'orders' key.", 'error');
            return back()->with('error', "Uploaded file is not valid JSON or missing 'orders' key.");
        }

        // Optional: apply order settings (to enable manual numbering if source had it)
        if (isset($data['orderSettings']) && is_array($data['orderSettings'])) {
            $this->updateOrderSettings($auth, $data['orderSettings']);
        }

        $exportTagsIndex = $data['tagsIndex'] ?? [];
        $fromStoreId     = $data['from_store_id'] ?? 'unknown';
        $orders          = $data['orders'];

        // oldest → newest
        usort($orders, function ($a, $b) {
            return strtotime($a['createdDate'] ?? $a['purchasedDate'] ?? '')
                <=> strtotime($b['createdDate'] ?? $b['purchasedDate'] ?? '');
        });

        $refundsByOrder = $data['refundsByOrder'] ?? [];

        // Row claiming & dedupe
        $claimPendingRow = function (?string $sourceOrderId) use ($userId, $fromStoreId) {
            return DB::transaction(function () use ($userId, $fromStoreId, $sourceOrderId) {
                $row = null;

                if ($sourceOrderId) {
                    $row = WixOrderMigration::where('user_id', $userId)
                        ->where('from_store_id', $fromStoreId)
                        ->where('status', 'pending')
                        ->where(function ($q) use ($sourceOrderId) {
                            $q->where('source_order_id', $sourceOrderId)
                              ->orWhereNull('source_order_id');
                        })
                        ->orderByRaw("CASE WHEN source_order_id = ? THEN 0 ELSE 1 END", [$sourceOrderId])
                        ->orderBy('created_at', 'asc')
                        ->lockForUpdate()
                        ->first();
                }

                if (!$row) {
                    $row = WixOrderMigration::where('user_id', $userId)
                        ->where('from_store_id', $fromStoreId)
                        ->where('status', 'pending')
                        ->orderBy('created_at', 'asc')
                        ->lockForUpdate()
                        ->first();
                }

                return $row;
            }, 3);
        };

        $resolveTargetRow = function (?WixOrderMigration $claimed, ?string $sourceOrderId) use ($userId, $fromStoreId, $toStoreId) {
            if ($sourceOrderId) {
                $existing = WixOrderMigration::where('user_id', $userId)
                    ->where('from_store_id', $fromStoreId)
                    ->where('to_store_id', $toStoreId)
                    ->where('source_order_id', $sourceOrderId)
                    ->orderBy('created_at', 'asc')
                    ->first();

                if ($existing) {
                    if ($claimed && $claimed->id !== $existing->id && $claimed->status === 'pending') {
                        $claimed->update([
                            'status'        => 'skipped',
                            'error_message' => 'Merged into existing migration row id ' . $existing->id,
                        ]);
                    }
                    return $existing;
                }
            }
            return $claimed;
        };

        // Determine if manual numbering is honored on destination
        $manualAllowedDest = $this->isManualNumberingAllowed($this->getOrderSettingsCached($auth));

        $imported = 0; $failed = 0; $processed = 0; $total = count($orders);

        foreach ($orders as $srcOrder) {
            $processed++;

            $sourceOrderId = $srcOrder['id'] ?? null;
            $orderNumber   = $srcOrder['number'] ?? null;

            // Prepare payload — keep number only if manual numbering is allowed; otherwise auto
            $order = $srcOrder;

            $exportPaymentsRaw  = $srcOrder['transactions']['payments'] ?? [];
            $exportPaymentsFlat = $this->normalizePaymentsArray(['payments' => $exportPaymentsRaw]);
            $exportPayments     = array_map(fn($p) => $this->shapePaymentForAddPayment($p), $exportPaymentsFlat);

            $exportFulfillments = $order['fulfillments']['fulfillments'] ?? [];
            $exportRefunds      = $refundsByOrder[$sourceOrderId] ?? ($this->collectRefundsFromPayments($exportPaymentsRaw));

            $order['tags'] = $this->resolveOrderTagsForCreate($auth, $order['tags'] ?? [], $exportTagsIndex);

            unset(
                $order['createdDate'], $order['updatedDate'],
                $order['siteLanguage'], $order['isInternalOrderCreate'], $order['seenByAHuman'],
                $order['transactions'], $order['fulfillments'], $order['refundability']
            );
            if (isset($srcOrder['purchasedDate'])) {
                $order['purchasedDate'] = $srcOrder['purchasedDate'];
            }
            if (!empty($order['lineItems'])) {
                foreach ($order['lineItems'] as &$li) {
                    unset($li['id'], $li['rootCatalogItemId'], $li['priceUndetermined'], $li['fixedQuantity'], $li['modifierGroups']);
                }
                unset($li);
            }
            if (isset($order['activities'])) {
                foreach ($order['activities'] as &$a) unset($a['id']);
                unset($a);
            }

            // Attach migrated contactId by matching buyer email
            $buyerEmail    = $this->extractBuyerEmail($srcOrder);
            $destContactId = $this->findDestContactId($userId, $fromStoreId, $toStoreId, $buyerEmail);
            if ($destContactId) {
                $order['buyerInfo'] = $order['buyerInfo'] ?? [];
                $order['buyerInfo']['contactId'] = $destContactId;
            }

            // Create Order (PRESERVE NUMBER ONLY IF ALLOWED) — single call when not allowed to avoid sequence gaps
            $createRes = $this->createOrderInWixPreserveNumberIfAllowed(
                $auth,
                $order,
                $manualAllowedDest ? ($srcOrder['number'] ?? null) : null
            );

            if (!isset($createRes['order']['id'])) {
                $errMsg = json_encode(['sent' => $this->redactLarge($order), 'response' => $createRes]);

                // Claim/resolve a row, then mark failed
                $claimed   = $claimPendingRow($sourceOrderId);
                $targetRow = $resolveTargetRow($claimed, $sourceOrderId);

                if ($targetRow) {
                    DB::transaction(function () use ($targetRow, $toStoreId, $sourceOrderId, $orderNumber, $errMsg) {
                        $targetRow->update([
                            'to_store_id'           => $toStoreId,
                            'source_order_id'       => $targetRow->source_order_id ?: ($sourceOrderId ?? null),
                            'order_number'          => $orderNumber ?? $targetRow->order_number,
                            'destination_order_id'  => null,
                            'status'                => 'failed',
                            'error_message'         => $errMsg,
                        ]);
                    }, 3);
                } else {
                    $this->createRowSafely($userId, $fromStoreId, $toStoreId, $sourceOrderId, $orderNumber, null, 'failed', $errMsg);
                }

                WixHelper::log('Import Orders', "Failed to create order {$orderNumber}: {$errMsg}.", 'error');
                $failed++;
                if ($processed % 10 === 0) {
                    WixHelper::log('Import Orders', "Progress: {$processed}/{$total}; imported={$imported}; failed={$failed}.", 'debug');
                }
                continue;
            }

            $destOrderId = $createRes['order']['id'];
            $destOrderNo = $createRes['order']['number'] ?? null;
            if ($orderNumber && $destOrderNo && $destOrderNo !== $orderNumber) {
                WixHelper::log('Import Orders', "Order number requested {$orderNumber} but created as {$destOrderNo} (taken / auto-assigned).", 'warn');
            } else {
                WixHelper::log('Import Orders', "Created order {$destOrderNo} → {$destOrderId}.", 'success');
            }

            // Refresh destination order items
            $destOrderData = $this->getOrderByIdLight($auth, $destOrderId);
            $destLineItems = $destOrderData['lineItems'] ?? [];
            $lineItemMap   = $this->buildLineItemMap($srcOrder['lineItems'] ?? [], $destLineItems);

            // Payments (idempotent)
            $existingPayments = $this->listPaymentsForOrder($auth, $destOrderId);
            $newPayments      = $this->filterNewPayments($existingPayments, $exportPayments);
            if (!empty($newPayments)) {
                $addRes = $this->addPaymentsToOrderInWix($auth, $destOrderId, $newPayments);
                if (!$addRes['success']) {
                    WixHelper::log('Import Orders', "Add payments failed for {$destOrderNo}: " . $addRes['error'] . '.', 'error');
                } else {
                    $existingPayments = $this->listPaymentsForOrder($auth, $destOrderId);
                }
            }

            // Fulfillments (delta)
            $existingFulfillments = $this->listFulfillmentsForOrder($auth, $destOrderId);
            $toCreateFulfillments = $this->computeFulfillmentDeltas(
                $exportFulfillments, $existingFulfillments, $lineItemMap, $destLineItems
            );
            foreach ($toCreateFulfillments as $fPayload) {
                $fRes = $this->createFulfillmentInWix($auth, $destOrderId, $fPayload);
                if (!$fRes['success']) {
                    WixHelper::log('Import Orders', "Fulfillment create failed for {$destOrderNo}: " . $fRes['error'] . '.', 'error');
                }
            }

            // Refunds (idempotent; externalRefund=true)
            if (!empty($exportRefunds)) {
                $refundErr = $this->performRefundsIdempotent(
                    $auth, $destOrderId, $exportRefunds, $existingPayments, $lineItemMap
                );
                if ($refundErr) {
                    WixHelper::log('Import Orders', "Refunds err for {$destOrderNo}: {$refundErr}.", 'warn');
                }
            }

            // Claim/resolve row & mark success
            $claimed   = $claimPendingRow($sourceOrderId);
            $targetRow = $resolveTargetRow($claimed, $sourceOrderId);

            if ($targetRow) {
                DB::transaction(function () use ($targetRow, $toStoreId, $sourceOrderId, $orderNumber, $destOrderId) {
                    $targetRow->update([
                        'to_store_id'           => $toStoreId,
                        'source_order_id'       => $targetRow->source_order_id ?: ($sourceOrderId ?? null),
                        'order_number'          => $orderNumber ?? $targetRow->order_number,
                        'destination_order_id'  => $destOrderId,
                        'status'                => 'success',
                        'error_message'         => null,
                    ]);
                }, 3);
            } else {
                $this->createRowSafely($userId, $fromStoreId, $toStoreId, $sourceOrderId, $orderNumber, $destOrderId, 'success', null);
            }

            $imported++;
            if ($processed % 10 === 0) {
                WixHelper::log('Import Orders', "Progress: {$processed}/{$total}; imported={$imported}; failed={$failed}.", 'debug');
            }
        }

        if ($imported > 0) {
            return back()->with($failed ? 'warning' : 'success', "{$imported} order(s) imported." . ($failed ? " Failed: {$failed}." : ""));
        }
        return back()->with('error', $failed ? "No orders imported. Failed: {$failed}." : 'Nothing to import.');
    }

    // =========================================================
    // CORE API HELPERS
    // =========================================================

    private function authHeader(string $token): string
    {
        return preg_match('/^Bearer\s+/i', $token) ? $token : ('Bearer ' . $token);
    }

    // Supports optional limit + createdDate filters; uses /ecom/v1/orders/search
    private function getWixOrderIds(
        string $auth,
        ?int $max = null,
        ?string $createdFrom = null,
        ?string $createdTo = null
    ) {
        $fmt = function (?string $d, bool $isEnd = false): ?string {
            if (!$d) return null;
            $d = trim($d);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                return $d . ($isEnd ? 'T23:59:59.999Z' : 'T00:00:00.000Z');
            }
            if (!preg_match('/Z$/', $d)) {
                if (!preg_match('/[+-]\d{2}:\d{2}$/', $d)) $d .= 'Z';
            }
            if (!preg_match('/\.\d{3}Z$/', $d)) {
                $d = preg_replace('/Z$/', '.000Z', $d);
            }
            return $d;
        };

        $gte = $fmt($createdFrom, false);
        $lte = $fmt($createdTo,   true);

        $max = ($max && $max > 0) ? $max : null;

        $makeFilter = function (string $field, ?string $gte, ?string $lte): array {
            $and = [];
            if ($gte) $and[] = [ $field => [ '$gte' => $gte ] ];
            if ($lte) $and[] = [ $field => [ '$lte' => $lte ] ];
            if (!$and) return [];
            return [ '$and' => $and ];
        };

        $orderIds = [];
        $cursor   = null;

        $fieldsToTry = [
            ['field' => 'createdDate'],
            ['field' => 'purchasedDate'],
        ];

        $lastErr = null;

        foreach ($fieldsToTry as $try) {
            $cursor   = null;
            $orderIds = [];

            do {
                $remaining = $max ? ($max - count($orderIds)) : 100;
                if ($remaining <= 0) break;
                $pageLimit = max(1, min(100, $remaining));

                $payload = [
                    'search' => [
                        'sort'         => [ ['fieldName' => 'createdDate', 'order' => 'ASC'] ],
                        'cursorPaging' => ['limit' => $pageLimit] + ($cursor ? ['cursor' => $cursor] : []),
                    ],
                ];

                $flt = $makeFilter($try['field'], $gte, $lte);
                if (!empty($flt)) $payload['search']['filter'] = $flt;

                $resp = Http::withHeaders([
                    'Authorization' => $auth,
                    'Content-Type'  => 'application/json',
                ])->post('https://www.wixapis.com/ecom/v1/orders/search', $payload);

                if (!$resp->ok()) {
                    $lastErr = $resp->body();
                    $orderIds = [];
                    break;
                }

                $data = $resp->json();
                foreach (($data['orders'] ?? []) as $row) {
                    if (!empty($row['id'])) {
                        $orderIds[] = $row['id'];
                        if ($max && count($orderIds) >= $max) break;
                    }
                }
                if ($max && count($orderIds) >= $max) break;

                $cursor = $data['metadata']['pagingMetadata']['cursors']['next'] ?? null;
            } while ($cursor);

            if ($orderIds) return $orderIds;
            if (!$gte && !$lte) return $orderIds;
        }

        return ['error' => 'Failed to fetch order IDs from Wix.', 'raw' => $lastErr ?: 'Unknown error'];
    }

    private function getWixFullOrder(string $auth, string $orderId): ?array
    {
        $orderResp = Http::withHeaders([
            'Authorization' => $auth,
            'Content-Type'  => 'application/json',
        ])->get("https://www.wixapis.com/ecom/v1/orders/{$orderId}");
        if (!$orderResp->ok()) {
            WixHelper::log('Export Orders', "Order {$orderId} fetch failed: " . $orderResp->body() . '.', 'error');
            return null;
        }
        $order = $orderResp->json('order');
        if (!$order) return null;

        $txResp = Http::withHeaders([
            'Authorization' => $auth,
            'Content-Type'  => 'application/json',
        ])->get("https://www.wixapis.com/ecom/v1/payments/orders/{$orderId}");
        $order['transactions'] = $txResp->json('orderTransactions') ?? [];

        $fulResp = Http::withHeaders([
            'Authorization' => $auth,
            'Content-Type'  => 'application/json',
        ])->get("https://www.wixapis.com/ecom/v1/fulfillments/orders/{$orderId}");
        $order['fulfillments'] = $fulResp->json('orderWithFulfillments') ?? [];

        return $order;
    }

    private function getOrderRefundability(string $auth, string $orderId): ?array
    {
        $resp = Http::withHeaders([
            'Authorization' => $auth,
            'Content-Type'  => 'application/json',
        ])->post('https://www.wixapis.com/ecom/v1/order-billing/get-order-refundability', [
            'orderId' => $orderId,
        ]);
        if ($resp->ok()) return $resp->json();
        WixHelper::log('Export Orders', "Refundability fetch failed for {$orderId}: " . $resp->body() . '.', 'warn');
        return null;
    }

    private function queryDraftOrders(string $auth, array $filter = []): array
    {
        $all = []; $cursor = null;
        do {
            $payload = [
                'query' => [
                    'filter'       => (object) $filter,
                    'sort'         => [],
                    'cursorPaging' => ['limit' => 100] + ($cursor ? ['cursor' => $cursor] : []),
                ],
            ];
            $resp = Http::withHeaders([
                'Authorization' => $auth,
                'Content-Type'  => 'application/json',
            ])->post('https://www.wixapis.com/ecom/v1/draft-orders/query', $payload);

            if (!$resp->ok()) { WixHelper::log('Export Orders', 'Draft query failed: ' . $resp->body() . '.', 'warn'); break; }
            $data   = $resp->json();
            $page   = $data['draftOrders'] ?? [];
            $all    = array_merge($all, $page);
            $cursor = $data['pagingMetadata']['cursors']['next'] ?? null;
        } while ($cursor);
        return $all;
    }

    private function getOrderSettings(string $auth)
    {
        $resp = Http::withHeaders([
            'Authorization' => $auth,
            'Content-Type'  => 'application/json',
        ])->get('https://www.wixapis.com/ecom/v1/orders-settings');
        if ($resp->ok()) return $resp->json('ordersSettings');
        WixHelper::log('Orders Settings', 'Fetch failed: ' . $resp->body() . '.', 'error');
        return null;
    }

    private function getOrderSettingsCached(string $auth)
    {
        if (!isset($this->ordersSettingsCache[$auth])) {
            $this->ordersSettingsCache[$auth] = $this->getOrderSettings($auth);
        }
        return $this->ordersSettingsCache[$auth];
    }

    private function updateOrderSettings(string $auth, array $settings): bool
    {
        $resp = Http::withHeaders([
            'Authorization' => $auth,
            'Content-Type'  => 'application/json',
        ])->patch('https://www.wixapis.com/ecom/v1/orders-settings', [
            'ordersSettings' => $settings,
        ]);

        if ($resp->ok()) {
            // refresh cache
            $this->ordersSettingsCache[$auth] = $this->getOrderSettings($auth);
            WixHelper::log('Import Orders', 'Orders settings updated.', 'info');
            return true;
        }

        WixHelper::log('Import Orders', 'Failed to update orders settings: ' . $resp->body() . '.', 'error');
        return false;
    }

    private function createOrderInWix(string $auth, array $order): array
    {
        $resp = Http::withHeaders([
            'Authorization' => $auth,
            'Content-Type'  => 'application/json',
        ])->post('https://www.wixapis.com/ecom/v1/orders', ['order' => $order]);
        return $resp->json();
    }

    /**
     * Only tries to set 'number' when destination settings say it's allowed.
     * Why: If manual numbering is not honored, attempting it first can reserve/increment
     * Wix's internal counter even on failure, producing gaps (e.g., +2 each import).
     *
     * Behaviour:
     *  - If $desiredNumber is non-null AND settings allow manual numbering:
     *      Attempt once with 'number' = $desiredNumber.
     *      If it fails, fallback to a single auto-generated attempt (no second manual attempt).
     *  - Otherwise:
     *      Single auto-generated attempt (no manual try) → ensures +1 increments.
     */
    private function createOrderInWixPreserveNumberIfAllowed(string $auth, array $cleanOrder, ?string $desiredNumber): array
    {
        $settings = $this->getOrderSettingsCached($auth);
        $manual   = $this->isManualNumberingAllowed($settings);

        if ($manual && $desiredNumber) {
            $try = $cleanOrder;
            unset($try['id']);
            $try['number'] = $desiredNumber;
            $res = $this->createOrderInWix($auth, $try);
            if (!empty($res['order']['id'])) return $res;

            // Fallback: strict single auto attempt (remove number) to avoid double bumps
            $auto = $cleanOrder;
            unset($auto['id'], $auto['number']);
            return $this->createOrderInWix($auth, $auto);
        }

        // Manual not allowed or no desired number → single auto attempt
        $auto = $cleanOrder;
        unset($auto['id'], $auto['number']);
        return $this->createOrderInWix($auth, $auto);
    }

    /**
     * Detect whether manual order numbering is honored in current ordersSettings.
     * We check a handful of likely flags/structures to be robust across API variants.
     */
    private function isManualNumberingAllowed($ordersSettings): bool
    {
        if (!is_array($ordersSettings)) return false;

        // Common/likely places:
        $candidates = [
            // e.g. ['orderNumberSettings' => ['manualNumberingEnabled' => true]]
            fn($s) => $s['orderNumberSettings']['manualNumberingEnabled'] ?? null,
            fn($s) => $s['orderNumberSettings']['allowManualNumber'] ?? null,
            fn($s) => $s['orderNumberSettings']['generationMethod'] ?? null, // "MANUAL" or "AUTOMATIC"
            fn($s) => $s['manualNumberingEnabled'] ?? null,
            fn($s) => $s['allowManualNumber'] ?? null,
        ];

        foreach ($candidates as $cb) {
            $val = $cb($ordersSettings);
            if (is_bool($val)) return $val;
            if (is_string($val) && strtoupper($val) === 'MANUAL') return true;
        }
        return false;
    }

    private function createFulfillmentInWix(string $auth, string $orderId, array $fulfillment): array
    {
        $payload = [
            'fulfillment' => [
                'lineItems'    => $fulfillment['lineItems'] ?? [],
                'trackingInfo' => $fulfillment['trackingInfo'] ?? null,
                'status'       => $fulfillment['status'] ?? 'Fulfilled',
                'completed'    => $fulfillment['completed'] ?? true,
            ],
        ];
        $resp = Http::withHeaders([
            'Authorization' => $auth,
            'Content-Type'  => 'application/json',
        ])->post("https://www.wixapis.com/ecom/v1/fulfillments/orders/{$orderId}/create-fulfillment", $payload);
        if ($resp->ok()) return ['success' => true];
        return ['success' => false, 'error' => $resp->body()];
    }

    private function addPaymentsToOrderInWix(string $auth, string $orderId, array $transactions): array
    {
        $payments = [];
        foreach ($transactions as $p) {
            $entry = [
                'amount'         => $p['amount'] ?? ['amount' => '0.00'],
                'refundDisabled' => $p['refundDisabled'] ?? false,
            ];
            if (!empty($p['createdDate'])) $entry['createdDate'] = $p['createdDate'];
            if (!empty($p['regularPaymentDetails']))  $entry['regularPaymentDetails']  = $p['regularPaymentDetails'];
            if (!empty($p['giftcardPaymentDetails'])) $entry['giftcardPaymentDetails'] = $p['giftcardPaymentDetails'];
            if (!empty($p['status'])) $entry['status'] = $p['status'];

            $payments[] = $entry;
        }

        $resp = Http::withHeaders([
            'Authorization' => $auth,
            'Content-Type'  => 'application/json',
        ])->post("https://www.wixapis.com/ecom/v1/payments/orders/{$orderId}/add-payment", [
            'payments' => $payments,
        ]);
        if ($resp->ok()) return ['success' => true];
        return ['success' => false, 'error' => $resp->body()];
    }

    private function queryRefunds(string $auth, array $filter): array
    {
        $all = []; $cursor = null;
        do {
            $payload = [
                'query' => [
                    'filter' => $filter,
                    'sort' => [['fieldName' => 'createdDate', 'order' => 'ASC']],
                    'cursorPaging' => ['limit' => 100] + ($cursor ? ['cursor' => $cursor] : []),
                ],
            ];
            $resp = Http::withHeaders([
                'Authorization' => $auth,
                'Content-Type'  => 'application/json',
            ])->post('https://www.wixapis.com/payments/refunds/v1/refunds/query', $payload);

            if (!$resp->ok()) { WixHelper::log('Export Orders', 'Refunds query failed: ' . $resp->body() . '.', 'warn'); break; }
            $data = $resp->json();
            $page = $data['refunds'] ?? [];
            $all  = array_merge($all, $page);
            $cursor = $data['pagingMetadata']['cursors']['next'] ?? null;
        } while ($cursor);
        return $all;
    }

    private function getRefundById(string $auth, string $refundId): ?array
    {
        if (!$refundId) return null;
        $resp = Http::withHeaders([
            'Authorization' => $auth,
            'Content-Type'  => 'application/json',
        ])->get("https://www.wixapis.com/payments/refunds/v1/refunds/{$refundId}");
        return $resp->ok() ? $resp->json() : null;
    }

    private function listInvoicesForOrders(string $auth, array $orderIds): array
    {
        $byOrder = [];
        $chunks = array_chunk($orderIds, 100);
        foreach ($chunks as $ids) {
            $resp = Http::withHeaders([
                'Authorization' => $auth,
                'Content-Type'  => 'application/json',
            ])->post('https://www.wixapis.com/ecom/v1/ep-invoices/list-by-ids', [
                'orderIds' => array_values($ids),
            ]);

            if (!$resp->ok()) {
                WixHelper::log('Export Orders', 'Invoices list-by-ids failed: ' . $resp->body() . '.', 'warn');
                continue;
            }

            $list = $resp->json('invoicesForOrder') ?? [];
            foreach ($list as $row) {
                $oid = $row['orderId'] ?? null;
                if ($oid) $byOrder[$oid] = $row['invoicesInfo'] ?? [];
            }
        }
        return $byOrder;
    }

    private function listOrderTagsIndex(string $auth): array
    {
        $resp = Http::withHeaders([
            'Authorization' => $auth,
            'Content-Type'  => 'application/json',
        ])->get('https://www.wixapis.com/tags/v1/tags', [
            'fqdn' => 'wix.ecom.v1.order',
        ]);

        if (!$resp->ok()) {
            WixHelper::log('Export Orders', 'Tags list failed: ' . $resp->body() . '.', 'warn');
            return [];
        }

        $index = [];
        foreach ($resp->json('tags') ?? [] as $t) {
            if (!empty($t['id'])) $index[$t['id']] = $t;
        }
        return $index;
    }

    // =========================================================
    // IMPORT HELPERS (idempotency, mapping, dedupe)
    // =========================================================

    private function getOrderByIdLight(string $auth, string $orderId): array
    {
        $resp = Http::withHeaders([
            'Authorization' => $auth,
            'Content-Type'  => 'application/json',
        ])->get("https://www.wixapis.com/ecom/v1/orders/{$orderId}");
        return $resp->ok() ? ($resp->json('order') ?? []) : [];
    }

    private function buildLineItemMap(array $srcLineItems, array $destLineItems): array
    {
        $map = [];
        $destBySku  = [];
        $destByName = [];
        foreach ($destLineItems as $d) {
            $sku  = $d['physicalProperties']['sku'] ?? null;
            $name = $d['productName']['original']  ?? null;
            if ($sku)  $destBySku[$sku][]  = $d;
            if ($name) $destByName[$name][] = $d;
        }
        foreach ($srcLineItems as $s) {
            $srcSku  = $s['physicalProperties']['sku'] ?? null;
            $srcName = $s['productName']['original']  ?? null;
            $dest = null;
            if ($srcSku && !empty($destBySku[$srcSku])) {
                $dest = array_shift($destBySku[$srcSku]);
            } elseif ($srcName && !empty($destByName[$srcName])) {
                $dest = array_shift($destByName[$srcName]);
            }
            if ($dest && !empty($dest['id'])) {
                $map[$s['id'] ?? ($srcSku ?: $srcName)] = $dest['id'];
            }
        }
        return $map;
    }

    private function resolveOrderTagsForCreate(string $auth, array $exportTags, array $exportTagsIndex): array
    {
        $fqdn = 'wix.ecom.v1.order';

        $destIndex = $this->listOrderTagsIndex($auth);
        $nameToId  = [];
        foreach ($destIndex as $id => $meta) {
            if (!empty($meta['name'])) $nameToId[$meta['name']] = $id;
        }

        $resolve = function(array $group) use ($exportTagsIndex, &$nameToId, $auth, $fqdn) {
            $out = [];
            $ids = $group['tagIds'] ?? [];
            $names = $group['tagNames'] ?? [];

            if (!$names && $ids) {
                foreach ($ids as $tid) {
                    if (!empty($exportTagsIndex[$tid]['name'])) $names[] = $exportTagsIndex[$tid]['name'];
                }
            }

            foreach (array_unique(array_filter($names)) as $name) {
                if (!isset($nameToId[$name])) {
                    $created = $this->createTag($auth, $name, $fqdn);
                    if (!empty($created['id'])) {
                        $nameToId[$name] = $created['id'];
                        WixHelper::log('Import Orders', "Created tag '{$name}' (id={$created['id']}).", 'info');
                    }
                }
                if (isset($nameToId[$name])) $out[] = $nameToId[$name];
            }

            if (!$out && $ids) $out = $ids;

            return ['tagIds' => array_values(array_unique($out))];
        };

        return [
            'privateTags' => $resolve($exportTags['privateTags'] ?? []),
            'tags'        => $resolve($exportTags['tags'] ?? []),
        ];
    }

    private function createTag(string $auth, string $name, string $fqdn): array
    {
        $resp = Http::withHeaders([
            'Authorization' => $auth,
            'Content-Type'  => 'application/json',
        ])->post('https://www.wixapis.com/tags/v1/tags', [
            'tag' => ['name' => $name, 'fqdn' => $fqdn],
        ]);
        return $resp->ok() ? ($resp->json('tag') ?? $resp->json() ?? []) : [];
    }

    private function listPaymentsForOrder(string $auth, string $orderId): array
    {
        $resp = Http::withHeaders([
            'Authorization' => $auth,
            'Content-Type'  => 'application/json',
        ])->get("https://www.wixapis.com/ecom/v1/payments/orders/{$orderId}");
        return $resp->ok() ? ($resp->json('orderTransactions') ?? []) : [];
    }

    private function listFulfillmentsForOrder(string $auth, string $orderId): array
    {
        $resp = Http::withHeaders([
            'Authorization' => $auth,
            'Content-Type'  => 'application/json',
        ])->get("https://www.wixapis.com/ecom/v1/fulfillments/orders/{$orderId}");
        return $resp->ok() ? ($resp->json('orderWithFulfillments') ?? []) : [];
    }

    // --- Normalize any response into a flat payments[] array ---
    private function normalizePaymentsArray($data): array
    {
        if (!is_array($data)) return [];
        if (isset($data['orderTransactions']) && is_array($data['orderTransactions'])) {
            $ot = $data['orderTransactions'];
            if (isset($ot['payments']) && is_array($ot['payments'])) {
                return $ot['payments'];
            }
        }
        if (isset($data['payments']) && is_array($data['payments'])) {
            return $data['payments'];
        }
        $allNumeric = true;
        foreach (array_keys($data) as $k) {
            if (!is_int($k)) { $allNumeric = false; break; }
        }
        if ($allNumeric) {
            foreach ($data as $v) {
                if (!is_array($v)) return [];
            }
            return $data;
        }
        return [];
    }

    // --- Shape an exported payment for add-payment payload ---
    private function shapePaymentForAddPayment(array $p): array
    {
        $out = [
            'amount'         => ['amount' => (string)($p['amount']['amount'] ?? $p['amount'] ?? '0.00')],
            'refundDisabled' => (bool)($p['refundDisabled'] ?? false),
        ];
        if (!empty($p['createdDate'])) $out['createdDate'] = $p['createdDate'];
        if (!empty($p['status']))      $out['status']      = $p['status'];

        if (!empty($p['regularPaymentDetails']) && is_array($p['regularPaymentDetails'])) {
            $r = $p['regularPaymentDetails'];
            $keep = [
                'paymentOrderId', 'gatewayTransactionId', 'paymentMethod',
                'providerTransactionId', 'offlinePayment', 'status',
                'savedPaymentMethod', 'paymentProvider', 'chargebacks'
            ];
            $out['regularPaymentDetails'] = [];
            foreach ($keep as $k) {
                if (array_key_exists($k, $r)) $out['regularPaymentDetails'][$k] = $r[$k];
            }
        }
        if (!empty($p['giftcardPaymentDetails']) && is_array($p['giftcardPaymentDetails'])) {
            $g = $p['giftcardPaymentDetails'];
            $keep = ['giftCardPaymentId', 'appId'];
            $out['giftcardPaymentDetails'] = [];
            foreach ($keep as $k) {
                if (array_key_exists($k, $g)) $out['giftcardPaymentDetails'][$k] = $g[$k];
            }
        }
        return $out;
    }

    // --- Signature for dedupe
    private function paymentSignature($p): ?string
    {
        if (!is_array($p)) return null;

        $prov = $p['regularPaymentDetails']['providerTransactionId'] ?? null;
        $gate = $p['regularPaymentDetails']['gatewayTransactionId']  ?? null;
        if ($prov) return 'prov:' . $prov;
        if ($gate) return 'gate:' . $gate;

        $rcpt = $p['wixReceipt']['receiptId'] ?? null;
        if ($rcpt) return 'rcpt:' . $rcpt;

        $amt = $p['amount']['amount'] ?? null;
        $cd  = $p['createdDate'] ?? null;
        if ($amt && $cd) return 'amtcd:' . $amt . '|' . substr($cd, 0, 19);
        if ($amt) return 'amt:' . $amt;

        return null;
    }

    private function filterNewPayments(array $existingWrapper, array $exported): array
    {
        $existing = $this->normalizePaymentsArray($existingWrapper);

        $existsSig = [];
        foreach ($existing as $p) {
            $sig = $this->paymentSignature($p);
            if ($sig) $existsSig[$sig] = true;
        }

        $new = [];
        foreach ($exported as $p) {
            if (!is_array($p)) continue;
            $sig = $this->paymentSignature($p);
            if ($sig && isset($existsSig[$sig])) continue;
            $new[] = $p;
        }

        return $new;
    }

    private function computeFulfillmentDeltas(array $exportFulfillments, array $destFulfillments, array $lineItemMap, array $destLineItems): array
    {
        $fulfilledQty = [];
        foreach (($destFulfillments['fulfillments'] ?? []) as $f) {
            foreach ($f['lineItems'] ?? [] as $li) {
                $destId = $li['id'] ?? null;
                if ($destId) {
                    $fulfilledQty[$destId] = ($fulfilledQty[$destId] ?? 0) + (int)($li['quantity'] ?? 0);
                }
            }
        }

        $destQtyById = [];
        foreach ($destLineItems as $d) {
            if (!empty($d['id'])) {
                $destQtyById[$d['id']] = (int)($d['quantity'] ?? 1);
            }
        }

        $payloads = [];

        foreach ($exportFulfillments as $f) {
            $lines = [];
            foreach ($f['lineItems'] ?? [] as $li) {
                $srcKey = $li['id'] ?? null;
                $destId = $lineItemMap[$srcKey] ?? null;
                if (!$destId) continue;

                $already = $fulfilledQty[$destId] ?? 0;
                $want    = (int)($li['quantity'] ?? 0);
                $limit   = max(0, ($destQtyById[$destId] ?? 0) - $already);
                $q       = min($want, $limit);
                if ($q > 0) {
                    $lines[] = ['id' => $destId, 'quantity' => $q];
                    $fulfilledQty[$destId] = $already + $q;
                }
            }
            if ($lines) {
                $payloads[] = [
                    'lineItems'    => $lines,
                    'trackingInfo' => $f['trackingInfo'] ?? null,
                    'status'       => $f['status'] ?? 'Fulfilled',
                    'completed'    => $f['completed'] ?? true,
                ];
            }
        }
        return $payloads;
    }

    private function performRefundsIdempotent(
        string $auth,
        string $orderId,
        array $exportRefunds,
        array $destPaymentsWrapper,
        array $lineItemMap
    ): ?string {
        if (!$exportRefunds) return null;

        $destPayments = $this->normalizePaymentsArray($destPaymentsWrapper);

        // Remaining refundable per payment
        $byId = [];
        foreach ($destPayments as $p) {
            if (!is_array($p) || empty($p['id'])) continue;
            $paid = (float)($p['amount']['amount'] ?? 0);
            $refunded = 0.0;
            foreach (($p['refunds'] ?? []) as $rf) {
                $refunded += (float)($rf['amount']['amount'] ?? 0);
            }
            $byId[$p['id']] = max(0.0, $paid - $refunded);
        }

        // Index by provider/gateway ids
        $destIndexByProv = [];
        $destIndexByGate = [];
        foreach ($destPayments as $p) {
            if (!is_array($p) || empty($p['id'])) continue;
            $prov = $p['regularPaymentDetails']['providerTransactionId'] ?? null;
            $gate = $p['regularPaymentDetails']['gatewayTransactionId']  ?? null;
            if ($prov) $destIndexByProv[$prov] = $p['id'];
            if ($gate) $destIndexByGate[$gate] = $p['id'];
        }

        $refundByPayment = [];

        foreach ($exportRefunds as $rf) {
            $prov = $rf['providerTransactionId'] ?? ($rf['payment']['providerTransactionId'] ?? null);
            $gate = $rf['gatewayTransactionId']  ?? ($rf['payment']['gatewayTransactionId']  ?? null);

            $destPaymentId = null;
            if ($prov && isset($destIndexByProv[$prov])) $destPaymentId = $destIndexByProv[$prov];
            elseif ($gate && isset($destIndexByGate[$gate])) $destPaymentId = $destIndexByGate[$gate];

            $amount = (float)($rf['amount']['amount'] ?? $rf['amount'] ?? 0);
            if ($amount <= 0) continue;

            if (!$destPaymentId) {
                foreach ($byId as $pid => $left) {
                    if ($left > 0.0) { $destPaymentId = $pid; break; }
                }
            }
            if (!$destPaymentId) continue;

            $left = $byId[$destPaymentId] ?? 0.0;
            $amt  = min($amount, $left);
            if ($amt <= 0.0) continue;

            $refundByPayment[$destPaymentId][] = [
                'paymentId'      => $destPaymentId,
                'amount'         => ['amount' => (string)$amt],
                'externalRefund' => true,
            ];

            $byId[$destPaymentId] = max(0.0, $left - $amt);
        }

        if (!$refundByPayment) return null;

        foreach ($refundByPayment as $pid => $rows) {
            $resp = Http::withHeaders([
                'Authorization' => $auth,
                'Content-Type'  => 'application/json',
            ])->post('https://www.wixapis.com/ecom/v1/order-billing/refund-payments', [
                'orderId'        => $orderId,
                'paymentRefunds' => array_values($rows),
                'sideEffects'    => [
                    'notifications' => ['sendCustomerEmail' => false],
                ],
            ]);

            if (!$resp->ok()) {
                return 'refund-payments failed: ' . $resp->body();
            }
        }
        return null;
    }

    private function collectRefundsFromPayments(array $payments): array
    {
        $outs = [];
        foreach ($payments as $p) {
            foreach (($p['refunds'] ?? []) as $rf) {
                $outs[] = [
                    'amount' => $rf['amount']['amount'] ?? $rf['amount'] ?? null,
                    'providerTransactionId' => $p['regularPaymentDetails']['providerTransactionId'] ?? null,
                    'gatewayTransactionId'  => $p['regularPaymentDetails']['gatewayTransactionId']  ?? null,
                ];
            }
        }
        return $outs;
    }

    private function redactLarge(array $order, int $limit = 2000): array
    {
        $encoded = json_encode($order);
        if (strlen($encoded) <= $limit) return $order;
        return ['_truncated_' => true];
    }

    // =========================================================
    // DB utility: safe create to avoid duplicate key crash
    // =========================================================
    private function createRowSafely(
        int $userId,
        string $fromStoreId,
        ?string $toStoreId,
        ?string $sourceOrderId,
        ?string $orderNumber,
        ?string $destOrderId,
        string $status,
        ?string $error
    ): WixOrderMigration {
        try {
            return WixOrderMigration::create([
                'user_id'               => $userId,
                'from_store_id'         => $fromStoreId,
                'to_store_id'           => $toStoreId,
                'source_order_id'       => $sourceOrderId,
                'order_number'          => $orderNumber,
                'destination_order_id'  => $destOrderId,
                'status'                => $status,
                'error_message'         => $error,
            ]);
        } catch (QueryException $e) {
            $row = WixOrderMigration::where('user_id', $userId)
                ->where('from_store_id', $fromStoreId)
                ->when($toStoreId !== null, fn($q) => $q->where('to_store_id', $toStoreId))
                ->where('source_order_id', $sourceOrderId)
                ->first();

            if ($row) {
                $row->update([
                    'order_number'          => $orderNumber ?? $row->order_number,
                    'destination_order_id'  => $destOrderId ?? $row->destination_order_id,
                    'status'                => $status,
                    'error_message'         => $error,
                ]);
                return $row;
            }
            throw $e;
        }
    }

    // =========================================================
    // Small helpers for contact mapping & email extraction
    // =========================================================

    private function extractBuyerEmail(array $order): ?string
    {
        $candidates = [
            $order['buyerInfo']['email'] ?? null,
            $order['billingInfo']['address']['email'] ?? null,
            $order['billingInfo']['email'] ?? null,
            $order['customerInfo']['email'] ?? null,
        ];
        foreach ($candidates as $em) {
            if (is_string($em) && trim($em) !== '') return strtolower(trim($em));
        }
        return null;
    }

    private function findDestContactId(int $userId, string $fromStoreId, string $toStoreId, ?string $email): ?string
    {
        if (!$email) return null;
        $row = WixContactMigration::where('user_id', $userId)
            ->where('from_store_id', $fromStoreId)
            ->where('to_store_id', $toStoreId)
            ->whereRaw('LOWER(contact_email) = ?', [strtolower($email)])
            ->whereIn('status', ['success','imported','done'])
            ->orderByDesc('id')
            ->first();

        $id = $row?->destination_contact_id;
        return is_string($id) && $id !== '' ? $id : null;
    }
}
