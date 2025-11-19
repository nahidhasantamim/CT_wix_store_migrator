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
use App\Models\WixContactMigration;
use Illuminate\Support\Arr;

class WixOrderController extends Controller
{
    // ========================================================= Automatic Migrator =========================================================
    public function migrateAuto(Request $request)
    {
        $request->validate([
            'from_store' => 'required|string',
            'to_store'   => 'required|string|different:from_store',
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

        // --- Source refs for enrichment
        $tagsIndexSrc   = $this->listOrderTagsIndex($fromAuth);          // [tagId => meta]
        $orderSettings  = $this->getOrderSettings($fromAuth);            // for optional copy
        $orderIds       = $this->getWixOrderIds($fromAuth);
        if (isset($orderIds['error'])) {
            WixHelper::log('Auto Order Migration', "Order IDs fetch error: " . ($orderIds['raw'] ?? $orderIds['error']) . '.', 'error');
            return back()->with('error', 'Failed to fetch source orders.');
        }

        // Optional: apply source order settings to destination
        if (is_array($orderSettings)) {
            $this->updateOrderSettings($toAuth, $orderSettings);
        }

        // --- Batch invoices for context (not strictly required for create)
        $invoicesByOrder = $this->listInvoicesForOrders($fromAuth, $orderIds);

        // --- Prepare row claim/resolve helpers (same pattern as import())
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

            // Map tagIds → tagNames (so we can recreate tags later)
            $tagIds = $o['tags']['privateTags']['tagIds'] ?? [];
            if ($tagIds) {
                $o['tags']['privateTags']['tagNames'] = array_values(array_filter(array_map(
                    fn($id) => $tagsIndexSrc[$id]['name'] ?? null, $tagIds
                )));
            }

            // Refundability (optional context)
            $ref = $this->getOrderRefundability($fromAuth, $oid);
            if ($ref !== null) $o['refundability'] = $ref;

            // Aggregate refunds via transactions + refunds API (like export())
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

            // Stage append-only pending row (ignore duplicates)
            try {
                WixOrderMigration::create([
                    'user_id'               => $userId,
                    'from_store_id'         => $fromId,
                    'to_store_id'           => $toId, // we know destination up front
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

        // ---- Migrate to destination
        $imported = 0; $failed = 0; $processed = 0; $total = count($fullOrders);

        foreach ($fullOrders as $srcOrder) {
            $processed++;

            $sourceOrderId = $srcOrder['id'] ?? null;
            $orderNumber   = $srcOrder['number'] ?? null;

            // Prepare order payload (same as import()) — but DO NOT strip id/number here (we'll try preserve)
            $order = $srcOrder;

            $exportPaymentsRaw  = $srcOrder['transactions']['payments'] ?? [];
            $exportPaymentsFlat = $this->normalizePaymentsArray(['payments' => $exportPaymentsRaw]);
            $exportPayments     = array_map(fn($p) => $this->shapePaymentForAddPayment($p), $exportPaymentsFlat);

            $exportFulfillments = $order['fulfillments']['fulfillments'] ?? [];
            $exportRefunds      = $refundsByOrder[$sourceOrderId] ?? ($this->collectRefundsFromPayments($exportPaymentsRaw) ?: []);

            // Resolve/export tags into destination IDs
            $order['tags'] = $this->resolveOrderTagsForCreate($toAuth, $order['tags'] ?? [], $tagsIndexSrc);

            // Strip system / incompatible fields (but keep id/number for first attempt)
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
            $buyerEmail = $this->extractBuyerEmail($srcOrder);
            $destContactId = $this->findDestContactId($userId, $fromId, $toId, $buyerEmail);
            if ($destContactId) {
                $order['buyerInfo'] = $order['buyerInfo'] ?? [];
                $order['buyerInfo']['contactId'] = $destContactId;
            }

            // Create order on destination with best-effort ID/number preservation
            $createRes = $this->createOrderInWixPreserveIdNumber(
                $toAuth,
                $order,
                $srcOrder['id']     ?? null,   // try same ID
                $srcOrder['number'] ?? null    // try same number
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
            WixHelper::log('Auto Order Migration', "Created order {$orderNumber} → {$destOrderId}.", 'success');

            // Post-create enrichment (payments, fulfillments, refunds)
            $destOrderData       = $this->getOrderByIdLight($toAuth, $destOrderId);
            $destLineItems       = $destOrderData['lineItems'] ?? [];
            $lineItemMap         = $this->buildLineItemMap($srcOrder['lineItems'] ?? [], $destLineItems);

            // Payments delta
            $existingPayments = $this->listPaymentsForOrder($toAuth, $destOrderId);
            $newPayments      = $this->filterNewPayments($existingPayments, $exportPayments);
            if ($newPayments) {
                $addRes = $this->addPaymentsToOrderInWix(
                        $toAuth,
                        $destOrderId,
                        $newPayments,
                        strtoupper($srcOrder['paymentStatus'] ?? 'UNPAID')
                    );

                if (!$addRes['success']) {
                    WixHelper::log('Auto Order Migration', "Add payments failed #{$orderNumber}: " . $addRes['error'] . '.', 'error');
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
                    WixHelper::log('Auto Order Migration', "Fulfillment failed #{$orderNumber}: " . $fRes['error'] . '.', 'error');
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

        // Some imported (maybe with failures)
        if ($imported > 0) {
            WixHelper::log('Auto Order Migration', "Done. {$summary}", $failed ? 'warn' : 'success');

            // Always show a visible banner. Use success (so it renders) and, if supported,
        // also attach a warning to highlight partial failures.
            $resp = back()->with('success', "Auto migration completed. {$summary}");
            if ($failed > 0) {
                $resp = $resp->with('warning', 'Some orders failed to migrate. Check logs for details.');
            }
            return $resp;
        }

        // None imported, some failed
        if ($failed > 0) {
            WixHelper::log('Auto Order Migration', "Done. {$summary}", 'error');
            return back()->with('error', "No orders imported. {$summary}");
        }

        // Nothing to do
        WixHelper::log('Auto Order Migration', 'Done. Nothing to import.', 'info');
        return back()->with('success', 'Nothing to import.');

    }


    // ========================================================= Manual Migrator =========================================================
    // =========================================================
    // EXPORT: Orders + Drafts + Refundability + Refunds + Invoices + Tags
    // (append-only pending rows; do NOT overwrite)
    // =========================================================
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

        // ---- NEW: optional controls ----
        // limit: integer > 0 (caps total exported orders)
        // created_from / created_to: 'YYYY-MM-DD' or ISO8601 (UTC or with offset)
        $max               = $request->integer('limit') ?: null;
        $createdFrom       = $request->input('created_from'); // e.g. 2025-01-01 or 2025-01-01T00:00:00Z
        $createdTo         = $request->input('created_to');   // e.g. 2025-01-31 or 2025-01-31T23:59:59Z
        $startOrderNumber  = $request->input('start_order_number');


        // 0) Tags index (Orders FQDN)
        $tagsIndex = $this->listOrderTagsIndex($auth); // [tagId => ['name'=>..., ...]]

        // 1) Order IDs (with NEW filters)
        $orderIds = $this->getWixOrderIds($auth, $max, $createdFrom, $createdTo, $startOrderNumber);
        WixHelper::log('Export Orders', "Fetching orders starting after: " . ($startOrderNumber ?: 'beginning') . ".", 'debug');
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
            if ($refundability !== null) {
                $order['refundability'] = $refundability;
            }

            // Map tag IDs -> names for convenience
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
            if ($orderRefunds) {
                $refundsByOrder[$orderId] = $orderRefunds;
            }

            // ---- Append-only pending row (to_store_id = null). Ignore duplicates. ----
            try {
                WixOrderMigration::create([
                    'user_id'                => $userId,
                    'from_store_id'          => $fromStoreId,
                    'to_store_id'            => null, // export phase
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

        // Draft Orders (kept as-is; harmless if unused)
        $draftFilter = $request->input('draft_filter', []);
        $draftOrders = $this->queryDraftOrders($auth, $draftFilter);

        // Order settings
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

    // =========================================================
    // IMPORT: Idempotent (DB follows claim/resolve pattern)
    // Reads transactions directly from orders_json and adds only missing ones
    // =========================================================
    public function import(Request $request, WixStore $store)
    {
        $userId    = Auth::id() ?: 1;
        $toStoreId = $store->instance_id;

        WixHelper::log('Import Orders', "Start: {$store->store_name} ({$toStoreId}).", 'info');

        // // 1️⃣ Form-level safety check
        // $fromStoreId = $request->input('from_store_id') ?? ($request->input('from_store') ?? null);
        // if ($fromStoreId && $fromStoreId === $toStoreId) {
        //     WixHelper::log('Import Orders', "Cancelled: from_store_id and to_store_id are identical ({$fromStoreId}).", 'warn');
        //     return back()->with('error', 'Import cancelled — source and destination stores cannot be the same.');
        // }

        // // 2️⃣ Continue existing access token and file validation...
        // $accessToken = WixHelper::getAccessToken($toStoreId);
        // $json = file_get_contents($request->file('orders_json')->getRealPath());
        // $data = json_decode($json, true);

        // // 3️⃣ JSON-level safety check
        // $fromStoreId = $data['from_store_id'] ?? $fromStoreId ?? null;
        // if ($fromStoreId && $fromStoreId === $toStoreId) {
        //     WixHelper::log('Import Orders', "Import cancelled: from_store_id and to_store_id are identical ({$fromStoreId}) [from JSON].", 'warn');
        //     return back()->with('error', 'Import cancelled — source and destination stores cannot be the same.');
        // }

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

        // Optional: apply order settings
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

        // --- Dynamically align destination store's nextOrderNumber before import ---
        $minOrderNumber = collect($fullOrders ?? $orders ?? [])->pluck('number')->filter()->min();

        if ($minOrderNumber) {
            $desiredNext = max(0, $minOrderNumber - 1); // ensure non-negative

            $destSettings = $this->getOrderSettings($toAuth ?? $auth);
            $currentNext  = $destSettings['nextOrderNumber'] ?? null;

            if (!$currentNext || $currentNext > $desiredNext) {
                $this->updateOrderSettings($toAuth ?? $auth, [
                    'nextOrderNumber' => $desiredNext,
                ]);
                WixHelper::log(
                    'Order Migration',
                    "Adjusted destination nextOrderNumber to {$desiredNext} (for {$minOrderNumber} alignment).",
                    'info'
                );
            }
        }


        $refundsByOrder = $data['refundsByOrder'] ?? [];

        // --- Row claiming & dedupe (pattern like Discount Rules) ---
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

        $imported = 0; $failed = 0; $processed = 0; $total = count($orders);

        foreach ($orders as $srcOrder) {
            $processed++;

            $sourceOrderId = $srcOrder['id'] ?? null;
            $orderNumber   = $srcOrder['number'] ?? null;

            // Prepare payload (tags, clean fields) — keep id/number for first attempt
            $order = $srcOrder;

            $exportPaymentsRaw = $srcOrder['transactions']['payments'] ?? [];
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

            if (!empty($srcOrder['purchasedDate'])) {
                $norm = $this->normalizePurchasedDate($srcOrder['purchasedDate']);
                if ($norm) {
                    $order['purchasedDate'] = $norm;
                }
            } elseif (!empty($srcOrder['createdDate'])) {
                $norm = $this->normalizePurchasedDate($srcOrder['createdDate']);
                if ($norm) {
                    $order['purchasedDate'] = $norm;
                }
            }

            // Attach migrated contactId by matching buyer email
            $buyerEmail = $this->extractBuyerEmail($srcOrder);
            $destContactId = $this->findDestContactId($userId, $fromStoreId, $toStoreId, $buyerEmail);
            if ($destContactId) {
                $order['buyerInfo'] = $order['buyerInfo'] ?? [];
                $order['buyerInfo']['contactId'] = $destContactId;
            }

            WixHelper::log('Import Orders', 'Payload including purchasedDate', 'debug', [
                'source_order_number' => $orderNumber,
                'purchasedDate'       => $order['purchasedDate'] ?? null,
                'payloadSnippet'      => array_slice($order, 0, 10)  // or something limited
            ]);
            
            // ---- Create Order in Wix (try preserve id/number, then fallback) ----
            $createRes = $this->createOrderInWixPreserveIdNumber(
                $auth,
                $order,
                $srcOrder['id']     ?? null,
                $srcOrder['number'] ?? null
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
                    // As a last resort, create a new row safely without raising duplicates
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
            WixHelper::log('Import Orders', "Created order {$orderNumber} → {$destOrderId}.", 'success');

            // Refresh destination order items
            $destOrderData = $this->getOrderByIdLight($auth, $destOrderId);
            $destLineItems = $destOrderData['lineItems'] ?? [];
            $lineItemMap   = $this->buildLineItemMap($srcOrder['lineItems'] ?? [], $destLineItems);

            // Payments (idempotent) - NOW reading from orders_json (already shaped)
            $existingPayments = $this->listPaymentsForOrder($auth, $destOrderId);
            $newPayments      = $this->filterNewPayments($existingPayments, $exportPayments);
            if (!empty($newPayments)) {
                $addRes = $this->addPaymentsToOrderInWix(
                        $auth,
                        $destOrderId,
                        $newPayments,
                        strtoupper($srcOrder['paymentStatus'] ?? 'UNPAID')
                    );
                if (!$addRes['success']) {
                    WixHelper::log('Import Orders', "Add payments failed for {$orderNumber}: " . $addRes['error'] . '.', 'error');
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
                    WixHelper::log('Import Orders', "Fulfillment create failed for {$orderNumber}: " . $fRes['error'] . '.', 'error');
                }
            }

            // ---- Claim/resolve row & mark success ----
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
                // As a last resort, create a new row safely (handles dupes)
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

    private function getWixOrderIds(
        string $auth,
        ?int $max = null,
        ?string $createdFrom = null,
        ?string $createdTo = null,
        ?string $startOrderNumber = null
    ) {
        // =========================================================
        // Smart Order Fetcher (supports date filters + start number)
        // =========================================================
        $fmt = function (?string $d, bool $isEnd = false): ?string {
            if (!$d) return null;
            $d = trim(preg_replace('/[T\s].*$/', '', $d));
            return $d . ($isEnd ? 'T23:59:59.999' : 'T00:00:00.000');
        };

        $gte = $fmt($createdFrom, false);
        $lte = $fmt($createdTo, true);

        $makeFilter = function (?string $gte, ?string $lte, ?string $startOrderNumber): array {
            $and = [];

            // --- Date filters ---
            if ($gte) $and[] = ['createdDate' => ['$gte' => $gte]];
            if ($lte) $and[] = ['createdDate' => ['$lte' => $lte]];

            // --- Skip up to a given order number ---
            if ($startOrderNumber) {
                $and[] = ['number' => ['$gt' => $startOrderNumber]];
            }

            // --- Exclude "INITIALIZED" ---
            $and[] = ['status' => ['$nin' => ['INITIALIZED']]];

            return $and ? ['$and' => $and] : [];
        };

        $orderIds = [];
        $cursor   = null;
        $lastErr  = null;

        do {
            // Build payload
            $payload = [
                'search' => [
                    'sort' => [
                        ['fieldName' => 'number', 'order' => 'ASC']
                    ],
                    'cursorPaging' => [
                        'limit' => 100
                    ],
                    'filter' => $makeFilter($gte, $lte, $startOrderNumber),
                ],
            ];

            if ($cursor) {
                $payload['search']['cursorPaging']['cursor'] = $cursor;
            }

            $resp = Http::withHeaders([
                'Authorization' => $auth,
                'Content-Type'  => 'application/json',
            ])->post('https://www.wixapis.com/ecom/v1/orders/search', $payload);

            if (!$resp->ok()) {
                $lastErr = $resp->body();
                WixHelper::log('Export Orders', "Error fetching orders: {$lastErr}", 'error');
                break;
            }

            $data = $resp->json();
            $batch = $data['orders'] ?? [];
            $batchCount = count($batch);

            foreach ($batch as $row) {
                if (!empty($row['id'])) {
                    $orderIds[] = $row['id'];
                    if ($max && count($orderIds) >= $max) {
                        WixHelper::log('Export Orders', "Reached limit of {$max} order IDs.", 'info');
                        break 2;
                    }
                }
            }

            WixHelper::log(
                'Export Orders',
                "Fetched {$batchCount} orders (total: " . count($orderIds) . ")",
                'debug'
            );

            $cursor = $data['metadata']['pagingMetadata']['cursors']['next'] ?? null;

        } while ($cursor && (!$max || count($orderIds) < $max));

        if (empty($orderIds)) {
            return ['error' => 'No orders found or failed to fetch beyond start_order_number.', 'raw' => $lastErr];
        }

        return $orderIds;
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

    private function updateOrderSettings(string $auth, array $settings): bool
    {
        // Skip empty or invalid patches
        if (empty(array_filter($settings, fn($v) => $v !== null && $v !== ''))) {
            WixHelper::log('Order Settings', 'Skipped updateOrderSettings: empty or null payload.', 'info');
            return true;
        }

        $resp = Http::withHeaders([
            'Authorization' => $auth,
            'Content-Type'  => 'application/json',
        ])->patch('https://www.wixapis.com/ecom/v1/orders-settings', [
            'ordersSettings' => $settings,
        ]);

        if ($resp->ok()) {
            WixHelper::log('Order Settings', 'Orders settings updated successfully.', 'success');
            return true;
        }

        WixHelper::log('Order Settings', 'Failed to update orders settings: ' . $resp->body(), 'error');
        return false;
    }

private function createOrderInWix(string $auth, array &$order): array
{
    try {
        // =============================================
        //  Duplicate Creation Guard
        // =============================================
        if (!empty($order['migration_result']) && $order['migration_result'] === 'success') {
            WixHelper::log('Order Create', "Skipping duplicate create for order {$order['number']}", 'warn');
            return ['success' => true, 'message' => 'Already created'];
        }

        // =============================================
        //  Normalize Payload Before Sending
        // =============================================

        // Remove invalid createdBy (empty or wrong format)
        if (isset($order['createdBy'])) {
            if (empty($order['createdBy']) || (is_array($order['createdBy']) && array_is_list($order['createdBy']))) {
                unset($order['createdBy']);
            }
        }

        // Ensure proper object-type fields
        foreach (['channelInfo', 'priceSummary', 'buyerInfo'] as $objKey) {
            if (isset($order[$objKey]) && is_array($order[$objKey]) && array_is_list($order[$objKey])) {
                unset($order[$objKey]);
            }
        }

        // Normalize lineItems
        if (isset($order['lineItems']) && is_array($order['lineItems'])) {
            $order['lineItems'] = array_values($order['lineItems']);
        }

        // Remove read-only fields
        $removeKeys = [
            'transactions', 'refundability', 'balanceSummary',
            'activities', 'archived', 'purchaseFlowId',
            'priceSummary.refundsSummary', 'priceSummary.balanceSummary'
        ];
        foreach ($removeKeys as $key) {
            Arr::forget($order, $key);
        }

        // =============================================
        // Log structure for debug
        // =============================================
        WixHelper::log('Order Debug', 'Final payload structure before POST', 'info', [
            'source_number'    => $order['number'] ?? 'N/A',
            'channel'          => $order['channelInfo']['type'] ?? 'N/A',
            'has_lineItems'    => isset($order['lineItems']) && count($order['lineItems']) > 0,
            'has_priceSummary' => isset($order['priceSummary']),
            'createdBy_type'   => isset($order['createdBy']) ? gettype($order['createdBy']) : 'none',
        ]);


        // =============================================
        // FIX: Normalize Shipping Structure
        // =============================================
        if (isset($order['shippingInfo']['cost'])) {

            $shippingCost = &$order['shippingInfo']['cost'];

            if (
                isset($shippingCost['discount']['amount']) &&
                (float)$shippingCost['discount']['amount'] > 0
            ) {
                unset($shippingCost['totalPriceBeforeTax']);
                unset($shippingCost['totalPriceAfterTax']);
                unset($shippingCost['taxDetails']);
                unset($shippingCost['taxInfo']);
            }

            foreach ($shippingCost as $key => $value) {
                if ($value === [] || $value === null || $value === '') {
                    unset($shippingCost[$key]);
                }
            }
        }

        // =============================================
        // FIX: Remove invalid empty taxBreakdown arrays
        // =============================================

        if (isset($order['taxInfo']['taxBreakdown']) && $order['taxInfo']['taxBreakdown'] === []) {
            unset($order['taxInfo']['taxBreakdown']);
        }

        if (isset($order['lineItems'])) {
            foreach ($order['lineItems'] as &$li) {
                if (isset($li['taxInfo']['taxBreakdown']) && $li['taxInfo']['taxBreakdown'] === []) {
                    unset($li['taxInfo']['taxBreakdown']);
                }
            }
        }

        if (isset($order['shippingInfo']['cost']['taxInfo']['taxBreakdown']) &&
            $order['shippingInfo']['cost']['taxInfo']['taxBreakdown'] === []) {
            unset($order['shippingInfo']['cost']['taxInfo']['taxBreakdown']);
        }

        // =============================================
        // NEW FIX: Remove geocode: []
        // =============================================
        Arr::forget($order, 'shippingInfo.logistics.shippingDestination.address.geocode');
        Arr::forget($order, 'recipientInfo.address.geocode');

        // =============================================
        // NEW FIX: Remove lineItems[].locations: []
        // =============================================
        if (isset($order['lineItems'])) {
            foreach ($order['lineItems'] as &$li) {
                if (isset($li['locations']) && $li['locations'] === []) {
                    unset($li['locations']);
                }
            }
        }

        // =============================================
        // NEW FIX: Remove appliedDiscounts: []
        // =============================================
        if (isset($order['appliedDiscounts']) && $order['appliedDiscounts'] === []) {
            unset($order['appliedDiscounts']);
        }

        // =============================================
        // NEW FIX: Remove additionalFees: []
        // =============================================
        if (isset($order['additionalFees']) && $order['additionalFees'] === []) {
            unset($order['additionalFees']);
        }

        // ======================================================================
        // 🔥🔥🔥 NEW PATCHES FOR FAILED ORDERS (DO NOT REMOVE ANYTHING ABOVE) 🔥🔥🔥
        // ======================================================================

        // =============================================
        // PATCH A: Remove only refundQuantity (read-only)
        // =============================================
        if (!empty($order['lineItems'])) {
            foreach ($order['lineItems'] as &$li) {
                if (isset($li['refundQuantity'])) {
                    unset($li['refundQuantity']);
                }
            }
        }

        // =============================================
        // PATCH B: Normalize all price.amount values
        // =============================================
        $priceKeys = [
            'price', 'priceBeforeDiscounts', 'priceBeforeDiscountsAndTax',
            'totalPriceBeforeTax', 'totalPriceAfterTax', 'lineItemPrice'
        ];

        if (!empty($order['lineItems'])) {
            foreach ($order['lineItems'] as &$li) {
                foreach ($priceKeys as $k) {
                    if (isset($li[$k]['amount'])) {
                        $li[$k]['amount'] = (string) floatval($li[$k]['amount']);
                    }
                }
                if (isset($li['totalDiscount']['amount'])) {
                    $li['totalDiscount']['amount'] = (string) floatval($li['totalDiscount']['amount']);
                }
            }
        }

        // Normalize shipping cost numeric values
        if (isset($order['shippingInfo']['cost'])) {
            foreach ($order['shippingInfo']['cost'] as $k => $v) {
                if (is_array($v) && isset($v['amount'])) {
                    $order['shippingInfo']['cost'][$k]['amount'] = (string) floatval($v['amount']);
                }
            }
        }

        // Normalize priceSummary numeric values
        if (isset($order['priceSummary'])) {
            foreach ($order['priceSummary'] as $k => $v) {
                if (is_array($v) && isset($v['amount'])) {
                    $order['priceSummary'][$k]['amount'] = (string) floatval($v['amount']);
                }
            }
        }

        // ======================================================================
        // END OF NEW PATCHES
        // ======================================================================


        // =============================================
        // Send order to Wix
        // =============================================
        $response = Http::withHeaders([
            'Authorization' => $auth,
            'Content-Type'  => 'application/json',
        ])->post('https://www.wixapis.com/ecom/v1/orders', [
            'order' => $order,
        ]);

        // =============================================
        // Handle response
        // =============================================
        $json = $response->json();

        if (!$response->ok() || empty($json['order'])) {
            WixHelper::log('Order Create', 'Wix createOrderInWix failed', 'error', [
                'response' => $json ?? $response->body(),
            ]);
            return [
                'success' => false,
                'message' => 'Wix createOrderInWix failed',
                'response' => $json ?? $response->body(),
            ];
        }

        // =============================================
        // Success — mark as migrated
        // =============================================
        $createdOrder = $json['order'];
        $order['migration_result'] = 'success';

        WixHelper::log('Order Create', 'Order created successfully', 'success', [
            'id'      => $createdOrder['id'] ?? null,
            'number'  => $createdOrder['number'] ?? 'auto',
            'channel' => $order['channelInfo']['type'] ?? null,
        ]);

        // =============================================
        // Fulfillments (if present)
        // =============================================
        if (!empty($order['fulfillments']['fulfillments'])) {
            foreach ($order['fulfillments']['fulfillments'] as $f) {
                $this->createFulfillmentInWix($auth, $createdOrder['id'], $f);
            }
        }

        return [
            'success' => true,
            'order'   => $createdOrder,
        ];

    } catch (\Throwable $e) {
        WixHelper::log('Order Create', 'Exception during order creation', 'error', [
            'message' => $e->getMessage(),
            'trace'   => $e->getTraceAsString(),
        ]);

        return [
            'success' => false,
            'message' => $e->getMessage(),
        ];
    }
}




    // --- Ensure RFC3339 with milliseconds and Z (UTC), e.g. 2025-01-31T23:59:59.123Z
    private function normalizePurchasedDate(?string $raw): ?string
    {
        if (!$raw || !is_string($raw)) return null;

        try {
            // Accept: "YYYY-MM-DD", any ISO string, or with offset
            $dt = \Carbon\Carbon::parse($raw)->utc();

            // PHP 'v' = milliseconds; force "Z" suffix
            return $dt->format('Y-m-d\TH:i:s.v\Z');
        } catch (\Throwable $e) {
            return null; // if parsing fails, fall back to Wix default
        }
    }

    // Wrapper to try preserving id/number, then fallback
    private function createOrderInWixPreserveIdNumber(string $auth, array $cleanOrder, ?string $srcId, ?string $srcNumber): array
    {
        // Extract original statuses
        $originalStatus        = strtoupper($cleanOrder['status'] ?? 'APPROVED');
        $originalPaymentStatus = strtoupper($cleanOrder['paymentStatus'] ?? 'UNPAID');

        // If order was fully or partially paid/refunded, neutralize payment status
        // to prevent Wix from automatically creating a "marked as Paid" activity
        // if (in_array($originalPaymentStatus, ['PAID', 'PARTIALLY_REFUNDED', 'REFUNDED'])) {
        //     $originalPaymentStatus = 'UNPAID';
        // }

        // Inject neutral defaults for migration mode
        $migrationDefaults = [
            'channelInfo'   => ['type' => 'OTHER_PLATFORM'],
            'status'        => $originalStatus,
            'paymentStatus' => $originalPaymentStatus,
        ];

        // Merge defaults without overriding explicit fields
        $tryBase = array_merge($migrationDefaults, $cleanOrder);

        if (!empty($cleanOrder['purchasedDate'])) {
            $tryBase['purchasedDate'] = $cleanOrder['purchasedDate'];
        }

        // if (!empty($cleanOrder['purchasedDate'])) {
        //     $order['status'] = 'APPROVED';
        //     $order['paymentStatus'] = 'PAID';
        // }

        // --- Attempt 1: preserve both ID and number
        $try1 = $tryBase;
        if ($srcId)     $try1['id']     = $srcId;
        if ($srcNumber) $try1['number'] = (int) $srcNumber;

        $res1 = $this->createOrderInWix($auth, $try1);
        if (!empty($res1['order']['id'])) {
            WixHelper::log('Order Create', "Created with preserved ID+number: {$srcNumber} [{$originalPaymentStatus}]", 'success');
            return $res1;
        }

        // --- Attempt 2: preserve only number
        $try2 = $tryBase;
        unset($try2['id']);
        if ($srcNumber) $try2['number'] = (int) $srcNumber;

        $res2 = $this->createOrderInWix($auth, $try2);
        if (!empty($res2['order']['id'])) {
            WixHelper::log('Order Create', "Created with preserved number: {$srcNumber} [{$originalPaymentStatus}]", 'success');
            return $res2;
        }

        // --- Attempt 3: fully fallback to Wix auto-number
        $try3 = $tryBase;
        unset($try3['id'], $try3['number']);

        WixHelper::log('Order Create', "Falling back to auto-generated number for src={$srcNumber} [{$originalPaymentStatus}]", 'warn');
        return $this->createOrderInWix($auth, $try3);
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

    if ($resp->ok()) {
        WixHelper::log('Order Fulfillment', "Fulfillment added for order {$orderId}", 'success');
        return ['success' => true];
    }

    WixHelper::log('Order Fulfillment', "Failed to add fulfillment for order {$orderId}", 'error', [
        'payload' => $payload,
        'response' => $resp->body(),
    ]);
    return ['success' => false, 'error' => $resp->body()];
}


    private function addPaymentsToOrderInWix(string $auth, string $orderId, array $transactions, string $originalPaymentStatus = 'UNPAID'): array
    {
        // Normalize original status for comparison
        $status = strtoupper(str_replace(' ', '_', $originalPaymentStatus));

        // === Map order payment status → Wix API payment status ===
        $statusMap = [
            'PAID'               => 'APPROVED',
            'UNPAID'             => 'PENDING',
            'REFUNDED'           => 'REFUNDED',
            'PARTIALLY_REFUNDED' => 'VOIDED',
            'PARTIALLY_PAID'     => 'AUTHORIZED',
            'AUTHORIZED'         => 'AUTHORIZED',
            'PENDING'            => 'PENDING',
            'DECLINED'           => 'DECLINED',
            'CANCELED'           => 'CANCELED',
            'PENDING_REFUND'     => 'PENDING_MERCHANT',
            'CHARGEBACK'         => 'VOIDED',
            'CHARGEBACK_REVERSED'=> 'VOIDED',
        ];

        // Default fallback for unmapped
        $mappedStatus = $statusMap[$status] ?? 'VOIDED';

        $payments = [];

        foreach ($transactions as $p) {

            // === CASE 1: PAID ===
            if ($status === 'PAID') {
                $entry = [
                    'amount'         => $p['amount'] ?? ['amount' => '0.00'],
                    'refundDisabled' => $p['refundDisabled'] ?? false,
                    'status'         => $p['status'] ?? $mappedStatus,
                ];
                if (!empty($p['createdDate']))            $entry['createdDate']            = $p['createdDate'];
                if (!empty($p['regularPaymentDetails']))  $entry['regularPaymentDetails']  = $p['regularPaymentDetails'];
                if (!empty($p['giftcardPaymentDetails'])) $entry['giftcardPaymentDetails'] = $p['giftcardPaymentDetails'];

                $payments[] = $entry;
                continue;
            }

            // === CASE 2: OTHER (including partially refunded, etc.) ===
            $neutral = [
                'amount'         => $p['amount'] ?? ['amount' => '0.00'],
                'status'         => $mappedStatus,
                'refundDisabled' => true,
                'createdDate'    => $p['createdDate'] ?? now()->toIso8601String(),
                'regularPaymentDetails' => [
                    'paymentMethod'   => $p['regularPaymentDetails']['paymentMethod'] ?? 'Imported',
                    'paymentProvider' => 'ExternalMigration',
                    'offlinePayment'  => true,
                    'status'          => $mappedStatus,
                    'providerTransactionId' => $p['regularPaymentDetails']['providerTransactionId'] ?? null,
                    'gatewayTransactionId'  => $p['regularPaymentDetails']['gatewayTransactionId']  ?? null,
                ],
            ];

            if (!empty($p['giftcardPaymentDetails'])) {
                $neutral['giftcardPaymentDetails'] = $p['giftcardPaymentDetails'];
            }

            $payments[] = $neutral;
        }

        // No payments to add
        if (empty($payments)) {
            WixHelper::log('Add Payments', "No payments to add for order {$orderId}.", 'info');
            return ['success' => true, 'skipped' => true];
        }

        // Log mapping
        WixHelper::log('Add Payments', "Mapped {$originalPaymentStatus} → {$mappedStatus} for order {$orderId}.", 'debug');

        // Call Wix API
        $resp = Http::withHeaders([
            'Authorization' => $auth,
            'Content-Type'  => 'application/json',
        ])->post("https://www.wixapis.com/ecom/v1/payments/orders/{$orderId}/add-payment", [
            'payments' => $payments,
        ]);

        if ($resp->ok()) {
            WixHelper::log('Add Payments', "Added payment(s) for order {$orderId} [{$originalPaymentStatus} → {$mappedStatus}].", 'success');
            return ['success' => true, 'response' => $resp->json()];
        }

        $error = $resp->body();
        WixHelper::log('Add Payments', "Failed to add payments for order {$orderId}: {$error}", 'error');
        return ['success' => false, 'error' => $error];
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
        if (!is_array($data)) {
            return [];
        }
        // Case A: wrapper orderTransactions → payments
        if (isset($data['orderTransactions']) && is_array($data['orderTransactions'])) {
            $ot = $data['orderTransactions'];
            if (isset($ot['payments']) && is_array($ot['payments'])) {
                return $ot['payments'];
            }
        }
        // Case B: wrapper directly contains 'payments'
        if (isset($data['payments']) && is_array($data['payments'])) {
            return $data['payments'];
        }
        // Case C: already a list of payments
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

    // --- UPDATED: accept mixed and guard inside; includes receipt fallback ---
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

    // --- Use signatures to only create missing payments ---
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
            // Duplicate? Fetch and update existing
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
            throw $e; // unexpected
        }
    }

    // =========================================================
    // NEW small helpers for contact mapping & email extraction
    // =========================================================

    // Pull buyer email from common shapes
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

    // Read contact mapping created during contacts migration
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
