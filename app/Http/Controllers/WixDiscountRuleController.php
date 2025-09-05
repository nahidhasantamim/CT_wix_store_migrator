<?php

namespace App\Http\Controllers;

use App\Models\WixDiscountRuleMigration;
use App\Models\WixProductMigration;
use App\Models\WixCollectionMigration;
use App\Models\WixStore;
use App\Helpers\WixHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;

class WixDiscountRuleController extends Controller
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

        WixHelper::log('Auto Discount Rules Migration', "Start: {$fromLabel} → {$toLabel}", 'info');

        // --- Tokens
        $fromToken = WixHelper::getAccessToken($fromId);
        $toToken   = WixHelper::getAccessToken($toId);
        if (!$fromToken || !$toToken) {
            WixHelper::log('Auto Discount Rules Migration', 'Missing access token(s).', 'error');
            return back()->with('error', 'Could not get Wix access token(s).');
        }

        // --- 1) Fetch all rules from source (oldest-first)
        [$allRules, $pages] = $this->queryAllDiscountRules($fromToken, 100);
        usort($allRules, function ($a, $b) {
            $da = (int)($a['dateCreated'] ?? PHP_INT_MAX);
            $db = (int)($b['dateCreated'] ?? PHP_INT_MAX);
            return $da <=> $db;
        });
        WixHelper::log('Auto Discount Rules Migration', "Fetched ".count($allRules)." rule(s) across {$pages} page(s).", 'info');

        // --- 2) Stage append-only pending rows with destination known (avoid overwriting old exports)
        $staged = 0;
        foreach ($allRules as $rule) {
            $sourceId = $rule['id'] ?? null;
            $ruleName = $rule['name'] ?? null;
            if (!$sourceId) continue;

            try {
                WixDiscountRuleMigration::create([
                    'user_id'               => $userId,
                    'from_store_id'         => $fromId,
                    'to_store_id'           => $toId,
                    'source_rule_id'        => $sourceId,
                    'source_rule_name'      => $ruleName,
                    'destination_rule_id'   => null,
                    'status'                => 'pending',
                    'error_message'         => null,
                ]);
                $staged++;
            } catch (\Illuminate\Database\QueryException $e) {
                // duplicate pending row is fine in auto mode
            }
        }
        WixHelper::log('Auto Discount Rules Migration', "Staged {$staged} pending row(s).", 'success');

        // --- 3) Normalize payloads
        $prepared = $this->prepareDiscountRulesForImport($allRules);

        // --- 4) Row claim/resolve helpers
        $claimPendingRow = function (?string $sourceRuleId) use ($userId, $fromId) {
            return DB::transaction(function () use ($userId, $fromId, $sourceRuleId) {
                $row = null;

                if ($sourceRuleId) {
                    $row = WixDiscountRuleMigration::where('user_id', $userId)
                        ->where('from_store_id', $fromId)
                        ->where('status', 'pending')
                        ->where(function ($q) use ($sourceRuleId) {
                            $q->where('source_rule_id', $sourceRuleId)
                              ->orWhereNull('source_rule_id');
                        })
                        ->orderByRaw("CASE WHEN source_rule_id = ? THEN 0 ELSE 1 END", [$sourceRuleId])
                        ->orderBy('created_at', 'asc')
                        ->lockForUpdate()
                        ->first();
                }

                if (!$row) {
                    $row = WixDiscountRuleMigration::where('user_id', $userId)
                        ->where('from_store_id', $fromId)
                        ->where('status', 'pending')
                        ->orderBy('created_at', 'asc')
                        ->lockForUpdate()
                        ->first();
                }

                return $row;
            }, 3);
        };

        $resolveTargetRow = function ($claimed, ?string $sourceRuleId) use ($userId, $fromId, $toId) {
            if ($sourceRuleId) {
                $existing = WixDiscountRuleMigration::where('user_id', $userId)
                    ->where('from_store_id', $fromId)
                    ->where('to_store_id', $toId)
                    ->where('source_rule_id', $sourceRuleId)
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

        // --- 5) Create on destination, with entity-id mapping
        $imported = 0; $failed = 0; $processed = 0; $total = count($prepared);

        foreach ($prepared as $entry) {
            $processed++;

            $sourceId = $entry['__source_id'] ?? null;
            $ruleName = $entry['__name']      ?? null;
            $ruleBody = $entry['rule']        ?? [];

            // Map product/collection IDs from source→dest (STRICT)
            $map = $this->mapDiscountRuleEntityIds($ruleBody, $fromId, $toId, true);
            if (!$map['ok']) {
                $reason = $this->formatRuleMappingError($map['meta']);
                $claimed   = $claimPendingRow($sourceId);
                $targetRow = $resolveTargetRow($claimed, $sourceId);
                if ($targetRow) {
                    DB::transaction(function () use ($targetRow, $toId, $ruleName, $sourceId, $reason) {
                        $targetRow->update([
                            'to_store_id'           => $toId,
                            'destination_rule_id'   => null,
                            'status'                => 'failed',
                            'error_message'         => $reason,
                            'source_rule_name'      => $ruleName ?? $targetRow->source_rule_name,
                            'source_rule_id'        => $targetRow->source_rule_id ?: ($sourceId ?? null),
                        ]);
                    }, 3);
                }
                $failed++;
                continue; // do not send to Wix
            }
            $ruleBody = $map['rule'];

            $resp   = $this->createDiscountRuleInWix($toToken, $ruleBody);
            $raw    = $resp['raw'] ?? '';
            $ok     = $resp['ok']  ?? false;
            $destId = $resp['id']  ?? null;

            $claimed   = $claimPendingRow($sourceId);
            $targetRow = $resolveTargetRow($claimed, $sourceId);

            if ($ok && $destId) {
                if ($targetRow) {
                    DB::transaction(function () use ($targetRow, $toId, $destId, $ruleName, $sourceId) {
                        $targetRow->update([
                            'to_store_id'           => $toId,
                            'destination_rule_id'   => $destId,
                            'status'                => 'success',
                            'error_message'         => null,
                            'source_rule_name'      => $ruleName ?? $targetRow->source_rule_name,
                            'source_rule_id'        => $targetRow->source_rule_id ?: ($sourceId ?? null),
                        ]);
                    }, 3);
                }
                $imported++;
            } else {
                $err = $raw ?: 'Unknown error';
                if ($targetRow) {
                    DB::transaction(function () use ($targetRow, $toId, $ruleName, $sourceId, $err) {
                        $targetRow->update([
                            'to_store_id'           => $toId,
                            'destination_rule_id'   => null,
                            'status'                => 'failed',
                            'error_message'         => $err,
                            'source_rule_name'      => $ruleName ?? $targetRow->source_rule_name,
                            'source_rule_id'        => $targetRow->source_rule_id ?: ($sourceId ?? null),
                        ]);
                    }, 3);
                }
                $failed++;
            }

            if ($processed % 50 === 0) {
                WixHelper::log('Auto Discount Rules Migration', "Progress: {$processed}/{$total}; imported={$imported}; failed={$failed}", 'debug');
            }
        }

        // ===== Summary & flash (surface partial failures) =====
        $summary = "Discount rules: imported={$imported}, failed={$failed}";

        if ($imported > 0) {
            // Log: success if clean; warn on partial
            WixHelper::log('Auto Discount Rules Migration', "Done. {$summary}", $failed ? 'warn' : 'success');

            // Flash: always show success; add a warning when there were failures
            $resp = back()->with('success', "Auto discount rules migration completed. {$summary}");
            if ($failed > 0) {
                $resp = $resp->with('warning', 'Some rules failed to migrate. Check logs for details.');
            }
            return $resp;
        }

        if ($failed > 0) {
            // None imported and some failed → error
            WixHelper::log('Auto Discount Rules Migration', "Done. {$summary}", 'error');
            return back()->with('error', "No discount rules imported. {$summary}");
        }

        // Nothing to do
        WixHelper::log('Auto Discount Rules Migration', 'Done. Nothing to import.', 'info');
        return back()->with('success', 'Nothing to import.');

    }

    // ========================================================= Manual Migrator =========================================================
    // ========================================================= EXPORT (oldest-first)
    public function export(WixStore $store)
    {
        $userId      = Auth::id() ?: 1;
        $fromStoreId = $store->instance_id;

        WixHelper::log('Export Discount Rules', "Start: {$store->store_name} ({$fromStoreId})", 'info');

        $accessToken = WixHelper::getAccessToken($fromStoreId);
        if (!$accessToken) {
            WixHelper::log('Export Discount Rules', 'Unauthorized: Missing access token.', 'error');
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        [$allRules, $pages] = $this->queryAllDiscountRules($accessToken, 100);

        // Oldest first by numeric millis (fallback to very large number)
        usort($allRules, function ($a, $b) {
            $da = (int)($a['dateCreated'] ?? PHP_INT_MAX);
            $db = (int)($b['dateCreated'] ?? PHP_INT_MAX);
            return $da <=> $db;
        });

        WixHelper::log('Export Discount Rules', "Fetched ".count($allRules)." rule(s) across {$pages} page(s).", 'success');

        // Append-only: create NEW pending rows; do not overwrite previous exports
        $saved = 0;
        foreach ($allRules as $rule) {
            $sourceId = $rule['id']   ?? null;
            $ruleName = $rule['name'] ?? null;
            if (!$sourceId) continue;

            WixDiscountRuleMigration::create([
                'user_id'               => $userId,
                'from_store_id'         => $fromStoreId,
                'to_store_id'           => null,
                'source_rule_id'        => $sourceId,
                'source_rule_name'      => $ruleName,
                'destination_rule_id'   => null,
                'status'                => 'pending',
                'error_message'         => null,
            ]);
            $saved++;
        }
        WixHelper::log('Export Discount Rules', "Saved {$saved} pending row(s).", 'success');

        // Keep same pattern: meta + items
        $exportPayload = [
            'meta' => [
                'from_store_id' => $fromStoreId,
                'generated_at'  => now()->toIso8601String(),
            ],
            'discountRules' => $allRules,
        ];

        return response()->streamDownload(function () use ($exportPayload) {
            echo json_encode($exportPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }, 'discount_rules.json', ['Content-Type' => 'application/json']);
    }

    // ========================================================= IMPORT (oldest-first)
    public function import(Request $request, WixStore $store)
    {
        $userId    = Auth::id() ?: 1;
        $toStoreId = $store->instance_id;

        WixHelper::log('Import Discount Rules', "Start: {$store->store_name} ({$toStoreId})", 'info');

        $accessToken = WixHelper::getAccessToken($toStoreId);
        if (!$accessToken) {
            WixHelper::log('Import Discount Rules', 'Unauthorized: Missing access token.', 'error');
            return back()->with('error', 'Unauthorized');
        }

        if (!$request->hasFile('discount_rules_json')) {
            WixHelper::log('Import Discount Rules', 'No file uploaded.', 'error');
            return back()->with('error', 'No file uploaded.');
        }

        // Read JSON (allow wrapped {meta, discountRules} OR legacy {from_store_id, discountRules} OR raw array)
        $json    = file_get_contents($request->file('discount_rules_json')->getRealPath());
        $payload = json_decode($json, true);
        if (!is_array($payload)) {
            WixHelper::log('Import Discount Rules', 'Invalid JSON uploaded.', 'error');
            return back()->with('error', 'Invalid JSON.');
        }

        // Resolve list
        $rulesRaw = $payload['discountRules'] ?? (isset($payload[0]) ? $payload : ($payload['rules'] ?? []));
        if (!is_array($rulesRaw)) {
            WixHelper::log('Import Discount Rules', 'Invalid JSON: discountRules array not found.', 'error');
            return back()->with('error', 'Invalid JSON: discountRules array not found.');
        }

        // Resolve from_store_id: request > meta > legacy key
        $explicitFromStoreId = $request->input('from_store_id')
            ?: ($payload['meta']['from_store_id'] ?? ($payload['from_store_id'] ?? null));
        if (!$explicitFromStoreId) {
            WixHelper::log('Import Discount Rules', 'Missing from_store_id (input or meta).', 'error');
            return back()->with('error', 'from_store_id is required. Provide it as a field or include meta.from_store_id in the JSON.');
        }

        // 1) PREP PHASE: normalize & sort
        $rulesPrepared = $this->prepareDiscountRulesForImport($rulesRaw);

        // --- Row claiming & dedupe (pattern matched to CouponController) ---
        $claimPendingRow = function (?string $sourceRuleId) use ($userId, $explicitFromStoreId) {
            return DB::transaction(function () use ($userId, $explicitFromStoreId, $sourceRuleId) {
                $row = null;

                if ($sourceRuleId) {
                    $row = WixDiscountRuleMigration::where('user_id', $userId)
                        ->where('from_store_id', $explicitFromStoreId)
                        ->where('status', 'pending')
                        ->where(function ($q) use ($sourceRuleId) {
                            $q->where('source_rule_id', $sourceRuleId)
                              ->orWhereNull('source_rule_id');
                        })
                        ->orderByRaw("CASE WHEN source_rule_id = ? THEN 0 ELSE 1 END", [$sourceRuleId])
                        ->orderBy('created_at', 'asc')
                        ->lockForUpdate()
                        ->first();
                }

                if (!$row) {
                    $row = WixDiscountRuleMigration::where('user_id', $userId)
                        ->where('from_store_id', $explicitFromStoreId)
                        ->where('status', 'pending')
                        ->orderBy('created_at', 'asc')
                        ->lockForUpdate()
                        ->first();
                }

                return $row;
            }, 3);
        };

        $resolveTargetRow = function (?WixDiscountRuleMigration $claimed, ?string $sourceRuleId) use ($userId, $explicitFromStoreId, $toStoreId) {
            if ($sourceRuleId) {
                $existing = WixDiscountRuleMigration::where('user_id', $userId)
                    ->where('from_store_id', $explicitFromStoreId)
                    ->where('to_store_id', $toStoreId)
                    ->where('source_rule_id', $sourceRuleId)
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

        $imported  = 0;
        $failed    = 0;
        $processed = 0;
        $total     = count($rulesPrepared);

        // 2) CREATE PHASE: send to Wix per rule using helper
        foreach ($rulesPrepared as $entry) {
            $processed++;

            $sourceId = $entry['__source_id'] ?? null;   // original id (for mapping)
            $ruleName = $entry['__name']      ?? null;   // cached name (for logs)
            $ruleBody = $entry['rule'];                  // sanitized payload (no id)

            // Map product/collection IDs from source→dest (STRICT)
            $map = $this->mapDiscountRuleEntityIds($ruleBody, $explicitFromStoreId, $toStoreId, true);
            if (!$map['ok']) {
                $reason = $this->formatRuleMappingError($map['meta']);
                $claimed   = $claimPendingRow($sourceId);
                $targetRow = $resolveTargetRow($claimed, $sourceId);
                if ($targetRow) {
                    DB::transaction(function () use ($targetRow, $toStoreId, $ruleName, $sourceId, $reason) {
                        $targetRow->update([
                            'to_store_id'           => $toStoreId,
                            'destination_rule_id'   => null,
                            'status'                => 'failed',
                            'error_message'         => $reason,
                            'source_rule_name'      => $ruleName ?? $targetRow->source_rule_name,
                            'source_rule_id'        => $targetRow->source_rule_id ?: ($sourceId ?? null),
                        ]);
                    }, 3);
                }
                $failed++;
                continue; // do not send to Wix
            }
            $ruleBody = $map['rule'];

            $resp      = $this->createDiscountRuleInWix($accessToken, $ruleBody);
            $raw       = $resp['raw'] ?? '';
            $ok        = $resp['ok']  ?? false;
            $createdId = $resp['id']  ?? null;

            $claimed   = $claimPendingRow($sourceId);
            $targetRow = $resolveTargetRow($claimed, $sourceId);

            if ($ok && $createdId) {
                if ($targetRow) {
                    DB::transaction(function () use ($targetRow, $toStoreId, $createdId, $ruleName, $sourceId) {
                        $targetRow->update([
                            'to_store_id'           => $toStoreId,
                            'destination_rule_id'   => $createdId,
                            'status'                => 'success',
                            'error_message'         => null,
                            'source_rule_name'      => $ruleName ?? $targetRow->source_rule_name,
                            'source_rule_id'        => $targetRow->source_rule_id ?: ($sourceId ?? null),
                        ]);
                    }, 3);
                }
                $imported++;
            } else {
                $err = $raw ?: 'Unknown error';
                if ($targetRow) {
                    DB::transaction(function () use ($targetRow, $toStoreId, $ruleName, $sourceId, $err) {
                        $targetRow->update([
                            'to_store_id'           => $toStoreId,
                            'destination_rule_id'   => null,
                            'status'                => 'failed',
                            'error_message'         => $err,
                            'source_rule_name'      => $ruleName ?? $targetRow->source_rule_name,
                            'source_rule_id'        => $targetRow->source_rule_id ?: ($sourceId ?? null),
                        ]);
                    }, 3);
                }
                $failed++;
            }

            if ($processed % 50 === 0) {
                WixHelper::log('Import Discount Rules', "Progress: {$processed}/{$total}", 'debug');
            }
        }

        // ===== Summary & flash (match coupon controller pattern) =====
        if ($imported > 0) {
            $msg = "{$imported} rule(s) imported. Failed: {$failed}";
            WixHelper::log('Import Discount Rules', $msg, $failed ? 'warn' : 'success');
            return back()->with('success', $msg);
        }

        if ($failed > 0) {
            $msg = "No discount rules imported. Failed: {$failed}";
            WixHelper::log('Import Discount Rules', $msg, 'error');
            return back()->with('error', $msg);
        }

        WixHelper::log('Import Discount Rules', 'Done. Nothing to import.', 'info');
        return back()->with('success', 'Nothing to import.');
    }

    // =========================================================
    // Helpers
    // =========================================================

    /**
     * Query all discount rules using cursor paging.
     */
    private function queryAllDiscountRules(string $accessToken, int $limit = 100): array
    {
        $all   = [];
        $page  = 0;
        $cursor = null;

        while (true) {
            $page++;

            $body = [
                'query' => [
                    'sort' => [
                        ['fieldName' => 'name', 'order' => 'ASC'],
                    ],
                    'cursorPaging' => array_filter([
                        'limit'  => max(1, min(100, $limit)),
                        'cursor' => $cursor,
                    ]),
                ],
            ];

            $resp = Http::withHeaders([
                'Authorization' => preg_match('/^Bearer\s+/i', $accessToken) ? $accessToken : ('Bearer ' . $accessToken),
                'Content-Type'  => 'application/json',
            ])->post('https://www.wixapis.com/ecom/v1/discount-rules/query', $body);

            if (!$resp->ok()) {
                break;
            }

            $json  = $resp->json() ?: [];
            $items = $json['discountRules'] ?? [];
            if (!is_array($items) || count($items) === 0) {
                break;
            }

            $all = array_merge($all, $items);

            $pageInfo   = $json['pageInfo']        ?? $json['cursorPaging'] ?? [];
            $hasNext    = $pageInfo['hasNext']     ?? false;
            $nextCursor = $pageInfo['nextCursor']  ?? null;

            if (!$hasNext || !$nextCursor) {
                break;
            }
            $cursor = $nextCursor;

            if ($page > 10000) { // safety
                break;
            }
        }

        return [$all, $page];
    }

    /**
     * Prepare normalized rules list for import:
     * - Keeps original source id in __source_id for mapping to migration row
     * - Caches name in __name for logs
     * - Removes `id` from payload to let target generate a new one
     * - Sorts oldest-first by dateCreated millis (missing -> end)
     */
    private function prepareDiscountRulesForImport(array $rulesRaw): array
    {
        $prepared = [];

        foreach ($rulesRaw as $rule) {
            if (!is_array($rule)) continue;

            $sourceId = $rule['id']   ?? null;
            $name     = $rule['name'] ?? null;

            // sanitize: remove system id if present
            $clean = $rule;
            unset($clean['id']);

            $prepared[] = [
                '__source_id' => $sourceId,
                '__name'      => $name,
                '__date'      => (int)($rule['dateCreated'] ?? PHP_INT_MAX),
                'rule'        => $clean,
            ];
        }

        // Oldest first
        usort($prepared, function ($a, $b) {
            return ($a['__date'] <=> $b['__date']);
        });

        return $prepared;
    }

    /**
     * Create a discount rule in Wix.
     */
    private function createDiscountRuleInWix(string $accessToken, array $rule): array
    {
        $authHeader = preg_match('/^Bearer\s+/i', $accessToken) ? $accessToken : ('Bearer ' . $accessToken);

        $response = Http::withHeaders([
            'Authorization' => $authHeader,
            'Content-Type'  => 'application/json',
        ])->post('https://www.wixapis.com/ecom/v1/discount-rules', [
            'discountRule' => $rule,
        ]);

        $raw  = $response->body();
        $json = $response->json() ?: [];
        $id   = $json['discountRule']['id'] ?? null;
        $ok   = $response->ok() && $id;

        if (!$ok) {
            WixHelper::log('Import Discount Rules', "Create failed: {$response->status()} | {$raw}", 'warn');
        }

        return [
            'ok'  => $ok,
            'id'  => $id,
            'raw' => $raw,
            'json'=> $json,
        ];
    }

    /**
     * Maps product/collection IDs inside Discount Rule payloads from source → dest.
     * STRICT mode: if any source IDs can't be mapped, returns ok=false with meta reasons.
     */
    private function mapDiscountRuleEntityIds(array $rule, string $fromStoreId, string $toStoreId, bool $strict = true): array
    {
        $meta = [
            'missing_products'    => [],
            'missing_collections' => [],
            'mapped_products'     => 0,
            'mapped_collections'  => 0,
        ];

        // 1) Map TRIGGERS
        if (!empty($rule['trigger']) && is_array($rule['trigger'])) {
            $triggerRef = $rule['trigger'];
            $this->walkTriggersAndMap($triggerRef, $fromStoreId, $toStoreId, $meta);
            $rule['trigger'] = $triggerRef;
        }

        // 2) Map DISCOUNTS
        if (!empty($rule['discounts']['values']) && is_array($rule['discounts']['values'])) {
            foreach ($rule['discounts']['values'] as $idx => $discount) {
                $targetType = $discount['targetType'] ?? null;

                if ($targetType === 'SPECIFIC_ITEMS') {
                    if (!empty($discount['specificItemsInfo']['scopes']) && is_array($discount['specificItemsInfo']['scopes'])) {
                        $scopesRef = $discount['specificItemsInfo']['scopes'];
                        $this->mapScopesArray($scopesRef, $fromStoreId, $toStoreId, $meta);
                        $discount['specificItemsInfo']['scopes'] = $scopesRef;
                    }
                }

                if ($targetType === 'BUY_X_GET_Y') {
                    $bxgy = $discount['buyXGetYInfo'] ?? [];

                    if (!empty($bxgy['customerBuys']['scopes']) && is_array($bxgy['customerBuys']['scopes'])) {
                        $buysScopesRef = $bxgy['customerBuys']['scopes'];
                        $this->mapScopesArray($buysScopesRef, $fromStoreId, $toStoreId, $meta);
                        $bxgy['customerBuys']['scopes'] = $buysScopesRef;
                    }
                    if (!empty($bxgy['customerGets']['scopes']) && is_array($bxgy['customerGets']['scopes'])) {
                        $getsScopesRef = $bxgy['customerGets']['scopes'];
                        $this->mapScopesArray($getsScopesRef, $fromStoreId, $toStoreId, $meta);
                        $bxgy['customerGets']['scopes'] = $getsScopesRef;
                    }

                    $discount['buyXGetYInfo'] = $bxgy;
                }

                $rule['discounts']['values'][$idx] = $discount;
            }
        }

        if ($strict && (!empty($meta['missing_products']) || !empty($meta['missing_collections']))) {
            return ['ok' => false, 'rule' => $rule, 'meta' => $meta];
        }

        return ['ok' => true, 'rule' => $rule, 'meta' => $meta];
    }

    /**
     * Walk trigger tree (AND/OR + leaves) and map scopes in-place.
     */
    private function walkTriggersAndMap(array &$triggerNode, string $fromStoreId, string $toStoreId, array &$meta): void
    {
        $type = $triggerNode['triggerType'] ?? null;

        // Composite nodes
        if (in_array($type, ['AND', 'OR'], true)) {
            if (!empty($triggerNode['and']['triggers']) && is_array($triggerNode['and']['triggers'])) {
                foreach ($triggerNode['and']['triggers'] as $i => $child) {
                    $this->walkTriggersAndMap($child, $fromStoreId, $toStoreId, $meta);
                    $triggerNode['and']['triggers'][$i] = $child;
                }
            }
            if (!empty($triggerNode['or']['triggers']) && is_array($triggerNode['or']['triggers'])) {
                foreach ($triggerNode['or']['triggers'] as $i => $child) {
                    $this->walkTriggersAndMap($child, $fromStoreId, $toStoreId, $meta);
                    $triggerNode['or']['triggers'][$i] = $child;
                }
            }
            return;
        }

        // Leaf nodes: ITEM_QUANTITY_RANGE, SUBTOTAL_RANGE
        foreach (['itemQuantityRange', 'subtotalRange'] as $leafKey) {
            if (!empty($triggerNode[$leafKey]['scopes']) && is_array($triggerNode[$leafKey]['scopes'])) {
                $scopesRef = $triggerNode[$leafKey]['scopes'];
                $this->mapScopesArray($scopesRef, $fromStoreId, $toStoreId, $meta);
                $triggerNode[$leafKey]['scopes'] = $scopesRef;
            }
        }
    }

    /**
     * Map an array of Scope objects in-place.
     */
    private function mapScopesArray(array &$scopes, string $fromStoreId, string $toStoreId, array &$meta): void
    {
        foreach ($scopes as &$scope) {
            $scopeType = $scope['type'] ?? null;

            if ($scopeType === 'CATALOG_ITEM') {
                $ids = $scope['catalogItemFilter']['catalogItemIds'] ?? null;
                if (is_array($ids) && count($ids)) {
                    $mapped = [];
                    foreach ($ids as $pid) {
                        $dest = $this->lookupDestinationProductId((string)$pid, $fromStoreId, $toStoreId);
                        if ($dest) {
                            $mapped[] = (string)$dest;
                            $meta['mapped_products']++;
                        } else {
                            $meta['missing_products'][] = (string)$pid;
                        }
                    }
                    $scope['catalogItemFilter']['catalogItemIds'] = $mapped;
                }
            }

            if ($scopeType === 'CUSTOM_FILTER') {
                $collections = $scope['customFilter']['params']['collectionIds'] ?? null;
                if (is_array($collections) && count($collections)) {
                    $mapped = [];
                    foreach ($collections as $cid) {
                        $dest = $this->lookupDestinationCollectionId((string)$cid, $fromStoreId, $toStoreId);
                        if ($dest) {
                            $mapped[] = (string)$dest;
                            $meta['mapped_collections']++;
                        } else {
                            $meta['missing_collections'][] = (string)$cid;
                        }
                    }
                    $scope['customFilter']['params']['collectionIds'] = $mapped;
                }
            }
        }
        unset($scope);
    }

    private function lookupDestinationProductId(string $sourceProductId, string $fromStoreId, string $toStoreId): ?string
    {
        $row = WixProductMigration::where('from_store_id', $fromStoreId)
            ->where('to_store_id',   $toStoreId)
            ->where('source_product_id', $sourceProductId)
            ->whereNotNull('destination_product_id')
            ->orderBy('created_at', 'asc')
            ->first();

        return $row?->destination_product_id ? (string)$row->destination_product_id : null;
    }

    private function lookupDestinationCollectionId(string $sourceCollectionId, string $fromStoreId, string $toStoreId): ?string
    {
        $row = WixCollectionMigration::where('from_store_id', $fromStoreId)
            ->where('to_store_id',   $toStoreId)
            ->where('source_collection_id', $sourceCollectionId)
            ->whereNotNull('destination_collection_id')
            ->orderBy('created_at', 'asc')
            ->first();

        return $row?->destination_collection_id ? (string)$row->destination_collection_id : null;
    }

    private function formatRuleMappingError(array $meta): string
    {
        $parts = [];
        if (!empty($meta['missing_products'])) {
            $parts[] = 'product-not-found: '.implode(',', array_slice($meta['missing_products'], 0, 5))
                    . (count($meta['missing_products']) > 5 ? '…' : '');
        }
        if (!empty($meta['missing_collections'])) {
            $parts[] = 'collection-not-found: '.implode(',', array_slice($meta['missing_collections'], 0, 5))
                    . (count($meta['missing_collections']) > 5 ? '…' : '');
        }
        return $parts ? ('Entity mapping failed ('.implode(' | ', $parts).')') : 'Entity mapping failed';
    }
}
