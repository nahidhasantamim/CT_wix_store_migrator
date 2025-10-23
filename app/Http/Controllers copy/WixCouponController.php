<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;

use App\Helpers\WixHelper;
use App\Models\WixStore;
use App\Models\WixCouponMigration;
use App\Models\WixProductMigration;
use App\Models\WixCollectionMigration;

class WixCouponController extends Controller
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

        WixHelper::log('Auto Coupon Migration', "Start: {$fromLabel} → {$toLabel}", 'info');

        // Tokens
        $fromToken = WixHelper::getAccessToken($fromId);
        $toToken   = WixHelper::getAccessToken($toId);
        if (!$fromToken || !$toToken) {
            WixHelper::log('Auto Coupon Migration', 'Missing access token(s).', 'error');
            return back()->with('error', 'Coupon migration failed: Missing access token(s).');
        }
        $toAuth = preg_match('/^Bearer\s+/i', $toToken) ? $toToken : ('Bearer '.$toToken);

        // 1) Fetch source coupons (oldest-first)
        [$allCoupons, $pages] = $this->queryAllCoupons($fromToken, 100);
        usort($allCoupons, function ($a, $b) {
            $da = (int)($a['dateCreated'] ?? PHP_INT_MAX);
            $db = (int)($b['dateCreated'] ?? PHP_INT_MAX);
            return $da <=> $db;
        });
        WixHelper::log('Auto Coupon Migration', "Fetched ".count($allCoupons)." coupon(s) across {$pages} page(s).", 'info');

        // 2) Stage append-only pending rows (destination known now)
        $staged = 0;
        foreach ($allCoupons as $c) {
            $spec = $c['specification'] ?? [];
            $code = $spec['code'] ?? null;
            if (!$code) continue;

            try {
                WixCouponMigration::create([
                    'user_id'               => $userId,
                    'from_store_id'         => $fromId,
                    'to_store_id'           => $toId,
                    'source_coupon_code'    => $code,
                    'source_coupon_name'    => $spec['name'] ?? null,
                    'destination_coupon_id' => null,
                    'status'                => 'pending',
                    'error_message'         => null,
                ]);
                $staged++;
            } catch (QueryException $e) {
                // duplicate pending row is fine in auto mode; skip
            }
        }
        WixHelper::log('Auto Coupon Migration', "Staged {$staged} pending row(s).", 'success');

        // ---- Define claim/resolve for fail-fast mapping & results
        $claimPendingRow = function (?string $code) use ($userId, $fromId) {
            return DB::transaction(function () use ($userId, $fromId, $code) {
                $row = null;
                if ($code) {
                    $row = WixCouponMigration::where('user_id', $userId)
                        ->where('from_store_id', $fromId)
                        ->where('status', 'pending')
                        ->where(function ($q) use ($code) {
                            $q->where('source_coupon_code', $code)->orWhereNull('source_coupon_code');
                        })
                        ->orderByRaw("CASE WHEN source_coupon_code = ? THEN 0 ELSE 1 END", [$code])
                        ->orderBy('created_at', 'asc')
                        ->lockForUpdate()
                        ->first();
                }
                if (!$row) {
                    $row = WixCouponMigration::where('user_id', $userId)
                        ->where('from_store_id', $fromId)
                        ->where('status', 'pending')
                        ->orderBy('created_at', 'asc')
                        ->lockForUpdate()
                        ->first();
                }
                return $row;
            }, 3);
        };

        $resolveTargetRow = function ($claimed, ?string $code) use ($userId, $fromId, $toId) {
            if ($code) {
                $existing = WixCouponMigration::where('user_id', $userId)
                    ->where('from_store_id', $fromId)
                    ->where('to_store_id', $toId)
                    ->where('source_coupon_code', $code)
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

        // 3) Build clean specs with strict mapping; fail-fast on unmapped
        $specs   = [];
        $imported= 0;
        $failed  = 0;

        foreach ($allCoupons as $c) {
            $s = $c['specification'] ?? null;
            if (!is_array($s)) continue;
            if (empty($s['name']) || empty($s['code']) || empty($s['startTime'])) continue;

            if (isset($s['scope']['group']['entityId']) && ($s['scope']['group']['entityId'] === '' || $s['scope']['group']['entityId'] === null)) {
                unset($s['scope']['group']['entityId']);
            }

            $mapped = $this->mapScopeEntityIds($s, $fromId, $toId, true, $c ?? null); // strict=true
            $meta   = $mapped['__mapMeta'] ?? ['mapped' => true, 'reason' => 'n/a'];
            unset($mapped['__mapMeta']);

            if ($meta['mapped'] === true) {
                $specs[] = $mapped;
            } else {
                $code      = $s['code'] ?? null;
                $claimed   = $claimPendingRow($code);
                $targetRow = $resolveTargetRow($claimed, $code);

                if ($targetRow) {
                    $msg = "Entity mapping failed ({$meta['reason']})"
                        . (!empty($meta['wanted']) ? " for source entityId={$meta['wanted']}" : '');
                    $targetRow->update([
                        'to_store_id'        => $toId,
                        'status'             => 'failed',
                        'error_message'      => $msg,
                        'source_coupon_name' => $s['name'] ?? $targetRow->source_coupon_name,
                        'source_coupon_code' => $targetRow->source_coupon_code ?: ($code ?? null),
                    ]);
                    WixHelper::log('Auto Coupon Migration', $msg." | code=".($code ?? 'n/a'), 'warn');
                }
                $failed++;
            }
        }

        // 4) Bulk create on destination
        foreach (array_chunk($specs, 100) as $chunk) {
            $resp = Http::withHeaders([
                'Authorization' => $toAuth,
                'Content-Type'  => 'application/json',
            ])->post('https://www.wixapis.com/stores/v2/bulk/coupons/create', [
                'specifications'   => $chunk,
                'returnFullEntity' => true,
            ]);

            $raw = $resp->body();

            if ($resp->failed()) {
                foreach ($chunk as $s) {
                    $code      = $s['code'] ?? null;
                    $claimed   = $claimPendingRow($code);
                    $targetRow = $resolveTargetRow($claimed, $code);

                    if ($targetRow) {
                        $targetRow->update([
                            'to_store_id'        => $toId,
                            'status'             => 'failed',
                            'error_message'      => $raw,
                            'source_coupon_name' => $s['name'] ?? $targetRow->source_coupon_name,
                            'source_coupon_code' => $targetRow->source_coupon_code ?: ($code ?? null),
                        ]);
                    }
                    $failed++;
                }
                continue;
            }

            $res     = $resp->json();
            $results = $res['results'] ?? $res['items'] ?? [];

            foreach ($results as $i => $item) {
                $s        = $chunk[$i] ?? [];
                $code     = $s['code'] ?? null;
                $success  = $item['success'] ?? ($item['itemMetadata']['success'] ?? null);
                $couponId = $item['item']['id'] ?? ($item['coupon']['id'] ?? $item['id'] ?? null);

                $claimed   = $claimPendingRow($code);
                $targetRow = $resolveTargetRow($claimed, $code);

                if ($success === true && $couponId) {
                    if ($targetRow) {
                        DB::transaction(function () use ($targetRow, $toId, $couponId, $s, $code) {
                            $targetRow->update([
                                'to_store_id'           => $toId,
                                'destination_coupon_id' => $couponId,
                                'status'                => 'success',
                                'error_message'         => null,
                                'source_coupon_name'    => $s['name'] ?? $targetRow->source_coupon_name,
                                'source_coupon_code'    => $targetRow->source_coupon_code ?: ($code ?? null),
                            ]);
                        }, 3);
                    }
                    $imported++;
                } else {
                    $err = isset($item['error']) ? json_encode($item['error']) : 'Unknown error';
                    if ($targetRow) {
                        DB::transaction(function () use ($targetRow, $toId, $s, $code, $err) {
                            $targetRow->update([
                                'to_store_id'           => $toId,
                                'destination_coupon_id' => null,
                                'status'                => 'failed',
                                'error_message'         => $err,
                                'source_coupon_name'    => $s['name'] ?? $targetRow->source_coupon_name,
                                'source_coupon_code'    => $targetRow->source_coupon_code ?: ($code ?? null),
                            ]);
                        }, 3);
                    }
                    $failed++;
                }
            }
        }

        // ===== Summary & flash (surface partial failures) =====
        $summary = "Coupons: imported={$imported}, failed={$failed}";

        if ($imported > 0) {
            // Log success vs partial
            WixHelper::log('Auto Coupon Migration', "Done. {$summary}", $failed ? 'warn' : 'success');

            // Flash success normally; switch to warning when some failed
            return back()->with($failed ? 'warning' : 'success', "Auto coupon migration completed. {$summary}");
        }

        if ($failed > 0) {
            // None imported and some failed → error
            WixHelper::log('Auto Coupon Migration', "Done. {$summary}", 'error');
            return back()->with('error', "No coupons imported. {$summary}");
        }

        // Nothing to do
        WixHelper::log('Auto Coupon Migration', 'Done. Nothing to import.', 'info');
        return back()->with('success', 'Nothing to import.');

    }


    // ========================================================= Manual Migrator =========================================================
    // ========================== EXPORT (oldest-first)
    public function export(WixStore $store, Request $request)
    {
        $userId      = Auth::id() ?: 1;
        $fromStoreId = $store->instance_id;

        WixHelper::log('Export Coupons', "Start: {$store->store_name} ({$fromStoreId})", 'info');

        $accessToken = WixHelper::getAccessToken($fromStoreId);
        if (!$accessToken) {
            WixHelper::log('Export Coupons', 'Unauthorized: Missing access token.', 'error');
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        [$allCoupons, $pages] = $this->queryAllCoupons($accessToken, 100);

        // Oldest first by numeric millis
        usort($allCoupons, function ($a, $b) {
            $da = (int)($a['dateCreated'] ?? PHP_INT_MAX);
            $db = (int)($b['dateCreated'] ?? PHP_INT_MAX);
            return $da <=> $db;
        });

        WixHelper::log('Export Coupons', "Fetched ".count($allCoupons)." coupon(s) across {$pages} page(s).", 'success');

        // Always append new "pending" rows (no overwrite of past exports)
        $saved = 0;
        foreach ($allCoupons as $c) {
            $spec = $c['specification'] ?? [];
            $code = $spec['code'] ?? null;
            if (!$code) continue;

            WixCouponMigration::create([
                'user_id'                => $userId,
                'from_store_id'          => $fromStoreId,
                'to_store_id'            => null,
                'source_coupon_code'     => $code,
                'source_coupon_name'     => $spec['name'] ?? null,
                'destination_coupon_id'  => null,
                'status'                 => 'pending',
                'error_message'          => null,
            ]);
            $saved++;
        }
        WixHelper::log('Export Coupons', "Saved {$saved} pending row(s).", 'success');

        // Keep same output pattern: meta + coupons
        $exportPayload = [
            'meta' => [
                'from_store_id'   => $fromStoreId,
                'generated_at'    => now()->toIso8601String(),
            ],
            'coupons' => $allCoupons,
        ];

        return response()->streamDownload(function () use ($exportPayload) {
            echo json_encode($exportPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }, 'coupons.json', ['Content-Type' => 'application/json']);
    }

    // ========================== IMPORT (oldest-first)
    public function import(Request $request, WixStore $store)
    {
        $userId    = Auth::id() ?: 1;
        $toStoreId = $store->instance_id;

        WixHelper::log('Import Coupons', "Start: {$store->store_name} ({$toStoreId})", 'info');

        $accessToken = WixHelper::getAccessToken($toStoreId);
        if (!$accessToken) {
            WixHelper::log('Import Coupons', 'Unauthorized: Missing access token.', 'error');
            return back()->with('error', 'Coupon import failed: Unauthorized.');
        }

        if (!$request->hasFile('coupons_json')) {
            WixHelper::log('Import Coupons', 'No file uploaded.', 'error');
            return back()->with('error', 'Coupon import failed: No file uploaded.');
        }

        // Read file first so we can auto-detect from_store_id from meta if needed
        $json    = file_get_contents($request->file('coupons_json')->getRealPath());
        $payload = json_decode($json, true);
        if (!is_array($payload)) {
            WixHelper::log('Import Coupons', 'Invalid JSON uploaded.', 'error');
            return back()->with('error', 'Invalid JSON.');
        }

        // Accept either wrapped format ({ meta, coupons }) or raw list
        $coupons = $payload['coupons'] ?? $payload;

        // Determine from_store_id:
        // 1) prefer explicit request input
        // 2) else fallback to meta.from_store_id in uploaded JSON
        $explicitFromStoreId = $request->input('from_store_id') ?: ($payload['meta']['from_store_id'] ?? null);
        if (!$explicitFromStoreId) {
            WixHelper::log('Import Coupons', 'Missing from_store_id (input or meta).', 'error');
            return back()->with('error', 'from_store_id is required to map import to exported rows. Provide it as a field or include meta.from_store_id in the JSON.');
        }

        // Inline auth header
        $authHeader = preg_match('/^Bearer\s+/i', $accessToken) ? $accessToken : ('Bearer ' . $accessToken);

        // Oldest first (millis)
        usort($coupons, function ($a, $b) {
            $da = (int)($a['dateCreated'] ?? PHP_INT_MAX);
            $db = (int)($b['dateCreated'] ?? PHP_INT_MAX);
            return $da <=> $db;
        });

        // ---- Define closures EARLY so we can fail-fast during mapping
        $claimPendingRow = function (?string $code) use ($userId, $explicitFromStoreId) {
            return DB::transaction(function () use ($userId, $explicitFromStoreId, $code) {
                $row = null;

                if ($code) {
                    $row = WixCouponMigration::where('user_id', $userId)
                        ->where('from_store_id', $explicitFromStoreId)
                        ->where('status', 'pending')
                        ->where(function ($q) use ($code) {
                            $q->where('source_coupon_code', $code)
                              ->orWhereNull('source_coupon_code');
                        })
                        ->orderByRaw("CASE WHEN source_coupon_code = ? THEN 0 ELSE 1 END", [$code])
                        ->orderBy('created_at', 'asc')
                        ->lockForUpdate()
                        ->first();
                }

                if (!$row) {
                    $row = WixCouponMigration::where('user_id', $userId)
                        ->where('from_store_id', $explicitFromStoreId)
                        ->where('status', 'pending')
                        ->orderBy('created_at', 'asc')
                        ->lockForUpdate()
                        ->first();
                }

                return $row;
            }, 3);
        };

        // Before writing to a claimed row, dedupe by existing unique-combo:
        $resolveTargetRow = function (?WixCouponMigration $claimed, ?string $code) use ($userId, $explicitFromStoreId, $toStoreId) {
            // If there already exists a row for the same unique combo, prefer updating that one
            if ($code) {
                $existing = WixCouponMigration::where('user_id', $userId)
                    ->where('from_store_id', $explicitFromStoreId)
                    ->where('to_store_id', $toStoreId)
                    ->where('source_coupon_code', $code)
                    ->orderBy('created_at', 'asc')
                    ->first();

                if ($existing) {
                    // If we’re switching away from an extra pending row, mark it as skipped
                    if ($claimed && $claimed->id !== $existing->id && $claimed->status === 'pending') {
                        $claimed->update([
                            'status'        => 'skipped',
                            'error_message' => 'Merged into existing migration row id '.$existing->id,
                        ]);
                    }
                    return $existing;
                }
            }
            // Otherwise use the claimed one
            return $claimed;
        };

        // Build mapped specs with strict failure if entityId cannot be mapped
        $specs   = [];
        $failed  = 0;
        $imported= 0;

        foreach ($coupons as $c) {
            $s = $c['specification'] ?? null;
            if (!is_array($s)) continue;

            // Required minimal fields for creation
            if (empty($s['name']) || empty($s['code']) || empty($s['startTime'])) continue;

            // Trim empty group.entityId if present
            if (isset($s['scope']['group']['entityId']) && ($s['scope']['group']['entityId'] === '' || $s['scope']['group']['entityId'] === null)) {
                unset($s['scope']['group']['entityId']);
            }

            // Map (strict) with full source coupon context available
            $mapped = $this->mapScopeEntityIds($s, $explicitFromStoreId, $toStoreId, true, $c ?? null);
            $meta   = $mapped['__mapMeta'] ?? ['mapped' => true, 'reason' => 'n/a'];
            unset($mapped['__mapMeta']);

            if ($meta['mapped'] === true) {
                $specs[] = $mapped;
            } else {
                // fail-fast this coupon; do not send to Wix
                $code      = $s['code'] ?? null;
                $claimed   = $claimPendingRow($code);
                $targetRow = $resolveTargetRow($claimed, $code);

                if ($targetRow) {
                    $msg = "Entity mapping failed ({$meta['reason']})"
                         . (!empty($meta['wanted']) ? " for source entityId={$meta['wanted']}" : '');
                    $targetRow->update([
                        'to_store_id'           => $toStoreId,
                        'status'                => 'failed',
                        'error_message'         => $msg,
                        'source_coupon_name'    => $s['name'] ?? $targetRow->source_coupon_name,
                        'source_coupon_code'    => $targetRow->source_coupon_code ?: ($code ?? null),
                    ]);
                    WixHelper::log('Import Coupons', $msg." | code=".($code ?? 'n/a'), 'warn');
                }
                $failed++;
            }
        }

        // Send in chunks
        foreach (array_chunk($specs, 100) as $chunk) {
            $resp = Http::withHeaders([
                'Authorization' => $authHeader,
                'Content-Type'  => 'application/json',
            ])->post('https://www.wixapis.com/stores/v2/bulk/coupons/create', [
                'specifications'   => $chunk,
                'returnFullEntity' => true,
            ]);

            $raw = $resp->body();

            if ($resp->failed()) {
                foreach ($chunk as $s) {
                    $code      = $s['code'] ?? null;
                    $claimed   = $claimPendingRow($code);
                    $targetRow = $resolveTargetRow($claimed, $code);
                    if ($targetRow) {
                        $targetRow->update([
                            'to_store_id'           => $toStoreId,
                            'status'                => 'failed',
                            'error_message'         => $raw,
                            'source_coupon_name'    => $s['name'] ?? $targetRow->source_coupon_name,
                            'source_coupon_code'    => $targetRow->source_coupon_code ?: ($code ?? null),
                        ]);
                    }
                    $failed++;
                }
                continue;
            }

            $res     = $resp->json();
            $results = $res['results'] ?? $res['items'] ?? [];

            foreach ($results as $i => $item) {
                $s        = $chunk[$i] ?? [];
                $code     = $s['code'] ?? null;
                $success  = $item['success'] ?? ($item['itemMetadata']['success'] ?? null);
                $couponId = $item['item']['id'] ?? ($item['coupon']['id'] ?? $item['id'] ?? null);

                $claimed   = $claimPendingRow($code);
                $targetRow = $resolveTargetRow($claimed, $code);

                if ($success === true && $couponId) {
                    if ($targetRow) {
                        DB::transaction(function () use ($targetRow, $toStoreId, $couponId, $s, $code) {
                            $targetRow->update([
                                'to_store_id'           => $toStoreId,
                                'destination_coupon_id' => $couponId,
                                'status'                => 'success',
                                'error_message'         => null,
                                'source_coupon_name'    => $s['name'] ?? $targetRow->source_coupon_name,
                                'source_coupon_code'    => $targetRow->source_coupon_code ?: ($code ?? null),
                            ]);
                        }, 3);
                    }
                    $imported++;
                } else {
                    $err = isset($item['error']) ? json_encode($item['error']) : 'Unknown error';

                    if ($targetRow) {
                        DB::transaction(function () use ($targetRow, $toStoreId, $s, $code, $err) {
                            $targetRow->update([
                                'to_store_id'           => $toStoreId,
                                'destination_coupon_id' => null,
                                'status'                => 'failed',
                                'error_message'         => $err,
                                'source_coupon_name'    => $s['name'] ?? $targetRow->source_coupon_name,
                                'source_coupon_code'    => $targetRow->source_coupon_code ?: ($code ?? null),
                            ]);
                        }, 3);
                    }
                    $failed++;
                }
            }
        }

        if ($imported > 0) {
            $msg = "{$imported} coupon(s) imported. Failed: {$failed}";
            WixHelper::log('Import Coupons', $msg, $failed ? 'warn' : 'success');
            return back()->with('success', $msg);
        }

        if ($failed > 0) {
            $msg = "No coupons imported. Failed: {$failed}";
            WixHelper::log('Import Coupons', $msg, 'error');
            return back()->with('error', $msg);
        }

        WixHelper::log('Import Coupons', 'Done. Nothing to import.', 'info');
        return back()->with('success', 'Nothing to import.');
    }

    // ========================== Helpers ==========================
    /**
     * Map scope.group.entityId from source → destination using your existing migration tables.
     * Uses ONLY columns you already have:
     *  - wix_product_migrations:    source_product_id, source_product_name, destination_product_id
     *  - wix_collection_migrations: source_collection_id, source_collection_name, source_collection_slug, destination_collection_id
     *
     * @param array       $spec
     * @param string      $fromStoreId
     * @param string      $toStoreId
     * @param bool        $strict
     * @param array|null  $context   full coupon (for displayData->name)
     * @return array                 $spec + __mapMeta
     */
    private function mapScopeEntityIds(array $spec, string $fromStoreId, string $toStoreId, bool $strict = true, ?array $context = null): array
    {
        // Free shipping must have NO scope
        if (!empty($spec['freeShipping'])) {
            unset($spec['scope']);
            return $spec + ['__mapMeta' => ['mapped' => true, 'reason' => 'freeShipping']];
        }

        if (empty($spec['scope']['group']['name'])) {
            return $spec + ['__mapMeta' => ['mapped' => true, 'reason' => 'no-group']];
        }

        $groupName = $spec['scope']['group']['name'] ?? null;
        $entityId  = $spec['scope']['group']['entityId'] ?? null;

        // Clean empty entityId to avoid Wix validation errors
        if (array_key_exists('entityId', $spec['scope']['group'] ?? [])
            && ($spec['scope']['group']['entityId'] === '' || $spec['scope']['group']['entityId'] === null)) {
            unset($spec['scope']['group']['entityId']);
            $entityId = null;
        }

        if (!$groupName) {
            return $spec + ['__mapMeta' => ['mapped' => true, 'reason' => 'group-missing']];
        }

        // Context hints from export (optional)
        $displayName = trim((string)($context['displayData']['name'] ?? ''));
        $exportSlug  = trim((string)($context['displayData']['slug'] ?? ''));

        // ================= PRODUCT =================
        if ($groupName === 'product') {
            // Primary: map by source_product_id
            if ($entityId) {
                $map = \App\Models\WixProductMigration::where('from_store_id', $fromStoreId)
                    ->where('to_store_id',   $toStoreId)
                    ->where('source_product_id', $entityId)
                    ->whereNotNull('destination_product_id')
                    ->orderBy('created_at', 'asc')
                    ->first();

                if ($map && $map->destination_product_id) {
                    $spec['scope']['group']['entityId'] = (string) $map->destination_product_id;
                    return $spec + ['__mapMeta' => ['mapped' => true, 'reason' => 'product-by-id']];
                }
            }

            // Fallback: map by source_product_name (you already store this)
            if ($displayName !== '') {
                $map = \App\Models\WixProductMigration::where('from_store_id', $fromStoreId)
                    ->where('to_store_id',   $toStoreId)
                    ->where('source_product_name', $displayName)
                    ->whereNotNull('destination_product_id')
                    ->orderBy('created_at', 'asc')
                    ->first();

                if ($map && $map->destination_product_id) {
                    $spec['scope']['group']['entityId'] = (string) $map->destination_product_id;
                    return $spec + ['__mapMeta' => ['mapped' => true, 'reason' => 'product-by-name']];
                }
            }

            // Unmapped
            if ($strict) {
                return $spec + ['__mapMeta' => [
                    'mapped' => false,
                    'reason' => 'product-not-found',
                    'wanted' => $entityId ?: ($displayName ?: null),
                ]];
            }
            unset($spec['scope']['group']['entityId']); // widen scope if you chose non-strict
            return $spec + ['__mapMeta' => ['mapped' => true, 'reason' => 'product-fallback-namespace']];
        }

        // ================= COLLECTION =================
        if ($groupName === 'collection') {
            // Primary: map by source_collection_id
            if ($entityId) {
                $map = \App\Models\WixCollectionMigration::where('from_store_id', $fromStoreId)
                    ->where('to_store_id',     $toStoreId)
                    ->where('source_collection_id', $entityId)
                    ->whereNotNull('destination_collection_id')
                    ->orderBy('created_at', 'asc')
                    ->first();

                if ($map && $map->destination_collection_id) {
                    $spec['scope']['group']['entityId'] = (string) $map->destination_collection_id;
                    return $spec + ['__mapMeta' => ['mapped' => true, 'reason' => 'collection-by-id']];
                }
            }

            // Fallback A: map by source_collection_name (you already store this)
            if ($displayName !== '') {
                $map = \App\Models\WixCollectionMigration::where('from_store_id', $fromStoreId)
                    ->where('to_store_id',     $toStoreId)
                    ->where('source_collection_name', $displayName)
                    ->whereNotNull('destination_collection_id')
                    ->orderBy('created_at', 'asc')
                    ->first();

                if ($map && $map->destination_collection_id) {
                    $spec['scope']['group']['entityId'] = (string) $map->destination_collection_id;
                    return $spec + ['__mapMeta' => ['mapped' => true, 'reason' => 'collection-by-name']];
                }
            }

            // Fallback B: map by source_collection_slug (you already store this)
            if ($exportSlug !== '') {
                $map = \App\Models\WixCollectionMigration::where('from_store_id', $fromStoreId)
                    ->where('to_store_id',     $toStoreId)
                    ->where('source_collection_slug', $exportSlug)
                    ->whereNotNull('destination_collection_id')
                    ->orderBy('created_at', 'asc')
                    ->first();

                if ($map && $map->destination_collection_id) {
                    $spec['scope']['group']['entityId'] = (string) $map->destination_collection_id;
                    return $spec + ['__mapMeta' => ['mapped' => true, 'reason' => 'collection-by-slug']];
                }
            }

            // Unmapped
            if ($strict) {
                return $spec + ['__mapMeta' => [
                    'mapped' => false,
                    'reason' => 'collection-not-found',
                    'wanted' => $entityId ?: ($displayName ?: $exportSlug ?: null),
                ]];
            }
            unset($spec['scope']['group']['entityId']);
            return $spec + ['__mapMeta' => ['mapped' => true, 'reason' => 'collection-fallback-namespace']];
        }

        // Other groups: nothing to map
        return $spec + ['__mapMeta' => ['mapped' => true, 'reason' => 'other-group']];
    }


    /**
     * Paginates through Wix Coupons Query API and returns all coupons + page count.
     */
    private function queryAllCoupons(string $accessToken, int $limit = 100): array
    {
        $offset = 0;
        $page   = 0;
        $all    = [];

        while (true) {
            $page++;

            $body = [
                'query' => (object)[
                    'paging' => (object)[
                        'limit'  => max(1, min(100, $limit)),
                        'offset' => $offset,
                    ],
                ],
            ];

            $resp = Http::withHeaders([
                'Authorization' => preg_match('/^Bearer\s+/i', $accessToken) ? $accessToken : ('Bearer ' . $accessToken),
                'Content-Type'  => 'application/json',
            ])->post('https://www.wixapis.com/stores/v2/coupons/query', $body);

            if (!$resp->ok()) break;

            $json  = $resp->json() ?: [];
            $items = $json['coupons'] ?? [];
            $count = is_array($items) ? count($items) : 0;

            if ($count === 0) break;

            $all = array_merge($all, $items);

            if ($count < $limit) break;
            $offset += $limit;

            if ($page > 10000) break; // safety
        }

        return [$all, $page];
    }
}
