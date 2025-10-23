<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

use App\Models\WixStore;
use App\Helpers\WixHelper;
use App\Models\WixGiftCardMigration;

class WixGiftCardController extends Controller
{
    // ========================================================= Automatic Migrator =========================================================
    public function migrateAuto(Request $request)
    {
        $request->validate([
            'from_store' => 'required|string',
            'to_store'   => 'required|string|different:from_store',
        ]);

        $userId = Auth::id() ?: 1;
        $fromId = (string) $request->input('from_store');
        $toId   = (string) $request->input('to_store');

        $fromStore = WixStore::where('instance_id', $fromId)->first();
        $toStore   = WixStore::where('instance_id', $toId)->first();

        $fromLabel = $fromStore?->store_name ?: $fromId;
        $toLabel   = $toStore?->store_name   ?: $toId;

        WixHelper::log('Auto Gift Cards Migration', "Start: {$fromLabel} → {$toLabel}", 'info');

        // --- Tokens
        $fromToken = WixHelper::getAccessToken($fromId);
        $toToken   = WixHelper::getAccessToken($toId);
        if (!$fromToken || !$toToken) {
            WixHelper::log('Auto Gift Cards Migration', 'Missing access token(s).', 'error');
            return back()->with('error', 'Could not get Wix access token(s).');
        }

        // --- Helpers (local)
        $ensureBearer = fn(string $t) => preg_match('/^Bearer\s+/i', $t) ? $t : ('Bearer '.$t);
        $toAuthHeader = $ensureBearer($toToken);

        $createdAtMillis = function (array $item): int {
            foreach (['createdDate','dateCreated','createdAt','creationDate','date_created'] as $k) {
                if (array_key_exists($k, $item)) {
                    $v = $item[$k];
                    if (is_numeric($v)) return (int)$v;
                    if (is_string($v)) { $ts = strtotime($v); if ($ts !== false) return $ts * 1000; }
                }
            }
            foreach ([['audit','createdDate'], ['audit','dateCreated'], ['metadata','createdDate'], ['metadata','dateCreated']] as $path) {
                $cur = $item;
                foreach ($path as $p) { if (!is_array($cur) || !array_key_exists($p, $cur)) { $cur=null; break; } $cur=$cur[$p]; }
                if ($cur !== null) {
                    if (is_numeric($cur)) return (int)$cur;
                    if (is_string($cur)) { $ts = strtotime($cur); if ($ts !== false) return $ts * 1000; }
                }
            }
            return PHP_INT_MAX;
        };

        $uuid4 = function (): string {
            $data = random_bytes(16);
            $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
            $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
            return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
        };

        $sanitizeNotificationInfo = function (array $ni): ?array {
            $out = [];
            if (!empty($ni['recipient']) && is_array($ni['recipient'])) {
                $r = [];
                if (!empty($ni['recipient']['email'])) $r['email'] = $ni['recipient']['email'];
                if (!empty($ni['recipient']['name']))  $r['name']  = $ni['recipient']['name'];
                if ($r) $out['recipient'] = $r;
            }
            if (!empty($ni['sender']) && is_array($ni['sender'])) {
                $s = [];
                if (!empty($ni['sender']['email'])) $s['email'] = $ni['sender']['email'];
                if (!empty($ni['sender']['name']))  $s['name']  = $ni['sender']['name'];
                if ($s) $out['sender'] = $s;
            }
            if (!$out) return null;
            if (!empty($ni['notificationDate']))     $out['notificationDate']     = $ni['notificationDate'];
            if (!empty($ni['personalizedMessage']))  $out['personalizedMessage']  = $ni['personalizedMessage'];
            return $out;
        };

        $getStoreCurrency = function (string $authHeader) {
            $resp = Http::withHeaders([
                'Authorization' => $authHeader,
                'Content-Type'  => 'application/json',
            ])->get('https://www.wixapis.com/stores/v2/settings');

            if (!$resp->ok()) {
                WixHelper::log('Auto Gift Cards Migration', 'Get store settings failed: status='.$resp->status().' body='.$resp->body(), 'error');
                return null;
            }
            $j = $resp->json();
            return $j['storeSettings']['currency'] ?? $j['currency'] ?? null;
        };

        $destCurrency = $getStoreCurrency($toAuthHeader) ?? 'USD';

        // --- 1) Fetch source gift cards (oldest-first)
        $resp = $this->getGiftCardsFromWix($fromToken);
        if (!isset($resp['giftCards']) || !is_array($resp['giftCards'])) {
            WixHelper::log('Auto Gift Cards Migration', 'Fetch error: '.json_encode($resp), 'error');
            return back()->with('error', 'Failed to fetch gift cards from source.');
        }
        $cards = $resp['giftCards'];
        usort($cards, fn($a, $b) => $createdAtMillis($a) <=> $createdAtMillis($b));
        WixHelper::log('Auto Gift Cards Migration', 'Fetched '.count($cards).' gift card(s).', 'info');

        // --- 2) Stage pending rows (target-aware)
        $staged = 0;
        foreach ($cards as $gc) {
            $srcId = $gc['id'] ?? null;
            if (!$srcId) continue;

            try {
                WixGiftCardMigration::create([
                    'user_id'                  => $userId,
                    'from_store_id'            => $fromId,
                    'to_store_id'              => $toId,
                    'source_gift_card_id'      => $srcId,
                    'source_code_suffix'       => $gc['code'] ?? null, // obfuscated, for reference
                    'initial_value_amount'     => $gc['initialValue']['amount'] ?? null,
                    'currency'                 => $gc['currency'] ?? null,
                    'destination_gift_card_id' => null,
                    'status'                   => 'pending',
                    'error_message'            => null,
                ]);
                $staged++;
            } catch (\Illuminate\Database\QueryException $e) {
                // duplicate pending row is OK in auto mode
            }
        }
        WixHelper::log('Auto Gift Cards Migration', "Staged {$staged} pending row(s).", 'success');

        // --- 3) Claim/resolve helpers
        $claimPendingRow = function (?string $sourceId) use ($userId, $fromId) {
            return DB::transaction(function () use ($userId, $fromId, $sourceId) {
                $row = null;

                if ($sourceId) {
                    $row = WixGiftCardMigration::where('user_id', $userId)
                        ->where('from_store_id', $fromId)
                        ->where('status', 'pending')
                        ->where(function ($q) use ($sourceId) {
                            $q->where('source_gift_card_id', $sourceId)
                            ->orWhereNull('source_gift_card_id');
                        })
                        ->orderByRaw("CASE WHEN source_gift_card_id = ? THEN 0 ELSE 1 END", [$sourceId])
                        ->orderBy('created_at', 'asc')
                        ->lockForUpdate()
                        ->first();
                }
                if (!$row) {
                    $row = WixGiftCardMigration::where('user_id', $userId)
                        ->where('from_store_id', $fromId)
                        ->where('status', 'pending')
                        ->orderBy('created_at', 'asc')
                        ->lockForUpdate()
                        ->first();
                }
                return $row;
            }, 3);
        };

        $resolveTargetRow = function ($claimed, ?string $sourceId) use ($userId, $fromId, $toId) {
            if ($sourceId) {
                $existing = WixGiftCardMigration::where('user_id', $userId)
                    ->where('from_store_id', $fromId)
                    ->where('to_store_id', $toId)
                    ->where('source_gift_card_id', $sourceId)
                    ->orderBy('created_at', 'asc')
                    ->first();

                if ($existing) {
                    if ($claimed && $claimed->id !== $existing->id && $claimed->status === 'pending') {
                        $claimed->update([
                            'status'        => 'skipped',
                            'error_message' => 'Merged into existing migration row id '.$existing->id,
                        ]);
                    }
                    return $existing;
                }
            }
            return $claimed;
        };

        // --- 4) Create on destination
        $imported = 0; $failed = 0; $processed = 0; $total = count($cards);

        foreach ($cards as $gc) {
            $processed++;

            $sourceId = $gc['id'] ?? null;
            if (!$sourceId) continue;

            // If already migrated successfully for this target, skip
            $already = WixGiftCardMigration::where([
                'user_id'             => $userId,
                'from_store_id'       => $fromId,
                'to_store_id'         => $toId,
                'source_gift_card_id' => $sourceId,
                'status'              => 'success',
            ])->first();
            if ($already) {
                WixHelper::log('Auto Gift Cards Migration', "Skip (already imported): {$sourceId}", 'debug');
                continue;
            }

            // Build clean create payload (no full code available from source – let Wix generate)
            $amountRaw = $gc['initialValue']['amount'] ?? null;
            $amountStr = $amountRaw !== null ? number_format((float)$amountRaw, 2, '.', '') : null;
            $currency  = $gc['currency'] ?? $destCurrency;

            $giftCardForCreate = [
                'initialValue' => ['amount' => $amountStr],
                'currency'     => $currency,
                'source'       => $gc['source'] ?? 'MANUAL',
            ];
            if (!empty($gc['expirationDate'])) {
                $giftCardForCreate['expirationDate'] = $gc['expirationDate'];
            }
            if (!empty($gc['notificationInfo']) && is_array($gc['notificationInfo'])) {
                if ($cleanNI = $sanitizeNotificationInfo($gc['notificationInfo'])) {
                    $giftCardForCreate['notificationInfo'] = $cleanNI;
                }
            }

            if (empty($giftCardForCreate['initialValue']['amount']) || empty($giftCardForCreate['currency'])) {
                $err = 'Missing required initialValue.amount or currency.';
                $claimed   = $claimPendingRow($sourceId);
                $targetRow = $resolveTargetRow($claimed, $sourceId);
                if ($targetRow) {
                    $targetRow->update([
                        'to_store_id'              => $toId,
                        'status'                   => 'failed',
                        'error_message'            => $err,
                        'initial_value_amount'     => $gc['initialValue']['amount'] ?? null,
                        'currency'                 => $gc['currency'] ?? null,
                        'source_code_suffix'       => $gc['code'] ?? $targetRow->source_code_suffix,
                        'source_gift_card_id'      => $targetRow->source_gift_card_id ?: ($gc['id'] ?? null),
                    ]);
                }
                $failed++;
                WixHelper::log('Auto Gift Cards Migration', "Skip {$sourceId}: {$err}", 'error');
                continue;
            }

            $payload = [
                'giftCard'       => $giftCardForCreate,
                'idempotencyKey' => 'gc-'.$uuid4(),
            ];
            $result = $this->createGiftCardInWix($toToken, $payload);

            // Claim & resolve row after API call
            $claimed   = $claimPendingRow($sourceId);
            $targetRow = $resolveTargetRow($claimed, $sourceId);

            if (isset($result['giftCard']['id'])) {
                $newId = $result['giftCard']['id'];
                if ($targetRow) {
                    DB::transaction(function () use ($targetRow, $toId, $newId, $giftCardForCreate, $gc) {
                        $targetRow->update([
                            'to_store_id'                => $toId,
                            'destination_gift_card_id'   => $newId,
                            'status'                     => 'success',
                            'error_message'              => null,
                            'initial_value_amount'       => $giftCardForCreate['initialValue']['amount'] ?? $targetRow->initial_value_amount,
                            'currency'                   => $giftCardForCreate['currency'] ?? $targetRow->currency,
                            'source_code_suffix'         => $gc['code'] ?? $targetRow->source_code_suffix,
                            'source_gift_card_id'        => $targetRow->source_gift_card_id ?: ($gc['id'] ?? null),
                        ]);
                    }, 3);
                }
                $imported++;
                WixHelper::log('Auto Gift Cards Migration', "Imported gift card (new ID: {$newId}).", 'success');
            } else {
                $errorMsg = json_encode([
                    'status'   => $result['status'] ?? null,
                    'sent'     => ['giftCard' => $giftCardForCreate],
                    'response' => $result
                ]);
                if ($targetRow) {
                    DB::transaction(function () use ($targetRow, $toId, $giftCardForCreate, $gc, $errorMsg) {
                        $targetRow->update([
                            'to_store_id'                => $toId,
                            'destination_gift_card_id'   => null,
                            'status'                     => 'failed',
                            'error_message'              => $errorMsg,
                            'initial_value_amount'       => $giftCardForCreate['initialValue']['amount'] ?? $targetRow->initial_value_amount,
                            'currency'                   => $giftCardForCreate['currency'] ?? $targetRow->currency,
                            'source_code_suffix'         => $gc['code'] ?? $targetRow->source_code_suffix,
                            'source_gift_card_id'        => $targetRow->source_gift_card_id ?: ($gc['id'] ?? null),
                        ]);
                    }, 3);
                }
                $failed++;
                WixHelper::log('Auto Gift Cards Migration', "Failed {$sourceId}: {$errorMsg}", 'error');
            }

            if ($processed % 50 === 0) {
                WixHelper::log('Auto Gift Cards Migration', "Progress: {$processed}/{$total}; imported={$imported}; failed={$failed}.", 'debug');
            }
        }

        // ===== Summary & flash (always surface a message) =====
        $summary = "Gift cards: imported={$imported}, failed={$failed}.";

        WixHelper::log('Auto Gift Cards Migration', "Done. {$summary}", $failed ? 'warn' : 'success');

        if ($imported > 0) {
            // Show a visible banner even on partial failures
            return back()->with('success', "Auto-migration completed. {$summary}");
        }

        if ($failed > 0) {
            // None imported and some failed → error banner
            return back()->with('error', "No gift cards imported. {$summary}");
        }

        // Nothing to do
        return back()->with('success', 'Nothing to import.');

    }


    // ========================================================= Manual Migrator =========================================================
    // =========================================================
    // Export Gift Cards (append-only, oldest-first)
    // =========================================================
    public function export(WixStore $store)
    {
        $userId      = Auth::id() ?: 1;
        $fromStoreId = $store->instance_id;

        WixHelper::log('Export Gift Cards', "Start: {$store->store_name} ({$fromStoreId})", 'info');

        $accessToken = WixHelper::getAccessToken($fromStoreId);
        if (!$accessToken) {
            WixHelper::log('Export Gift Cards', "Unauthorized: Could not get access token for instanceId: {$fromStoreId}.", 'error');
            return back()->with('error', 'You are not authorized to access.');
        }

        // Robust created-at resolver for sorting (returns millis; unknown -> PHP_INT_MAX)
        $createdAtMillis = function (array $item): int {
            foreach (['createdDate','dateCreated','createdAt','creationDate','date_created'] as $k) {
                if (array_key_exists($k, $item)) {
                    $v = $item[$k];
                    if (is_numeric($v)) return (int)$v;
                    if (is_string($v)) { $ts = strtotime($v); if ($ts !== false) return $ts * 1000; }
                }
            }
            foreach ([['audit','createdDate'], ['audit','dateCreated'], ['metadata','createdDate'], ['metadata','dateCreated']] as $path) {
                $cur = $item;
                foreach ($path as $p) { if (!is_array($cur) || !array_key_exists($p, $cur)) { $cur=null; break; } $cur=$cur[$p]; }
                if ($cur !== null) {
                    if (is_numeric($cur)) return (int)$cur;
                    if (is_string($cur)) { $ts = strtotime($cur); if ($ts !== false) return $ts * 1000; }
                }
            }
            return PHP_INT_MAX;
        };

        $resp = $this->getGiftCardsFromWix($accessToken);
        if (!isset($resp['giftCards']) || !is_array($resp['giftCards'])) {
            WixHelper::log('Export Gift Cards', 'API error: '.json_encode($resp), 'error');
            return response()->json(['error' => 'Failed to fetch gift cards from Wix.'], 500);
        }

        $cards = $resp['giftCards'];

        // Oldest-first
        usort($cards, fn($a, $b) => $createdAtMillis($a) <=> $createdAtMillis($b));

        // Append-only pending rows (no overwrite of past exports)
        $saved = 0;
        foreach ($cards as $gc) {
            $srcId = $gc['id'] ?? null;
            if (!$srcId) continue;

            WixGiftCardMigration::create([
                'user_id'                  => $userId,
                'from_store_id'            => $fromStoreId,
                'to_store_id'              => null,
                'source_gift_card_id'      => $srcId,
                // Wix returns obfuscated code; keep suffix for reference only
                'source_code_suffix'       => $gc['code'] ?? null,
                'initial_value_amount'     => $gc['initialValue']['amount'] ?? null,
                'currency'                 => $gc['currency'] ?? null,
                'destination_gift_card_id' => null,
                'status'                   => 'pending',
                'error_message'            => null,
            ]);
            $saved++;
        }
        WixHelper::log('Export Gift Cards', "Saved {$saved} pending row(s).", 'success');

        // Keep meta wrapper pattern
        return response()->streamDownload(function () use ($cards, $fromStoreId) {
            echo json_encode([
                'meta' => [
                    'from_store_id' => $fromStoreId,
                    'generated_at'  => now()->toIso8601String(),
                ],
                // accept gift_cards on import; exporting as gift_cards for parity
                'gift_cards' => $cards,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }, 'gift_cards.json', ['Content-Type' => 'application/json']);
    }

    // =========================================================
    // Import Gift Cards (oldest-first, claim/resolve, target-aware dedupe)
    // =========================================================
    public function import(Request $request, WixStore $store)
    {
        $userId    = Auth::id() ?: 1;
        $toStoreId = $store->instance_id;

        WixHelper::log('Import Gift Cards', "Start: {$store->store_name} ({$toStoreId})", 'info');

        $accessToken = WixHelper::getAccessToken($toStoreId);
        if (!$accessToken) {
            WixHelper::log('Import Gift Cards', "Unauthorized: Could not get access token for instanceId: {$toStoreId}.", 'error');
            return back()->with('error', 'Could not get Wix access token.');
        }

        if (!$request->hasFile('giftcards_json')) {
            WixHelper::log('Import Gift Cards', "No file uploaded for store: {$store->store_name}.", 'error');
            return back()->with('error', 'No file uploaded.');
        }

        $json    = file_get_contents($request->file('giftcards_json')->getRealPath());
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            WixHelper::log('Import Gift Cards', 'Invalid JSON uploaded.', 'error');
            return back()->with('error', 'Invalid JSON file.');
        }

        // Inline helpers (scoped to import)
        $ensureBearer = fn(string $t) => preg_match('/^Bearer\s+/i', $t) ? $t : ('Bearer '.$t);
        $authHeader   = $ensureBearer($accessToken);

        $createdAtMillis = function (array $item): int {
            foreach (['createdDate','dateCreated','createdAt','creationDate','date_created'] as $k) {
                if (array_key_exists($k, $item)) {
                    $v = $item[$k];
                    if (is_numeric($v)) return (int)$v;
                    if (is_string($v)) { $ts = strtotime($v); if ($ts !== false) return $ts * 1000; }
                }
            }
            foreach ([['audit','createdDate'], ['audit','dateCreated'], ['metadata','createdDate'], ['metadata','dateCreated']] as $path) {
                $cur = $item;
                foreach ($path as $p) { if (!is_array($cur) || !array_key_exists($p, $cur)) { $cur=null; break; } $cur=$cur[$p]; }
                if ($cur !== null) {
                    if (is_numeric($cur)) return (int)$cur;
                    if (is_string($cur)) { $ts = strtotime($cur); if ($ts !== false) return $ts * 1000; }
                }
            }
            return PHP_INT_MAX;
        };

        $uuid4 = function (): string {
            $data = random_bytes(16);
            $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
            $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
            return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
        };

        $sanitizeNotificationInfo = function (array $ni): ?array {
            $out = [];
            if (!empty($ni['recipient']) && is_array($ni['recipient'])) {
                $r = [];
                if (!empty($ni['recipient']['email'])) $r['email'] = $ni['recipient']['email'];
                if (!empty($ni['recipient']['name']))  $r['name']  = $ni['recipient']['name'];
                if ($r) $out['recipient'] = $r;
            }
            if (!empty($ni['sender']) && is_array($ni['sender'])) {
                $s = [];
                if (!empty($ni['sender']['email'])) $s['email'] = $ni['sender']['email'];
                if (!empty($ni['sender']['name']))  $s['name']  = $ni['sender']['name'];
                if ($s) $out['sender'] = $s;
            }
            if (!$out) return null;
            if (!empty($ni['notificationDate']))     $out['notificationDate']     = $ni['notificationDate'];
            if (!empty($ni['personalizedMessage']))  $out['personalizedMessage']  = $ni['personalizedMessage'];
            return $out;
        };

        $getStoreCurrency = function (string $authHeader) {
            $resp = Http::withHeaders([
                'Authorization' => $authHeader,
                'Content-Type'  => 'application/json',
            ])->get('https://www.wixapis.com/stores/v2/settings');

            if (!$resp->ok()) {
                WixHelper::log('Import Gift Cards', 'Get store settings failed: status='.$resp->status().' body='.$resp->body(), 'error');
                return null;
            }
            $j = $resp->json();
            return $j['storeSettings']['currency'] ?? $j['currency'] ?? null;
        };

        // Resolve from_store_id (request > meta > legacy)
        $explicitFromStoreId = $request->input('from_store_id')
            ?: ($decoded['meta']['from_store_id'] ?? ($decoded['from_store_id'] ?? null));
        if (!$explicitFromStoreId) {
            return back()->with('error', 'from_store_id is required. Provide it as a field or include meta.from_store_id / from_store_id in the JSON.');
        }

        // Accept both gift_cards and giftCards keys
        $giftCards = $decoded['gift_cards'] ?? $decoded['giftCards'] ?? null;
        if (!is_array($giftCards)) {
            WixHelper::log('Import Gift Cards', 'Invalid JSON structure: missing gift_cards/giftCards array.', 'error');
            return back()->with('error', 'Invalid JSON structure. Required key: gift_cards (or giftCards).');
        }

        // Oldest-first import
        usort($giftCards, fn($a, $b) => $createdAtMillis($a) <=> $createdAtMillis($b));

        // Lock/claim pattern (coupon-style)
        $claimPendingRow = function (?string $sourceId) use ($userId, $explicitFromStoreId) {
            return DB::transaction(function () use ($userId, $explicitFromStoreId, $sourceId) {
                $row = null;

                if ($sourceId) {
                    $row = WixGiftCardMigration::where('user_id', $userId)
                        ->where('from_store_id', $explicitFromStoreId)
                        ->where('status', 'pending')
                        ->where(function ($q) use ($sourceId) {
                            $q->where('source_gift_card_id', $sourceId)
                              ->orWhereNull('source_gift_card_id');
                        })
                        ->orderByRaw("CASE WHEN source_gift_card_id = ? THEN 0 ELSE 1 END", [$sourceId])
                        ->orderBy('created_at', 'asc')
                        ->lockForUpdate()
                        ->first();
                }
                if (!$row) {
                    $row = WixGiftCardMigration::where('user_id', $userId)
                        ->where('from_store_id', $explicitFromStoreId)
                        ->where('status', 'pending')
                        ->orderBy('created_at', 'asc')
                        ->lockForUpdate()
                        ->first();
                }
                return $row;
            }, 3);
        };

        $resolveTargetRow = function ($claimed, ?string $sourceId) use ($userId, $explicitFromStoreId, $toStoreId) {
            if ($sourceId) {
                $existing = WixGiftCardMigration::where('user_id', $userId)
                    ->where('from_store_id', $explicitFromStoreId)
                    ->where('to_store_id', $toStoreId)
                    ->where('source_gift_card_id', $sourceId)
                    ->orderBy('created_at', 'asc')
                    ->first();

                if ($existing) {
                    if ($claimed && $claimed->id !== $existing->id && $claimed->status === 'pending') {
                        $claimed->update([
                            'status'        => 'skipped',
                            'error_message' => 'Merged into existing migration row id '.$existing->id,
                        ]);
                    }
                    return $existing;
                }
            }
            return $claimed;
        };

        $imported = 0;
        $failed   = 0;

        foreach ($giftCards as $gc) {
            $sourceId = $gc['id'] ?? null;
            if (!$sourceId) continue;

            // Target-aware skip
            $already = WixGiftCardMigration::where([
                'user_id'             => $userId,
                'from_store_id'       => $explicitFromStoreId,
                'to_store_id'         => $toStoreId,
                'source_gift_card_id' => $sourceId,
            ])->first();
            if ($already && $already->status === 'success') {
                WixHelper::log('Import Gift Cards', "Skip (already imported): {$sourceId}.", 'debug');
                continue;
            }

            // Build clean payload
            $payloadGc = $gc;
            unset(
                $payloadGc['id'],
                $payloadGc['balance'],
                $payloadGc['createdDate'],
                $payloadGc['updatedDate'],
                $payloadGc['disabledDate'],
                $payloadGc['orderInfo'],
                $payloadGc['code'] // obfuscated; do not re-import
            );

            // Normalize amount & currency
            $amountRaw = $payloadGc['initialValue']['amount'] ?? null;
            $amountStr = $amountRaw !== null ? number_format((float)$amountRaw, 2, '.', '') : null;
            $resolvedCurrency = $payloadGc['currency'] ?? $getStoreCurrency($authHeader) ?? 'USD';

            $giftCardForCreate = [
                'initialValue' => ['amount' => $amountStr],
                'currency'     => $resolvedCurrency,
                'source'       => $payloadGc['source'] ?? 'MANUAL',
            ];
            if (!empty($payloadGc['expirationDate'])) {
                $giftCardForCreate['expirationDate'] = $payloadGc['expirationDate'];
            }
            if (!empty($payloadGc['notificationInfo']) && is_array($payloadGc['notificationInfo'])) {
                if ($cleanNI = $sanitizeNotificationInfo($payloadGc['notificationInfo'])) {
                    $giftCardForCreate['notificationInfo'] = $cleanNI;
                }
            }
            if (!empty($payloadGc['code_full'])) {
                $giftCardForCreate['code'] = $payloadGc['code_full'];
            }

            // Validate
            if (empty($giftCardForCreate['initialValue']['amount']) || empty($giftCardForCreate['currency'])) {
                $err = 'Missing required initialValue.amount or currency.';
                $claimed   = $claimPendingRow($sourceId);
                $targetRow = $resolveTargetRow($claimed, $sourceId);
                if ($targetRow) {
                    $targetRow->update([
                        'to_store_id'            => $toStoreId,
                        'status'                 => 'failed',
                        'error_message'          => $err,
                        'initial_value_amount'   => $gc['initialValue']['amount'] ?? null,
                        'currency'               => $gc['currency'] ?? null,
                        'source_code_suffix'     => $gc['code'] ?? $targetRow->source_code_suffix,
                        'source_gift_card_id'    => $targetRow->source_gift_card_id ?: ($gc['id'] ?? null),
                    ]);
                }
                $failed++;
                WixHelper::log('Import Gift Cards', "Skip {$sourceId}: {$err}", 'error');
                continue;
            }

            // Create with idempotency + alt-schema retry if 500
            $payload = [
                'giftCard'       => $giftCardForCreate,
                'idempotencyKey' => 'gc-'.$uuid4(),
            ];
            $result = $this->createGiftCardInWix($authHeader, $payload);

            // Claim & resolve row after API call
            $claimed   = $claimPendingRow($sourceId);
            $targetRow = $resolveTargetRow($claimed, $sourceId);

            if (isset($result['giftCard']['id'])) {
                $newId = $result['giftCard']['id'];
                if ($targetRow) {
                    DB::transaction(function () use ($targetRow, $toStoreId, $newId, $giftCardForCreate, $gc) {
                        $targetRow->update([
                            'to_store_id'                => $toStoreId,
                            'destination_gift_card_id'   => $newId,
                            'status'                     => 'success',
                            'error_message'              => null,
                            'initial_value_amount'       => $giftCardForCreate['initialValue']['amount'] ?? $targetRow->initial_value_amount,
                            'currency'                   => $giftCardForCreate['currency'] ?? $targetRow->currency,
                            'source_code_suffix'         => $gc['code'] ?? $targetRow->source_code_suffix,
                            'source_gift_card_id'        => $targetRow->source_gift_card_id ?: ($gc['id'] ?? null),
                        ]);
                    }, 3);
                }
                $imported++;
                WixHelper::log('Import Gift Cards', "Imported gift card (new ID: {$newId}).", 'success');
            } else {
                $errorMsg = json_encode([
                    'status'   => $result['status'] ?? null,
                    'sent'     => ['giftCard' => $giftCardForCreate],
                    'response' => $result
                ]);
                if ($targetRow) {
                    DB::transaction(function () use ($targetRow, $toStoreId, $giftCardForCreate, $gc, $errorMsg) {
                        $targetRow->update([
                            'to_store_id'                => $toStoreId,
                            'destination_gift_card_id'   => null,
                            'status'                     => 'failed',
                            'error_message'              => $errorMsg,
                            'initial_value_amount'       => $giftCardForCreate['initialValue']['amount'] ?? $targetRow->initial_value_amount,
                            'currency'                   => $giftCardForCreate['currency'] ?? $targetRow->currency,
                            'source_code_suffix'         => $gc['code'] ?? $targetRow->source_code_suffix,
                            'source_gift_card_id'        => $targetRow->source_gift_card_id ?: ($gc['id'] ?? null),
                        ]);
                    }, 3);
                }
                $failed++;
                WixHelper::log('Import Gift Cards', "Failed {$sourceId}: {$errorMsg}", 'error');
            }
        }

        if ($imported > 0) {
            WixHelper::log('Import Gift Cards', "Done. Imported={$imported}; failed={$failed}.", $failed ? 'warn' : 'success');
            return back()->with('success', "{$imported} gift card(s) imported. Failed: {$failed}");
        }
        return back()->with('error', $failed ? "No gift cards imported. Failed: {$failed}" : 'Nothing to import.');
    }

    // =========================================================
    // Utilities (kept as requested)
    // =========================================================
    public function getGiftCardsFromWix($accessToken)
    {
        $ensureBearer = fn(string $t) => preg_match('/^Bearer\s+/i', $t) ? $t : ('Bearer '.$t);

        $endpoint = 'https://www.wixapis.com/gift-cards/v1/gift-cards/query';
        $all      = [];
        $cursor   = null;

        do {
            $body = ['query' => ['cursorPaging' => ['limit' => 100]]];
            if ($cursor) $body['query']['cursorPaging']['cursor'] = $cursor;

            $response = Http::withHeaders([
                'Authorization' => $ensureBearer($accessToken),
                'Content-Type'  => 'application/json'
            ])->post($endpoint, $body);

            WixHelper::log('Export Gift Cards', 'Wix API response: status='.$response->status().' body='.$response->body(), $response->ok() ? 'debug' : 'error');

            if (!$response->ok()) {
                return ['error' => ['status' => $response->status(), 'body' => $response->body()]];
            }

            $json  = $response->json();
            $batch = $json['giftCards'] ?? [];
            if (!is_array($batch)) $batch = [];
            $all   = array_merge($all, $batch);

            $cursor = $json['pagingMetadata']['cursors']['next'] ?? null;
        } while ($cursor);

        return ['giftCards' => $all];
    }

    public function createGiftCardInWix($accessTokenOrHeader, array $payload)
    {
        $ensureBearer = fn(string $t) => preg_match('/^Bearer\s+/i', $t) ? $t : ('Bearer '.$t);

        $create = function(array $body) use ($accessTokenOrHeader, $ensureBearer) {
            return Http::withHeaders([
                'Authorization' => $ensureBearer($accessTokenOrHeader),
                'Content-Type'  => 'application/json'
            ])->post('https://www.wixapis.com/gift-cards/v1/gift-cards', $body);
        };

        // Attempt #1
        $resp1 = $create($payload);
        WixHelper::log('Import Gift Cards', 'Create gift card attempt#1: status='.$resp1->status().' body='.$resp1->body(), $resp1->ok() ? 'debug' : 'error');
        if ($resp1->ok()) {
            return $resp1->json();
        }

        // Attempt #2: move currency -> initialValue.currency if server error (schema quirk on some tenants)
        if ($resp1->status() === 500 && isset($payload['giftCard'])) {
            $alt = $payload;
            if (isset($alt['giftCard']['currency'])) {
                $altCurrency = $alt['giftCard']['currency'];
                unset($alt['giftCard']['currency']);
                $alt['giftCard']['initialValue']['currency'] = $altCurrency;
            }

            $resp2 = $create($alt);
            WixHelper::log('Import Gift Cards', 'Create gift card attempt#2 (alt schema): status='.$resp2->status().' body='.$resp2->body(), $resp2->ok() ? 'debug' : 'error');
            return $resp2->json();
        }

        // Fallthrough
        return $resp1->json();
    }
}
