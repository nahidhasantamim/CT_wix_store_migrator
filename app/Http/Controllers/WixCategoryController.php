<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use App\Models\WixStore;
use App\Helpers\WixHelper;
use App\Models\WixCollectionMigration;

class WixCategoryController extends Controller
{
    
    // -----------------------------------------
    // Defaults for V3 Categories API
    // -----------------------------------------
    protected string $defaultAppNamespace = '@wix/stores';
    protected ?string $defaultTreeKey = null; // set if you manage multiple trees

    /** Default extra fields to include on V3 export (safe + useful) */
    protected array $defaultExportFieldsV3 = [
        'DESCRIPTION',
        'RICH_CONTENT_DESCRIPTION',
        'SEO_DATA',
        'MANAGING_APP_ID',
        'EXTENDED_FIELDS',
        // 'BREADCRUMBS_INFO',
    ];

    /** System categories we should never try to recreate */
    protected array $systemCategorySlugs = ['all-products'];
    protected array $systemCategoryNames = ['all products', 'all-products'];
    protected array $systemCategoryIds   = ['00000000-000000-000000-000000000001']; // All Products


    // ========================================================= Automatic Migrator =========================================================
    public function migrateAuto(Request $request)
    {
        $request->validate([
            'from_store' => 'required|string',
            'to_store'   => 'required|string|different:from_store',
        ]);

        $userId = Auth::id() ?: 1;
        $fromId = $request->input('from_store');
        $toId   = $request->input('to_store');

        $fromToken = WixHelper::getAccessToken($fromId);
        $toToken   = WixHelper::getAccessToken($toId);
        if (!$fromToken || !$toToken) {
            WixHelper::log('Auto Category Migration', 'Missing access token(s).', 'error');
            return back()->with('error', 'Could not get Wix access token(s).');
        }

        // Logging niceties
        $fromStore  = \App\Models\WixStore::where('instance_id', $fromId)->first();
        $toStore    = \App\Models\WixStore::where('instance_id', $toId)->first();
        $fromLabel  = $fromStore?->store_name ?: $fromId;
        $toLabel    = $toStore?->store_name   ?: $toId;
        WixHelper::log('Auto Category Migration', "Start: {$fromLabel} → {$toLabel}", 'info');

        $srcIsV3 = $this->isCatalogV3(WixHelper::getCatalogVersion($fromToken));
        $dstIsV3 = $this->isCatalogV3(WixHelper::getCatalogVersion($toToken));

        // ---------- helpers ----------
        $appNamespace = $this->treeAppNamespaceFromRequest();
        $treeKey      = $this->treeKeyFromRequest();

        $buildSeoFromDesc = function (?string $desc): ?array {
            $desc = trim(preg_replace('/\s+/u', ' ', strip_tags((string)$desc)));
            if ($desc === '') return null;
            // Reasonable meta length
            $desc = mb_substr($desc, 0, 300, 'UTF-8');
            return [
                'tags' => [[
                    'type'  => 'meta',
                    'props' => [
                        'fields' => [
                            'name'    => ['stringValue' => 'description'],
                            'content' => ['stringValue' => $desc],
                        ]
                    ],
                ]]
            ];
        };

        $ensureSeoOnPayload = function (array &$payload, array $source) use ($buildSeoFromDesc) {
            // If seoData missing meta description, build from description
            $hasMeta = false;
            if (isset($payload['seoData']['tags']) && is_array($payload['seoData']['tags'])) {
                foreach ($payload['seoData']['tags'] as $t) {
                    if (($t['type'] ?? '') === 'meta'
                        && strtolower($t['props']['fields']['name']['stringValue'] ?? '') === 'description') {
                        $hasMeta = true; break;
                    }
                }
            }
            if (!$hasMeta) {
                $seo = $buildSeoFromDesc($payload['description'] ?? ($source['description'] ?? null));
                if ($seo) $payload['seoData'] = $seo;
            }
        };

        $getV3Detail = function (string $id) use ($toToken, $appNamespace, $treeKey) {
            return $this->getCategoryByIdV3($toToken, $id, $appNamespace, $treeKey);
        };

        $patchV3 = function (string $id, array $partial) use ($toToken, $appNamespace, $treeKey, $getV3Detail) {
            // Need latest revision
            $detail = $getV3Detail($id);
            $rev = $detail['category']['revision'] ?? null;
            if ($rev === null) return ['error' => 'Missing revision'];

            $body = [
                'category'      => array_merge(['revision' => (string)$rev], $partial),
                'treeReference' => $this->treeRefArray($appNamespace, $treeKey),
            ];
            $resp = Http::withHeaders([
                'Authorization' => $this->ensureBearer($toToken),
                'Content-Type'  => 'application/json'
            ])->patch("https://www.wixapis.com/categories/v1/categories/{$id}", $body);
            return $resp->json() ?: [];
        };

        $patchV1 = function (string $id, array $partial) use ($toToken) {
            $resp = Http::withHeaders([
                'Authorization' => $this->ensureBearer($toToken),
                'Content-Type'  => 'application/json'
            ])->patch("https://www.wixapis.com/stores/v1/collections/{$id}", ['collection' => $partial]);
            return $resp->json() ?: [];
        };

        $v1Get = function (string $id) use ($toToken) {
            return $this->getCollectionByIdV1($toToken, $id, false);
        };

        // ---------- 1) Fetch source categories ----------
        $categories = [];
        if ($srcIsV3) {
            $fields = $this->exportFieldsV3FromRequest() ?: $this->defaultExportFieldsV3;
            if (!in_array('DESCRIPTION', $fields, true)) $fields[] = 'DESCRIPTION';
            if (!in_array('SEO_DATA', $fields, true))    $fields[] = 'SEO_DATA';

            $res = $this->queryCategoriesV3All($fromToken, $fields, $appNamespace, $treeKey, true);
            if (($res['failed'] ?? false) || empty($res['categories'])) {
                WixHelper::log('Auto Category Migration', 'V3 QUERY empty; falling back to SEARCH.', 'warn');
                $res = $this->searchCategoriesV3All($fromToken, $fields, $appNamespace, $treeKey, true);
            }
            if ((($res['failed'] ?? false) || empty($res['categories'])) && !empty($fields)) {
                WixHelper::log('Auto Category Migration', 'SEARCH empty; retry QUERY without fields.', 'warn');
                $res = $this->queryCategoriesV3All($fromToken, [], $appNamespace, $treeKey, true);
            }
            $categories = $res['categories'] ?? [];
        } else {
            $v1 = $this->getCollectionsFromWixV1($fromToken);
            $collections = $v1['collections'] ?? [];
            $categories = array_map(function ($col) {
                $cat = [
                    'id'   => $col['id']   ?? null,
                    'name' => $col['name'] ?? '',
                ];
                if (!empty($col['slug']))        $cat['slug'] = $col['slug'];
                if (!empty($col['description'])) $cat['description'] = $col['description'];
                if (array_key_exists('visible', $col)) $cat['visible'] = (bool)$col['visible'];
                if (!empty($col['media']['mainMedia']['image']['url'])) {
                    $cat['image'] = ['url' => $col['media']['mainMedia']['image']['url'], 'altText' => $col['name'] ?? null];
                }
                return $cat;
            }, $collections);

            // Enrich description if missing
            foreach ($categories as &$catRef) {
                if (empty($catRef['description']) && !empty($catRef['id'])) {
                    $detail = $this->getCollectionByIdV1($fromToken, $catRef['id']);
                    $full   = $detail['collection'] ?? [];
                    if (!empty($full['description'])) {
                        $catRef['description'] = $full['description'];
                    } elseif (!empty($full['additionalInfo']['description'])) {
                        $catRef['description'] = $full['additionalInfo']['description'];
                    }
                }
            }
            unset($catRef);
        }

        // Sort oldest-first
        $createdAtMillis = function (array $item): int {
            foreach (['createdDate','dateCreated','createdAt','creationDate','date_created'] as $k) {
                if (array_key_exists($k, $item)) {
                    $v = $item[$k];
                    if (is_numeric($v)) return (int)$v;
                    if (is_string($v)) { $ts = strtotime($v); if ($ts !== false) return $ts * 1000; }
                }
            }
            foreach ([['audit','createdDate'], ['audit','dateCreated'], ['metadata','createdDate'], ['metadata','dateCreated']] as $p) {
                $cur = $this->getNested($item, $p);
                if ($cur !== null) {
                    if (is_numeric($cur)) return (int)$cur;
                    if (is_string($cur))  { $ts = strtotime($cur); if ($ts !== false) return $ts * 1000; }
                }
            }
            return PHP_INT_MAX;
        };
        usort($categories, fn($a,$b) => $createdAtMillis($a) <=> $createdAtMillis($b));

        // ---------- 2) Destination indexes ----------
        $existingSlugs = [];
        $v3IndexBySlug = $v3IndexByName = $v1IndexBySlug = $v1IndexByName = [];
        $allProductsIdV3 = null;

        if ($dstIsV3) {
            $scan = $this->queryCategoriesV3All($toToken, ['DESCRIPTION','SEO_DATA'], $appNamespace, $treeKey, true);
            if (empty($scan['categories'])) {
                $scan = $this->searchCategoriesV3All($toToken, ['DESCRIPTION','SEO_DATA'], $appNamespace, $treeKey, true);
            }
            foreach (($scan['categories'] ?? []) as $c) {
                if (!empty($c['slug'])) $v3IndexBySlug[strtolower($c['slug'])] = $c;
                if (!empty($c['name'])) $v3IndexByName[strtolower($c['name'])] = $c;
            }
            $existingSlugs = $this->getExistingSlugsV3($toToken, $appNamespace, $treeKey);
            $allProductsIdV3 = $this->getAllProductsCategoryIdV3($toToken);
        } else {
            $scan = $this->getCollectionsFromWixV1($toToken);
            foreach (($scan['collections'] ?? []) as $c) {
                if (!empty($c['slug'])) $v1IndexBySlug[strtolower($c['slug'])] = $c;
                if (!empty($c['name'])) $v1IndexByName[strtolower($c['name'])] = $c;
            }
        }

        // ---------- 3) Stage + Upsert/Recreate ----------
        $created = 0; $updated = 0; $recreated = 0; $failed = 0; $skipped = 0; $stagedNew = 0; $stagedExisting = 0;

        foreach ($categories as $cat) {
            $srcId = $cat['id'] ?? null;
            if (!$srcId) continue;

            // ensure we have a row to update
            try {
                $row = WixCollectionMigration::firstOrCreate(
                    [
                        'user_id'              => $userId,
                        'from_store_id'        => $fromId,
                        'to_store_id'          => $toId,
                        'source_collection_id' => $srcId,
                    ],
                    [
                        'source_collection_slug'    => $cat['slug'] ?? null,
                        'source_collection_name'    => $cat['name'] ?? null,
                        'destination_collection_id' => null,
                        'status'                    => 'pending',
                        'error_message'             => null,
                    ]
                );
                $row->wasRecentlyCreated ? $stagedNew++ : $stagedExisting++;
            } catch (\Illuminate\Database\QueryException $e) {
                if ($e->getCode() === '23000') {
                    $row = WixCollectionMigration::where([
                        ['user_id', '=', $userId],
                        ['from_store_id', '=', $fromId],
                        ['to_store_id', '=', $toId],
                        ['source_collection_id', '=', $srcId],
                    ])->first();
                    if (!$row) continue;
                } else { throw $e; }
            }

            // Always try to sync—don't early-continue based on status

            // Special case: "All Products" -> UPDATE only
            if ($this->shouldSkipSystemCategory($cat)) {
                if ($dstIsV3 && $allProductsIdV3) {
                    $patch = $this->sanitizeCategoryForCreateV3($cat);
                    unset($patch['slug']); // keep core slug intact
                    $ensureSeoOnPayload($patch, $cat);

                    $res = $patchV3($allProductsIdV3, $patch);
                    if (isset($res['category']['id'])) {
                        $row->update([
                            'destination_collection_id' => $allProductsIdV3,
                            'status'                    => 'success',
                            'error_message'             => null,
                            'source_collection_name'    => $cat['name'] ?? $row->source_collection_name,
                            'source_collection_slug'    => $row->source_collection_slug ?? ($cat['slug'] ?? null),
                        ]);
                        $updated++;
                    } else {
                        $row->update([
                            'status'        => 'failed',
                            'error_message' => json_encode(['patch' => $patch, 'response' => $res]),
                        ]);
                        $failed++;
                    }
                    continue;
                }

                // For V1: try to find by slug/name and update (cannot create)
                if (!$dstIsV3) {
                    $match = null;
                    if (!empty($cat['slug'])) $match = $v1IndexBySlug[strtolower($cat['slug'])] ?? null;
                    if (!$match && !empty($cat['name'])) $match = $v1IndexByName[strtolower($cat['name'])] ?? null;

                    if ($match && !empty($match['id'])) {
                        $patch = [
                            'name'        => $cat['name'] ?? $match['name'] ?? '',
                            'description' => $cat['description'] ?? ($match['description'] ?? null),
                            'visible'     => array_key_exists('visible', $cat) ? (bool)$cat['visible'] : ($match['visible'] ?? true),
                        ];
                        $res = $patchV1($match['id'], $patch);
                        if (isset($res['collection']['id'])) {
                            $row->update([
                                'destination_collection_id' => $match['id'],
                                'status'                    => 'success',
                                'error_message'             => null,
                            ]);
                            $updated++;
                        } else {
                            $row->update(['status' => 'failed', 'error_message' => json_encode(['patch'=>$patch,'response'=>$res])]);
                            $failed++;
                        }
                    } else {
                        $skipped++; // cannot create system one if not resolvable
                        $row->update(['status'=>'skipped','error_message'=>'System collection not resolvable for update.']);
                    }
                    continue;
                }
            }

            if ($dstIsV3) {
                $payload = $this->sanitizeCategoryForCreateV3($cat);
                $ensureSeoOnPayload($payload, $cat);

                // Prefer the row's existing destination id if present
                $targetId = $row->destination_collection_id ?: null;
                $exists   = false;

                if ($targetId) {
                    $detail = $getV3Detail($targetId);
                    $exists = !empty($detail['category']['id']);
                }

                if ($exists) {
                    // UPDATE
                    $patch = $payload; unset($patch['slug']);
                    $res = $patchV3($targetId, $patch);
                    if (isset($res['category']['id'])) {
                        $row->update([
                            'status'                  => 'success',
                            'error_message'           => null,
                            'source_collection_name'  => $cat['name'] ?? $row->source_collection_name,
                            'source_collection_slug'  => $row->source_collection_slug ?? ($cat['slug'] ?? null),
                        ]);
                        $updated++;
                    } else {
                        $row->update(['status' => 'failed', 'error_message' => json_encode(['patch'=>$patch,'response'=>$res])]);
                        $failed++;
                    }
                } else {
                    // Try to match by slug or name in target
                    $match = null;
                    if (!empty($cat['slug'])) $match = $v3IndexBySlug[strtolower($cat['slug'])] ?? null;
                    if (!$match && !empty($cat['name'])) $match = $v3IndexByName[strtolower($cat['name'])] ?? null;

                    if ($match && !empty($match['id'])) {
                        // UPDATE matched one
                        $patch = $payload; unset($patch['slug']);
                        $res = $patchV3($match['id'], $patch);
                        if (isset($res['category']['id'])) {
                            $row->update([
                                'destination_collection_id' => $match['id'],
                                'status'                    => 'success',
                                'error_message'             => null,
                                'source_collection_name'    => $cat['name'] ?? $row->source_collection_name,
                                'source_collection_slug'    => $row->source_collection_slug ?? ($cat['slug'] ?? null),
                            ]);
                            $updated++;
                        } else {
                            $row->update(['status' => 'failed', 'error_message' => json_encode(['patch'=>$patch,'response'=>$res])]);
                            $failed++;
                        }
                    } else {
                        // CREATE (also covers "deleted on target" recreate case)
                        $baseForSlug = $payload['slug'] ?? ($cat['slug'] ?? $payload['name'] ?? 'category');
                        $payload['slug'] = $this->makeUniqueSlug($baseForSlug, $existingSlugs);
                        $res = $this->createCategoryWithDedupeV3($toToken, $payload, $appNamespace, $treeKey, $existingSlugs);
                        if (isset($res['category']['id'])) {
                            $row->update([
                                'destination_collection_id' => $res['category']['id'],
                                'status'                    => 'success',
                                'error_message'             => null,
                                'source_collection_name'    => $cat['name'] ?? $row->source_collection_name,
                                'source_collection_slug'    => $row->source_collection_slug ?? ($cat['slug'] ?? null),
                            ]);
                            $recreated += ($targetId ? 1 : 0);
                            $created   += ($targetId ? 0 : 1);
                        } else {
                            $row->update(['status' => 'failed', 'error_message' => json_encode(['sent'=>['category'=>$payload], 'response'=>$res])]);
                            $failed++;
                        }
                    }
                }
            } else {
                // V1 target
                $targetId = $row->destination_collection_id ?: null;
                $exists   = false;

                if ($targetId) {
                    $detail = $v1Get($targetId);
                    $exists = !empty($detail['collection']['id']);
                }

                if ($exists) {
                    $patch = [
                        'name'        => $cat['name'] ?? $detail['collection']['name'] ?? '',
                        'description' => $cat['description'] ?? ($detail['collection']['description'] ?? null),
                        'visible'     => array_key_exists('visible',$cat) ? (bool)$cat['visible'] : ($detail['collection']['visible'] ?? true),
                    ];
                    $res = $patchV1($targetId, $patch);
                    if (isset($res['collection']['id'])) {
                        $row->update(['status'=>'success','error_message'=>null]);
                        $updated++;
                    } else {
                        $row->update(['status'=>'failed','error_message'=>json_encode(['patch'=>$patch,'response'=>$res])]);
                        $failed++;
                    }
                } else {
                    // try to match by slug or name
                    $match = null;
                    if (!empty($cat['slug'])) $match = $v1IndexBySlug[strtolower($cat['slug'])] ?? null;
                    if (!$match && !empty($cat['name'])) $match = $v1IndexByName[strtolower($cat['name'])] ?? null;

                    if ($match && !empty($match['id'])) {
                        $patch = [
                            'name'        => $cat['name'] ?? $match['name'] ?? '',
                            'description' => $cat['description'] ?? ($match['description'] ?? null),
                            'visible'     => array_key_exists('visible',$cat) ? (bool)$cat['visible'] : ($match['visible'] ?? true),
                        ];
                        $res = $patchV1($match['id'], $patch);
                        if (isset($res['collection']['id'])) {
                            $row->update([
                                'destination_collection_id' => $match['id'],
                                'status' => 'success',
                                'error_message' => null,
                            ]);
                            $updated++;
                        } else {
                            $row->update(['status'=>'failed','error_message'=>json_encode(['patch'=>$patch,'response'=>$res])]);
                            $failed++;
                        }
                    } else {
                        // CREATE (or RE-CREATE)
                        $res = $this->createCollectionInWixV1($toToken, [
                            'name'        => $cat['name'] ?? '',
                            'description' => $cat['description'] ?? null,
                            'visible'     => array_key_exists('visible',$cat) ? (bool)$cat['visible'] : true,
                        ]);
                        if (isset($res['collection']['id'])) {
                            $row->update([
                                'destination_collection_id' => $res['collection']['id'],
                                'status'                    => 'success',
                                'error_message'             => null,
                            ]);
                            $recreated += ($targetId ? 1 : 0);
                            $created   += ($targetId ? 0 : 1);
                        } else {
                            $row->update(['status'=>'failed','error_message'=>json_encode(['sent'=>$cat,'response'=>$res])]);
                            $failed++;
                        }
                    }
                }
            }
        }

        $summary = "Categories: created={$created}, updated={$updated}, recreated={$recreated}, failed={$failed}, skipped(system)={$skipped}.";
        WixHelper::log('Auto Category Migration', "Done. {$summary}", $failed ? 'warn' : 'success');

        if ($failed && !$created && !$updated && !$recreated) return back()->with('error', $summary);
        if ($failed) return back()->with('warning', $summary);
        return back()->with('success', $summary);
    }




    // ========================================================= Manual Migrator =========================================================
    // =========================================================
    // Export (auto-detect V1 vs V3) — append-only pending rows
    // =========================================================
    public function export(WixStore $store)
    {
        $userId      = Auth::id() ?: 1;
        $fromStoreId = $store->instance_id;

        WixHelper::log('Export Product Categories', "Start: {$store->store_name} ({$fromStoreId})", 'info');

        $accessToken = WixHelper::getAccessToken($fromStoreId);
        if (!$accessToken) {
            WixHelper::log('Export Product Categories', "Unauthorized: Could not get access token for instanceId: {$fromStoreId}.", 'error');
            return back()->with('error', 'You are not authorized to access.');
        }

        $catalogVersion = WixHelper::getCatalogVersion($accessToken);
        $isV3 = $this->isCatalogV3($catalogVersion);

        if ($isV3) {
            // -------- V3 (Categories API)
            $appNamespace = $this->treeAppNamespaceFromRequest();
            $treeKey      = $this->treeKeyFromRequest();
            // Ensure DESCRIPTION is requested so V3 returns description when possible
            $fields       = $this->exportFieldsV3FromRequest() ?: $this->defaultExportFieldsV3;
            if (!in_array('DESCRIPTION', $fields, true)) $fields[] = 'DESCRIPTION';

            // 1) Try QUERY (with fields)
            $result = $this->queryCategoriesV3All($accessToken, $fields, $appNamespace, $treeKey, true);

            // 2) Fallbacks
            if (($result['failed'] ?? false) || empty($result['categories'])) {
                WixHelper::log('Export Product Categories', 'V3 QUERY failed/empty; falling back to SEARCH (with fields).', 'warn');
                $result = $this->searchCategoriesV3All($accessToken, $fields, $appNamespace, $treeKey, true);
            }
            if ((($result['failed'] ?? false) || empty($result['categories'])) && !empty($fields)) {
                WixHelper::log('Export Product Categories', 'SEARCH failed/empty; retrying QUERY without fields.', 'warn');
                $result = $this->queryCategoriesV3All($accessToken, [], $appNamespace, $treeKey, true);
            }
            if (!isset($result['categories']) || !is_array($result['categories'])) {
                WixHelper::log('Export Product Categories', 'V3 API error after fallbacks.', 'error');
                return response()->json(['error' => 'Failed to fetch categories from Wix (V3).'], 500);
            }

            $categories = $result['categories'];

            // 3) Enrich missing descriptions with GET /categories/{id}
            foreach ($categories as &$cat) {
                // if description is absent or empty, try detail fetch
                if ((!array_key_exists('description', $cat) || trim((string)$cat['description']) === '') && !empty($cat['id'])) {
                    $detail = $this->getCategoryByIdV3($accessToken, $cat['id'], $appNamespace, $treeKey);
                    $full   = $detail['category'] ?? [];
                    if (!empty($full['description'])) {
                        $cat['description'] = $full['description'];
                    }
                    // keep other fields as-is
                }
            }
            unset($cat);

            // Oldest-first if dateCreated present
            usort($categories, function ($a, $b) {
                $da = (int)($a['dateCreated'] ?? PHP_INT_MAX);
                $db = (int)($b['dateCreated'] ?? PHP_INT_MAX);
                return $da <=> $db;
            });

            // Append-only pending rows
            $saved = 0;
            foreach ($categories as $cat) {
                $srcId = $cat['id'] ?? null;
                if (!$srcId) continue;

                WixCollectionMigration::create([
                    'user_id'                     => $userId,
                    'from_store_id'               => $fromStoreId,
                    'to_store_id'                 => null,
                    'source_collection_id'        => $srcId,
                    'source_collection_slug'      => $cat['slug'] ?? null,
                    'source_collection_name'      => $cat['name'] ?? null,
                    'destination_collection_id'   => null,
                    'status'                      => 'pending',
                    'error_message'               => null,
                ]);
                $saved++;
            }
            WixHelper::log('Export Product Categories', "Saved {$saved} pending row(s) (V3).", 'success');

            return response()->streamDownload(function () use ($categories, $fromStoreId, $appNamespace, $treeKey) {
                echo json_encode([
                    'meta' => [
                        'from_store_id'   => $fromStoreId,
                        'tree'            => ['appNamespace' => $appNamespace, 'treeKey' => $treeKey],
                        'catalog_version' => 'V3',
                        'generated_at'    => now()->toIso8601String(),
                    ],
                    'categories' => $categories,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            }, 'categories.json', ['Content-Type' => 'application/json']);

        } else {
            // -------- V1
            // Use stores/v1 (not reader) to align with your curls and better field coverage
            $collectionsResp = $this->getCollectionsFromWixV1($accessToken);
            if (!isset($collectionsResp['collections']) || !is_array($collectionsResp['collections'])) {
                WixHelper::log('Export Product Categories', 'API error fetching V1 collections.', 'error');
                return response()->json(['error' => 'Failed to fetch collections from Wix (V1).'], 500);
            }

            $collections = $collectionsResp['collections'];

            // Enrich each with GET /stores/v1/collections/{id}?includeNumberOfProducts=true if description is missing
            foreach ($collections as &$col) {
                if ((!array_key_exists('description', $col) || trim((string)$col['description']) === '') && !empty($col['id'])) {
                    $detail = $this->getCollectionByIdV1($accessToken, $col['id'], true);
                    $full   = $detail['collection'] ?? [];
                    if (!empty($full['description'])) {
                        $col['description'] = $full['description'];
                    }
                }
            }
            unset($col);

            // Oldest-first if dateCreated present
            usort($collections, function ($a, $b) {
                $da = (int)($a['dateCreated'] ?? PHP_INT_MAX);
                $db = (int)($b['dateCreated'] ?? PHP_INT_MAX);
                return $da <=> $db;
            });

            // Append pending rows
            $saved = 0;
            foreach ($collections as $col) {
                $srcId = $col['id'] ?? null;
                if (!$srcId) continue;

                WixCollectionMigration::create([
                    'user_id'                     => $userId,
                    'from_store_id'               => $fromStoreId,
                    'to_store_id'                 => null,
                    'source_collection_id'        => $srcId,
                    'source_collection_slug'      => $col['slug'] ?? null,
                    'source_collection_name'      => $col['name'] ?? null,
                    'destination_collection_id'   => null,
                    'status'                      => 'pending',
                    'error_message'               => null,
                ]);
                $saved++;
            }
            WixHelper::log('Export Product Categories', "Saved {$saved} pending row(s) (V1).", 'success');

            return response()->streamDownload(function () use ($collections, $fromStoreId) {
                echo json_encode([
                    'meta' => [
                        'from_store_id'   => $fromStoreId,
                        'catalog_version' => 'V1',
                        'generated_at'    => now()->toIso8601String(),
                    ],
                    'collections' => $collections,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            }, 'collections.json', ['Content-Type' => 'application/json']);
        }
    }


    // =========================================================
    // Import (auto-detect V1 vs V3 for TARGET) — claim/resolve pattern
    // =========================================================
    public function import(Request $request, WixStore $store)
{
    $userId    = Auth::id() ?: 1;
    $toStoreId = $store->instance_id;

    WixHelper::log('Import Product Categories', "Start: {$store->store_name} ({$toStoreId})", 'info');

    $accessToken = WixHelper::getAccessToken($toStoreId);
    if (!$accessToken) {
        WixHelper::log('Import Product Categories', "Unauthorized: Could not get access token for instanceId: {$toStoreId}.", 'error');
        return back()->with('error', 'Could not get Wix access token.');
    }

    if (!$request->hasFile('categories_json')) {
        WixHelper::log('Import Product Categories', "No file uploaded for store: {$store->store_name}.", 'error');
        return back()->with('error', 'No file uploaded.');
    }

    $json    = file_get_contents($request->file('categories_json')->getRealPath());
    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        WixHelper::log('Import Product Categories', 'Invalid JSON uploaded.', 'error');
        return back()->with('error', 'Invalid JSON file.');
    }

    $catalogVersion = WixHelper::getCatalogVersion($accessToken);
    $isV3 = $this->isCatalogV3($catalogVersion);

    // Resolve from_store_id (request > meta > legacy)
    $explicitFromStoreId = $request->input('from_store_id')
        ?: ($decoded['meta']['from_store_id'] ?? ($decoded['from_store_id'] ?? null));

    // ---- small helpers ----
    $appNamespace = $this->treeAppNamespaceFromRequest();
    $treeKey      = $this->treeKeyFromRequest();

    $buildSeoFromDesc = function (?string $desc): ?array {
        $desc = trim(preg_replace('/\s+/u', ' ', strip_tags((string)$desc)));
        if ($desc === '') return null;
        $desc = mb_substr($desc, 0, 300, 'UTF-8');
        return [
            'tags' => [[
                'type'  => 'meta',
                'props' => [
                    'fields' => [
                        'name'    => ['stringValue' => 'description'],
                        'content' => ['stringValue' => $desc],
                    ]
                ],
            ]]
        ];
    };
    $ensureSeoOnPayload = function (array &$payload, array $source) use ($buildSeoFromDesc) {
        $hasMeta = false;
        if (isset($payload['seoData']['tags']) && is_array($payload['seoData']['tags'])) {
            foreach ($payload['seoData']['tags'] as $t) {
                if (($t['type'] ?? '') === 'meta'
                    && strtolower($t['props']['fields']['name']['stringValue'] ?? '') === 'description') {
                    $hasMeta = true; break;
                }
            }
        }
        if (!$hasMeta) {
            $seo = $buildSeoFromDesc($payload['description'] ?? ($source['description'] ?? null));
            if ($seo) $payload['seoData'] = $seo;
        }
    };

    $getV3Detail = function (string $id) use ($accessToken, $appNamespace, $treeKey) {
        return $this->getCategoryByIdV3($accessToken, $id, $appNamespace, $treeKey);
    };
    $patchV3 = function (string $id, array $partial) use ($accessToken, $appNamespace, $treeKey, $getV3Detail) {
        $detail = $getV3Detail($id);
        $rev = $detail['category']['revision'] ?? null;
        if ($rev === null) return ['error' => 'Missing revision'];
        $body = [
            'category'      => array_merge(['revision' => (string)$rev], $partial),
            'treeReference' => $this->treeRefArray($appNamespace, $treeKey),
        ];
        $resp = Http::withHeaders([
            'Authorization' => $this->ensureBearer($accessToken),
            'Content-Type'  => 'application/json'
        ])->patch("https://www.wixapis.com/categories/v1/categories/{$id}", $body);
        return $resp->json() ?: [];
    };
    $patchV1 = function (string $id, array $partial) use ($accessToken) {
        $resp = Http::withHeaders([
            'Authorization' => $this->ensureBearer($accessToken),
            'Content-Type'  => 'application/json'
        ])->patch("https://www.wixapis.com/stores/v1/collections/{$id}", ['collection' => $partial]);
        return $resp->json() ?: [];
    };
    $v1Get = function (string $id) use ($accessToken) {
        return $this->getCollectionByIdV1($accessToken, $id, false);
    };

    // Sorter
    $createdAtMillis = function (array $item): int {
        foreach (['createdDate','dateCreated','createdAt','creationDate','date_created'] as $k) {
            if (array_key_exists($k, $item)) {
                $v = $item[$k];
                if (is_numeric($v)) return (int)$v;
                if (is_string($v)) { $ts = strtotime($v); if ($ts !== false) return $ts * 1000; }
            }
        }
        foreach ([['audit','createdDate'], ['audit','dateCreated'], ['metadata','createdDate'], ['metadata','dateCreated']] as $p) {
            $cur = $this->getNested($item, $p);
            if ($cur !== null) {
                if (is_numeric($cur)) return (int)$cur;
                if (is_string($cur))  { $ts = strtotime($cur); if ($ts !== false) return $ts * 1000; }
            }
        }
        return PHP_INT_MAX;
    };

    if ($isV3) {
        $normalized = $this->normalizeUploadedCategories($decoded);
        if ($normalized === null) {
            WixHelper::log('Import Product Categories', 'Invalid JSON for V3 import.', 'error');
            return back()->with('error', 'Invalid JSON. Provide V3 export (meta.from_store_id + categories[]) or V1 export (from_store_id + collections[]).');
        }
        if (!$explicitFromStoreId) $explicitFromStoreId = $normalized[0];
        [$fromStoreId, $categories] = $normalized;

        usort($categories, fn($a,$b) => $createdAtMillis($a) <=> $createdAtMillis($b));

        // Build target indexes
        $scan = $this->queryCategoriesV3All($accessToken, ['DESCRIPTION','SEO_DATA'], $appNamespace, $treeKey, true);
        if (empty($scan['categories'])) {
            $scan = $this->searchCategoriesV3All($accessToken, ['DESCRIPTION','SEO_DATA'], $appNamespace, $treeKey, true);
        }
        $bySlug = []; $byName = [];
        foreach (($scan['categories'] ?? []) as $c) {
            if (!empty($c['slug'])) $bySlug[strtolower($c['slug'])] = $c;
            if (!empty($c['name'])) $byName[strtolower($c['name'])] = $c;
        }
        $existingSlugs = $this->getExistingSlugsV3($accessToken, $appNamespace, $treeKey);
        $allProductsIdV3 = $this->getAllProductsCategoryIdV3($accessToken);

        $created = 0; $updated = 0; $recreated = 0; $failed = 0;

        foreach ($categories as $cat) {
            $sourceId = $cat['id'] ?? null;
            if (!$sourceId) continue;

            // Get or create DB row for this mapping
            $row = WixCollectionMigration::firstOrCreate(
                [
                    'user_id'              => $userId,
                    'from_store_id'        => $explicitFromStoreId,
                    'to_store_id'          => $toStoreId,
                    'source_collection_id' => $sourceId,
                ],
                [
                    'source_collection_slug'    => $cat['slug'] ?? null,
                    'source_collection_name'    => $cat['name'] ?? null,
                    'destination_collection_id' => null,
                    'status'                    => 'pending',
                    'error_message'             => null,
                ]
            );

            // Special: All Products -> update
            if ($this->shouldSkipSystemCategory($cat) && $allProductsIdV3) {
                $patch = $this->sanitizeCategoryForCreateV3($cat);
                unset($patch['slug']);
                $ensureSeoOnPayload($patch, $cat);

                $res = $patchV3($allProductsIdV3, $patch);
                if (isset($res['category']['id'])) {
                    $row->update([
                        'destination_collection_id' => $allProductsIdV3,
                        'status'                    => 'success',
                        'error_message'             => null,
                    ]);
                    $updated++;
                } else {
                    $row->update(['status'=>'failed','error_message'=>json_encode(['patch'=>$patch,'response'=>$res])]);
                    $failed++;
                }
                continue;
            }
            if ($this->shouldSkipSystemCategory($cat)) continue;

            $payload = $this->sanitizeCategoryForCreateV3($cat);
            $ensureSeoOnPayload($payload, $cat);

            // Prefer DB destination id if present
            $targetId = $row->destination_collection_id ?: null;
            $exists   = false;
            if ($targetId) {
                $detail = $getV3Detail($targetId);
                $exists = !empty($detail['category']['id']);
            }

            if ($exists) {
                $patch = $payload; unset($patch['slug']);
                $res = $patchV3($targetId, $patch);
                if (isset($res['category']['id'])) {
                    $row->update(['status'=>'success','error_message'=>null]);
                    $updated++;
                } else {
                    $row->update(['status'=>'failed','error_message'=>json_encode(['patch'=>$patch,'response'=>$res])]);
                    $failed++;
                }
            } else {
                // Try to match by slug/name before creating
                $match = null;
                if (!empty($cat['slug'])) $match = $bySlug[strtolower($cat['slug'])] ?? null;
                if (!$match && !empty($cat['name'])) $match = $byName[strtolower($cat['name'])] ?? null;

                if ($match && !empty($match['id'])) {
                    $patch = $payload; unset($patch['slug']);
                    $res = $patchV3($match['id'], $patch);
                    if (isset($res['category']['id'])) {
                        $row->update([
                            'destination_collection_id' => $match['id'],
                            'status' => 'success',
                            'error_message' => null,
                        ]);
                        $updated++;
                    } else {
                        $row->update(['status'=>'failed','error_message'=>json_encode(['patch'=>$patch,'response'=>$res])]);
                        $failed++;
                    }
                } else {
                    // CREATE / RE-CREATE
                    $baseForSlug = $payload['slug'] ?? ($cat['slug'] ?? $payload['name'] ?? 'category');
                    $payload['slug'] = $this->makeUniqueSlug($baseForSlug, $existingSlugs);
                    $res = $this->createCategoryWithDedupeV3($accessToken, $payload, $appNamespace, $treeKey, $existingSlugs);
                    if (isset($res['category']['id'])) {
                        $row->update([
                            'destination_collection_id' => $res['category']['id'],
                            'status'                    => 'success',
                            'error_message'             => null,
                        ]);
                        $recreated += ($targetId ? 1 : 0);
                        $created   += ($targetId ? 0 : 1);
                    } else {
                        $row->update(['status'=>'failed','error_message'=>json_encode(['sent'=>$payload,'response'=>$res])]);
                        $failed++;
                    }
                }
            }
        }

        $msg = "V3 import: created={$created}, updated={$updated}, recreated={$recreated}, failed={$failed}";
        WixHelper::log('Import Product Categories', "Done. {$msg}", $failed ? 'warn' : 'success');
        return $failed && !$created && !$updated && !$recreated
            ? back()->with('error', $msg)
            : ($failed ? back()->with('warning', $msg) : back()->with('success', $msg));
    }

    // ---------- V1 target (upsert + recreate) ----------
    if (!isset($decoded['collections']) || !is_array($decoded['collections'])) {
        WixHelper::log('Import Product Categories', 'Invalid JSON structure for V1 import. Expected key: collections[].', 'error');
        return back()->with('error', 'Invalid JSON structure. Required key: collections[].');
    }
    if (!$explicitFromStoreId) {
        $explicitFromStoreId = $decoded['from_store_id'] ?? null;
        if (!$explicitFromStoreId) {
            return back()->with('error', 'from_store_id is required. Provide it as a field or include meta.from_store_id / from_store_id in the JSON.');
        }
    }

    $collections = $decoded['collections'];
    usort($collections, fn($a,$b) => $createdAtMillis($a) <=> $createdAtMillis($b));

    $scan = $this->getCollectionsFromWixV1($accessToken);
    $bySlug = []; $byName = [];
    foreach (($scan['collections'] ?? []) as $c) {
        if (!empty($c['slug'])) $bySlug[strtolower($c['slug'])] = $c;
        if (!empty($c['name'])) $byName[strtolower($c['name'])] = $c;
    }

    $created = 0; $updated = 0; $recreated = 0; $failed = 0;

    foreach ($collections as $col) {
        $sourceId = $col['id'] ?? null;
        if (!$sourceId) continue;

        $row = WixCollectionMigration::firstOrCreate(
            [
                'user_id'              => $userId,
                'from_store_id'        => $explicitFromStoreId,
                'to_store_id'          => $toStoreId,
                'source_collection_id' => $sourceId,
            ],
            [
                'source_collection_slug'    => $col['slug'] ?? null,
                'source_collection_name'    => $col['name'] ?? null,
                'destination_collection_id' => null,
                'status'                    => 'pending',
                'error_message'             => null,
            ]
        );

        $targetId = $row->destination_collection_id ?: null;
        $exists   = false;
        if ($targetId) {
            $detail = $v1Get($targetId);
            $exists = !empty($detail['collection']['id']);
        }

        if ($exists) {
            $patch = [
                'name'        => $col['name'] ?? $detail['collection']['name'] ?? '',
                'description' => $col['description'] ?? ($detail['collection']['description'] ?? null),
                'visible'     => array_key_exists('visible',$col) ? (bool)$col['visible'] : ($detail['collection']['visible'] ?? true),
            ];
            $res = $patchV1($targetId, $patch);
            if (isset($res['collection']['id'])) {
                $row->update(['status'=>'success','error_message'=>null]);
                $updated++;
            } else {
                $row->update(['status'=>'failed','error_message'=>json_encode(['patch'=>$patch,'response'=>$res])]);
                $failed++;
            }
        } else {
            // Try match by slug/name
            $match = null;
            if (!empty($col['slug'])) $match = $bySlug[strtolower($col['slug'])] ?? null;
            if (!$match && !empty($col['name'])) $match = $byName[strtolower($col['name'])] ?? null;

            if ($match && !empty($match['id'])) {
                $patch = [
                    'name'        => $col['name'] ?? $match['name'] ?? '',
                    'description' => $col['description'] ?? ($match['description'] ?? null),
                    'visible'     => array_key_exists('visible',$col) ? (bool)$col['visible'] : ($match['visible'] ?? true),
                ];
                $res = $patchV1($match['id'], $patch);
                if (isset($res['collection']['id'])) {
                    $row->update([
                        'destination_collection_id' => $match['id'],
                        'status'                    => 'success',
                        'error_message'             => null,
                    ]);
                    $updated++;
                } else {
                    $row->update(['status'=>'failed','error_message'=>json_encode(['patch'=>$patch,'response'=>$res])]);
                    $failed++;
                }
            } else {
                // CREATE / RE-CREATE
                $res = $this->createCollectionInWixV1($accessToken, [
                    'name'        => $col['name'] ?? '',
                    'description' => $col['description'] ?? null,
                    'visible'     => array_key_exists('visible',$col) ? (bool)$col['visible'] : true,
                ]);
                if (isset($res['collection']['id'])) {
                    $row->update([
                        'destination_collection_id' => $res['collection']['id'],
                        'status'                    => 'success',
                        'error_message'             => null,
                    ]);
                    $recreated += ($targetId ? 1 : 0);
                    $created   += ($targetId ? 0 : 1);
                } else {
                    $row->update(['status'=>'failed','error_message'=>json_encode(['sent'=>$col,'response'=>$res])]);
                    $failed++;
                }
            }
        }
    }

    $msg = "V1 import: created={$created}, updated={$updated}, recreated={$recreated}, failed={$failed}";
    WixHelper::log('Import Product Categories', "Done. {$msg}", $failed ? 'warn' : 'success');
    return $failed && !$created && !$updated && !$recreated
        ? back()->with('error', $msg)
        : ($failed ? back()->with('warning', $msg) : back()->with('success', $msg));
}



    // =========================================================
    // Helpers: request-driven overrides for V3 tree + fields
    // =========================================================
    protected function treeAppNamespaceFromRequest(): string
    {
        $val = request()->query('appNamespace', $this->defaultAppNamespace);
        return trim($val) !== '' ? $val : $this->defaultAppNamespace;
    }

    protected function treeKeyFromRequest(): ?string
    {
        $tk = request()->query('treeKey', $this->defaultTreeKey);
        return ($tk !== null && trim((string)$tk) !== '') ? $tk : null;
    }

    protected function exportFieldsV3FromRequest(): array
    {
        $fromQuery = request()->query('fields');
        if ($fromQuery) {
            return array_values(array_filter(array_map('trim', explode(',', $fromQuery))));
        }
        return [];
    }

    // =========================================================
    // V3 Utilities (Categories API)
    // =========================================================

    protected function isCatalogV3(?string $catalogVersion): bool
    {
        if (!$catalogVersion) return false;
        $v = strtoupper(preg_replace('/[^A-Z0-9_]/', '', $catalogVersion));
        return str_contains($v, 'V3');
    }

    protected function queryCategoriesV3All(
        string $accessToken,
        array $fields,
        string $appNamespace,
        ?string $treeKey,
        bool $returnNonVisible = true
    ): array {
        $all = [];
        $cursor = null;
        $pages = 0;

        do {
            $pages++;

            // Build a paging object once; put it under BOTH keys for maximum compatibility
            $paging = ['limit' => 1000];
            if ($cursor) $paging['cursor'] = $cursor;

            $body = [
                'query' => [
                    // Some tenants expect this:
                    'paging' => $paging,
                    // Others accept this (your original):
                    'cursorPaging' => $paging,
                ],
                'treeReference' => $this->treeRefArray($appNamespace, $treeKey),
            ];

            if (!empty($fields)) {
                $body['fields'] = array_values(array_unique($fields));
            }
            if ($returnNonVisible) {
                $body['returnNonVisibleCategories'] = true;
            }

            $resp = \Illuminate\Support\Facades\Http::withHeaders([
                'Authorization' => $this->ensureBearer($accessToken),
                'Content-Type'  => 'application/json'
            ])->post('https://www.wixapis.com/categories/v1/categories/query', $body);

            WixHelper::log('Export Product Categories', 'V3 QUERY page response received.', $resp->ok() ? 'debug' : 'warn');

            if (!$resp->ok()) {
                // Mark as failed so caller can fall back to SEARCH
                return ['categories' => [], 'error' => $resp->json(), 'failed' => true, 'pages' => $pages];
            }

            $json   = $resp->json() ?: [];
            $items  = $json['categories'] ?? [];
            if (!is_array($items)) $items = [];

            $all = array_merge($all, $items);

            // Handle both pagination shapes
            $cursor = $json['pagingMetadata']['cursors']['next']
                ?? ($json['pageInfo']['nextCursor'] ?? null);

        } while ($cursor);

        return ['categories' => $all, 'pages' => $pages];
    }


    protected function searchCategoriesV3All(
        string $accessToken,
        array $fields,
        string $appNamespace,
        ?string $treeKey,
        bool $returnNonVisible = true,
        ?string $expression = null
    ): array {
        $all = [];
        $cursor = null;
        $pages = 0;

        do {
            $pages++;

            $paging = ['limit' => 1000];
            if ($cursor) $paging['cursor'] = $cursor;

            $searchNode = [
                'paging'       => $paging,
                'cursorPaging' => $paging,
            ];
            if ($expression !== null && trim($expression) !== '') {
                $searchNode['search'] = ['expression' => $expression];
            }

            $body = [
                'search'        => $searchNode,
                'treeReference' => $this->treeRefArray($appNamespace, $treeKey),
            ];

            if (!empty($fields)) {
                $body['fields'] = array_values(array_unique($fields));
            }
            if ($returnNonVisible) {
                $body['returnNonVisibleCategories'] = true;
            }

            $resp = Http::withHeaders([
                'Authorization' => $this->ensureBearer($accessToken),
                'Content-Type'  => 'application/json'
            ])->post('https://www.wixapis.com/categories/v1/categories/search', $body);

            WixHelper::log('Export Product Categories', 'V3 SEARCH page response received.', $resp->ok() ? 'debug' : 'warn');

            if (!$resp->ok()) {
                return ['categories' => [], 'error' => $resp->json(), 'failed' => true, 'pages' => $pages];
            }

            $json   = $resp->json() ?: [];
            $items  = $json['categories'] ?? [];
            if (!is_array($items)) $items = [];

            $all = array_merge($all, $items);

            $cursor = $json['pagingMetadata']['cursors']['next']
                ?? ($json['pageInfo']['nextCursor'] ?? null);

        } while ($cursor);

        return ['categories' => $all, 'pages' => $pages];
    }

    protected function getCategoryByIdV3(string $accessToken, string $categoryId, string $appNamespace, ?string $treeKey): array
    {
        // Build query string: treeReference.appNamespace and optional treeKey
        $qs = http_build_query(array_filter([
            'treeReference.appNamespace' => $appNamespace,
            'treeReference.treeKey'      => $treeKey,
        ], fn($v) => $v !== null && $v !== ''));

        $url = "https://www.wixapis.com/categories/v1/categories/{$categoryId}" . ($qs ? "?{$qs}" : '');

        $resp = Http::withHeaders([
            'Authorization' => $this->ensureBearer($accessToken),
            'Content-Type'  => 'application/json'
        ])->get($url);

        return $resp->json() ?: [];
    }



    protected function createCategoryInWixV3(string $accessToken, array $category, string $appNamespace, ?string $treeKey): array
    {
        $body = ['category' => $category, 'treeReference' => $this->treeRefArray($appNamespace, $treeKey)];

        $response = Http::withHeaders([
            'Authorization' => $this->ensureBearer($accessToken),
            'Content-Type'  => 'application/json'
        ])->post('https://www.wixapis.com/categories/v1/categories', $body);

        return $response->json();
    }

    protected function treeRefArray(string $appNamespace, ?string $treeKey): array
    {
        $ref = ['appNamespace' => $appNamespace];
        if ($treeKey !== null) $ref['treeKey'] = $treeKey;
        return $ref;
    }

    protected function sanitizeCategoryForCreateV3(array $category): array
    {
        $payload = $category;

        unset(
            $payload['id'],
            $payload['managingAppId'],
            $payload['extendedFields'],
            $payload['treeReference']
        );

        if (isset($payload['parentCategory'])) unset($payload['parentCategory']);

        $payload['name'] = trim($payload['name'] ?? '');

        if (isset($payload['image'])) {
            $img = $payload['image'];
            $clean = [];
            if (!empty($img['id']))      $clean['id'] = $img['id'];
            if (!empty($img['url']))     $clean['url'] = $img['url'];
            if (!empty($img['altText'])) $clean['altText'] = $img['altText'];
            if ($clean) $payload['image'] = $clean; else unset($payload['image']);
        }

        if (isset($payload['slug'])) {
            $payload['slug'] = trim($payload['slug']);
            if ($payload['slug'] === '') unset($payload['slug']);
        }

        // NEW: clean + enforce Wix limit (maxLength 600) so it doesn't get dropped
        if (isset($payload['description'])) {
            // strip tags, collapse whitespace
            $desc = trim(preg_replace('/\s+/u', ' ', strip_tags((string)$payload['description'])));
            if ($desc === '') {
                unset($payload['description']);
            } else {
                // hard cap at 600 chars as per Wix schema
                if (mb_strlen($desc, 'UTF-8') > 600) {
                    $desc = mb_substr($desc, 0, 600, 'UTF-8');
                }
                $payload['description'] = $desc;
            }
        }

        return $payload;
    }


    protected function getAllProductsCategoryIdV3(string $accessToken): ?string
    {
        $response = Http::withHeaders([
            'Authorization' => $this->ensureBearer($accessToken),
            'Content-Type'  => 'application/json'
        ])->get('https://www.wixapis.com/stores/v3/all-products-category');

        if ($response->ok()) {
            $json = $response->json();
            return $json['categoryId'] ?? null;
        }
        return null;
    }

    protected function getExistingSlugsV3(string $accessToken, ?string $appNamespace, ?string $treeKey): array
    {
        $set = [];
        $res = $this->queryCategoriesV3All(
            $accessToken, [], $appNamespace ?: $this->defaultAppNamespace, $treeKey, true
        );
        if (empty($res['categories'])) {
            $res = $this->searchCategoriesV3All(
                $accessToken, [], $appNamespace ?: $this->defaultAppNamespace, $treeKey, true
            );
        }
        foreach (($res['categories'] ?? []) as $cat) {
            if (!empty($cat['slug'])) $set[strtolower($cat['slug'])] = true;
        }
        return $set;
    }

    protected function shouldSkipSystemCategory(array $category): bool
    {
        $id   = strtolower((string)($category['id'] ?? ''));
        $slug = strtolower((string)($category['slug'] ?? ''));
        $name = strtolower((string)($category['name'] ?? ''));

        if ($id && in_array($id, array_map('strtolower', $this->systemCategoryIds), true)) return true;
        if ($slug && in_array($slug, $this->systemCategorySlugs, true)) return true;
        if ($name && in_array($name, $this->systemCategoryNames, true)) return true;
        return false;
    }

    protected function makeUniqueSlug(string $base, array &$existing): string
    {
        $base = trim($base) !== '' ? Str::slug($base) : 'category';
        $candidate = $base;
        $i = 2;
        while (isset($existing[strtolower($candidate)])) {
            $candidate = $base . '-' . $i;
            $i++;
            if ($i > 9999) break;
        }
        $existing[strtolower($candidate)] = true;
        return $candidate;
    }

    protected function createCategoryWithDedupeV3(
        string $accessToken,
        array $payloadCategory,
        string $appNamespace,
        ?string $treeKey,
        array &$existingSlugs,
        int $maxRetries = 3
    ): array {
        $attempt = 0;
        do {
            $result = $this->createCategoryInWixV3($accessToken, $payloadCategory, $appNamespace, $treeKey);
            $attempt++;

            if (isset($result['category']['id'])) {
                return $result;
            }

            $code = $result['details']['applicationError']['code'] ?? null;
            if ($code === 'DUPLICATE_SLUG') {
                $current = $payloadCategory['slug'] ?? ($payloadCategory['name'] ?? 'category');
                $payloadCategory['slug'] = $this->makeUniqueSlug($current, $existingSlugs);
                continue;
            }

            return $result;

        } while ($attempt <= $maxRetries);

        return $result;
    }

    // =========================================================
    // V1 Utilities
    // =========================================================
    protected function getCollectionsFromWixV1(string $accessToken): array
    {
        // Mirrors: POST https://www.wixapis.com/stores/v1/collections/query
        $body = ['query' => new \stdClass()];

        $response = Http::withHeaders([
            'Authorization' => $this->ensureBearer($accessToken),
            'Content-Type'  => 'application/json'
        ])->post('https://www.wixapis.com/stores/v1/collections/query', $body);

        WixHelper::log('Export Product Categories', 'Wix API response received for collections query (V1).', 'debug');

        return $response->json() ?: [];
    }


    protected function createCollectionInWixV1(string $accessToken, array $collection): array
    {
        $body = ['collection' => $collection];

        $response = Http::withHeaders([
            'Authorization' => $this->ensureBearer($accessToken),
            'Content-Type'  => 'application/json'
        ])->post('https://www.wixapis.com/stores/v1/collections', $body);

        return $response->json();
    }

    /**
     * Fetch a single V1 collection (full fields incl. description) by ID.
     */
    protected function getCollectionByIdV1(string $accessToken, string $collectionId, bool $includeNumberOfProducts = true): array
    {
        $qs = $includeNumberOfProducts ? '?includeNumberOfProducts=true' : '';
        $url = "https://www.wixapis.com/stores/v1/collections/{$collectionId}{$qs}";

        $resp = Http::withHeaders([
            'Authorization' => $this->ensureBearer($accessToken),
            'Content-Type'  => 'application/json'
        ])->get($url);

        return $resp->json() ?: [];
    }



    // =========================================================
    // Normalization for uploaded files (V3 importer)
    // =========================================================
    protected function normalizeUploadedCategories(array $decoded): ?array
    {
        $metaFrom = $decoded['meta']['from_store_id'] ?? null;
        $topFrom  = $decoded['from_store_id'] ?? null;

        // --- V3: categories[] present
        if (isset($decoded['categories']) && is_array($decoded['categories'])) {
            $from = $metaFrom ?? $topFrom;
            if ($from !== null) {
                return [$from, $decoded['categories']];
            }
        }

        // --- V1: collections[] present (with top-level from_store_id)
        if (isset($decoded['collections']) && is_array($decoded['collections']) && $topFrom !== null) {
            $categories = array_map(function ($col) {
                $cat = [
                    'id'   => $col['id']   ?? null,
                    'name' => $col['name'] ?? '',
                ];
                if (!empty($col['slug']))        $cat['slug'] = $col['slug'];
                if (!empty($col['description'])) $cat['description'] = $col['description'];
                if (array_key_exists('visible', $col)) $cat['visible'] = (bool)$col['visible'];
                if ($img = $this->extractImageFromV1Collection($col)) $cat['image'] = $img;
                return $cat;
            }, $decoded['collections']);

            return [$topFrom, $categories];
        }

        // --- V1 (meta-wrapped): collections[] + meta.from_store_id
        if (isset($decoded['collections']) && is_array($decoded['collections']) && $metaFrom !== null) {
            $categories = array_map(function ($col) {
                $cat = [
                    'id'   => $col['id']   ?? null,
                    'name' => $col['name'] ?? '',
                ];
                if (!empty($col['slug']))        $cat['slug'] = $col['slug'];
                if (!empty($col['description'])) $cat['description'] = $col['description'];
                if (array_key_exists('visible', $col)) $cat['visible'] = (bool)$col['visible'];
                if ($img = $this->extractImageFromV1Collection($col)) $cat['image'] = $img;
                return $cat;
            }, $decoded['collections']);

            return [$metaFrom, $categories];
        }

        // --- Fallback: hybrid { from_store_id, categories: [...] }
        if ($topFrom !== null && isset($decoded['categories']) && is_array($decoded['categories'])) {
            return [$topFrom, $decoded['categories']];
        }

        return null;
    }

    // =========================================================
    // V1 media -> V3 image helpers
    // =========================================================
    protected function getNested(array $arr, array $path)
    {
        $cur = $arr;
        foreach ($path as $key) {
            if (is_int($key)) {
                if (!isset($cur[$key])) return null;
                $cur = $cur[$key];
            } else {
                if (!isset($cur[$key])) return null;
                $cur = $cur[$key];
            }
        }
        return $cur;
    }

    protected function extractImageFromV1Collection(array $col): ?array
    {
        $candidates = [
            ['media','mainMedia','image','url'],
            ['media','mainMedia','thumbnail','url'],
            ['media','items',0,'image','url'],
            ['media','items',0,'thumbnail','url'],
        ];

        foreach ($candidates as $path) {
            $url = $this->getNested($col, $path);
            if (is_string($url) && trim($url) !== '') {
                $img = ['url' => $url];
                if (!empty($col['name'])) $img['altText'] = $col['name'];
                return $img;
            }
        }
        return null;
    }

    // =========================================================
    // Misc helpers
    // =========================================================
    protected function ensureBearer(string $token): string
    {
        return preg_match('/^Bearer\s+/i', $token) ? $token : ('Bearer '.$token);
    }

    protected function getExistingSlugsV1(string $accessToken): array
    {
        $set = [];
        $res = $this->getCollectionsFromWixV1($accessToken);
        foreach (($res['collections'] ?? []) as $col) {
            if (!empty($col['slug'])) {
                $set[strtolower($col['slug'])] = true;
            }
        }
        return $set;
    }

}
