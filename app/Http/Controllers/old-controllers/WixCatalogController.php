<?php

namespace App\Http\Controllers;

use App\Helpers\WixHelper;
use App\Models\WixStore;
use App\Models\WixCollectionMigration;
use App\Models\WixProductMigration;
use App\Models\WixBrandMigration;
use App\Models\WixRibbonMigration;
use App\Models\WixCustomizationMigration;
use App\Models\WixInfoSectionMigration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WixCatalogController extends Controller
{
    // =========================================================
    // Export Catalog (Collections/Categories + Products+Inventory)
    // =========================================================
    public function exportAll(WixStore $store)
    {
        $userId      = Auth::id() ?: 1;
        $fromStoreId = $store->instance_id;

        WixHelper::log('Export Catalog', "Export started for store: {$store->store_name}", 'info');

        $accessToken = WixHelper::getAccessToken($fromStoreId);
        if (!$accessToken) {
            WixHelper::log('Export Catalog', "Failed: Could not get access token for instanceId: $fromStoreId", 'error');
            return back()->with('error', 'You are not authorized to access.');
        }

        $catalogVersion = WixHelper::getCatalogVersion($accessToken);

        // --- Collections/Categories ---
        $collectionsResp = $this->getCollectionsNormalized($accessToken, $catalogVersion);
        if (!isset($collectionsResp['collections']) || !is_array($collectionsResp['collections'])) {
            WixHelper::log('Export Catalog', 'Failed to fetch collections/categories from Wix: '.json_encode($collectionsResp), 'error');
            return response()->json(['error' => 'Failed to fetch collections/categories from Wix.'], 500);
        }
        $collections = $collectionsResp['collections'];

        foreach ($collections as $col) {
            WixCollectionMigration::firstOrCreate(
                [
                    'user_id'              => $userId,
                    'from_store_id'        => $fromStoreId,
                    'to_store_id'          => null,
                    'source_collection_id' => $col['id'],
                ],
                [
                    'source_collection_slug' => $col['slug'] ?? null,
                    'source_collection_name' => $col['name'] ?? null,
                    'status'                 => 'pending',
                    'error_message'          => null,
                ]
            );
        }

        // --- Products + Inventory ---
        $productsResponse = $this->getAllProducts($accessToken, $store);
        $products         = $productsResponse['products'] ?? [];

        // Inventory map (by SKU)
        $inventoryItems  = $this->queryInventoryItems($accessToken)['inventoryItems'] ?? [];
        $skuInventoryMap = [];
        foreach ($inventoryItems as $inv) {
            if (!empty($inv['sku'])) $skuInventoryMap[$inv['sku']] = $inv;
        }

        // Build collectionId -> slug
        $collectionIdToSlug = [];
        foreach ($collections as $c) {
            if (!empty($c['id'])) {
                $collectionIdToSlug[$c['id']] = $c['slug'] ?? null;
            }
        }

        // V3 enrichments
        $brands = $ribbons = $infoSections = $customizations = [];
        if ($catalogVersion === 'V3_CATALOG') {
            $brands         = $this->fetchAllV3EntityMap($accessToken, 'brands', 'https://www.wixapis.com/stores/v3/brands/query', 'brands');
            $ribbons        = $this->fetchAllV3EntityMap($accessToken, 'ribbons', 'https://www.wixapis.com/stores/v3/ribbons/query', 'ribbons');
            $infoSections   = $this->fetchAllV3EntityMap($accessToken, 'info-sections', 'https://www.wixapis.com/stores/v3/info-sections/query', 'infoSections');
            $customizations = $this->fetchAllV3EntityMap($accessToken, 'customizations', 'https://www.wixapis.com/stores/v3/customizations/query', 'customizations');
        }

        foreach ($products as &$product) {
            // Attach inventory by SKU
            $sku = $product['sku'] ?? null;
            if ($sku && isset($skuInventoryMap[$sku])) {
                $product['inventory'] = $skuInventoryMap[$sku];
            }

            // Full variants
            $variants_full = [];
            if (!empty($product['id'])) {
                $variantResp = Http::withHeaders([
                    'Authorization' => $accessToken,
                    'Content-Type'  => 'application/json'
                ])->post("https://www.wixapis.com/stores-reader/v1/products/{$product['id']}/variants/query", [
                    "includeMerchantSpecificData" => true
                ]);
                $variants_full = $variantResp->json('variants') ?? [];
            }

            if ($variants_full) {
                foreach ($variants_full as &$v) {
                    $vSku = $v['variant']['sku'] ?? null;
                    if ($vSku && isset($skuInventoryMap[$vSku])) {
                        $v['inventory'] = $skuInventoryMap[$vSku];
                    }
                }
                $product['variants_full'] = $variants_full;
            }

            // Add category/collection slugs
            $product['collectionSlugs'] = [];
            if (!empty($product['collectionIds']) && is_array($product['collectionIds'])) {
                foreach ($product['collectionIds'] as $colId) {
                    if (isset($collectionIdToSlug[$colId]) && $collectionIdToSlug[$colId] !== null) {
                        $product['collectionSlugs'][] = $collectionIdToSlug[$colId];
                    }
                }
            }

            // V3 enrich
            if ($catalogVersion === 'V3_CATALOG') {
                if (!empty($product['brand']['id']) && isset($brands[$product['brand']['id']])) {
                    $product['brand_export'] = $brands[$product['brand']['id']];
                    if (empty($product['brand']['name']) && !empty($brands[$product['brand']['id']]['name'])) {
                        $product['brand']['name'] = $brands[$product['brand']['id']]['name'];
                    }
                }
                if (!empty($product['ribbon']['id']) && isset($ribbons[$product['ribbon']['id']])) {
                    $product['ribbon_export'] = $ribbons[$product['ribbon']['id']];
                }
                if (!empty($product['infoSections'])) {
                    $product['infoSections_export'] = [];
                    foreach ($product['infoSections'] as $info) {
                        $id = is_array($info) ? ($info['id'] ?? $info) : $info;
                        if ($id && isset($infoSections[$id])) {
                            $product['infoSections_export'][] = $infoSections[$id];
                        }
                    }
                }
                if (!empty($product['customizations'])) {
                    $product['customizations_export'] = [];
                    foreach ($product['customizations'] as $cust) {
                        $id = is_array($cust) ? ($cust['id'] ?? $cust) : $cust;
                        if ($id && isset($customizations[$id])) {
                            $product['customizations_export'][] = $customizations[$id];
                        }
                    }
                }
            }

            if (!empty($product['id'])) {
                WixProductMigration::updateOrCreate(
                    [
                        'user_id'           => $userId,
                        'from_store_id'     => $fromStoreId,
                        'to_store_id'       => null,
                        'source_product_id' => $product['id'],
                    ],
                    [
                        'source_product_sku'  => $product['sku'] ?? null,
                        'source_product_name' => $product['name'] ?? null,
                        'status'              => 'pending',
                        'error_message'       => null,
                    ]
                );
            }
        }

        WixHelper::log('Export Catalog', "Exported ".count($collections)." collections/categories and ".count($products)." products.", 'success');

        return response()->streamDownload(function () use ($fromStoreId, $collections, $products) {
            echo json_encode([
                'from_store_id' => $fromStoreId,
                'collections'   => $collections,
                'products'      => $products,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }, 'catalog.json', ['Content-Type' => 'application/json']);
    }

    // =========================================================
    // Import Catalog (accepts merged or legacy files)
    // =========================================================
    public function importAll(Request $request, WixStore $store)
    {
        $userId    = Auth::id() ?: 1;
        $toStoreId = $store->instance_id;

        WixHelper::log('Import Catalog', "Import started for store: {$store->store_name}", 'info');

        $accessToken = WixHelper::getAccessToken($toStoreId);
        if (!$accessToken) {
            WixHelper::log('Import Catalog', "Failed: Could not get access token for instanceId: {$toStoreId}", 'error');
            return back()->with('error', 'Could not get Wix access token.');
        }

        // NEW: accept merged (catalog_json) OR legacy (categories_json / products_inventory_json) — either or both
        $resolved = $this->resolveCatalogUploads($request);
        if (!$resolved['ok']) {
            WixHelper::log('Import Catalog', $resolved['error'] ?? 'No usable JSON provided.', 'error');
            return back()->with('error', $resolved['error'] ?? 'No usable JSON provided.');
        }

        $fromStoreId = $resolved['fromStoreId'];
        $collections = $resolved['collections']; // may be []
        $products    = $resolved['products'];    // may be []

        $catalogVersion = WixHelper::getCatalogVersion($accessToken);

        // ---------- IMPORT COLLECTIONS / CATEGORIES ----------
        $collectionsImported = 0;
        $collectionErrors    = [];

        if (!empty($collections)) {
            foreach ($collections as $collection) {
                $sourceId = $collection['id'] ?? null; // may be null for older dumps; we still try
                $migration = null;

                if ($sourceId) {
                    $migration = WixCollectionMigration::where([
                        'user_id'              => $userId,
                        'from_store_id'        => $fromStoreId,
                        'source_collection_id' => $sourceId,
                    ])->first();

                    if ($migration && $migration->status === 'success') continue;
                }

                // Clean up any system fields before import
                $toCreate = $collection;
                unset($toCreate['id'], $toCreate['numberOfProducts'], $toCreate['slug']); // let Wix generate slug

                $resp = $this->createCollectionInWix($accessToken, $toCreate, $catalogVersion);

                if (isset($resp['collection']['id'])) {
                    $collectionsImported++;

                    WixCollectionMigration::updateOrCreate(
                        [
                            'user_id'              => $userId,
                            'from_store_id'        => $fromStoreId,
                            'source_collection_id' => $sourceId,
                        ],
                        [
                            'to_store_id'               => $toStoreId,
                            'source_collection_slug'    => $collection['slug'] ?? null,
                            'source_collection_name'    => $collection['name'] ?? null,
                            'destination_collection_id' => $resp['collection']['id'],
                            'status'                    => 'success',
                            'error_message'             => null,
                        ]
                    );

                    WixHelper::log('Import Catalog', "Imported collection/category '{$collection['name']}' (new ID: {$resp['collection']['id']})", 'success');
                } else {
                    $err = json_encode(['sent' => $toCreate, 'response' => $resp], JSON_UNESCAPED_UNICODE);
                    $collectionErrors[] = $err;

                    WixCollectionMigration::updateOrCreate(
                        [
                            'user_id'              => $userId,
                            'from_store_id'        => $fromStoreId,
                            'source_collection_id' => $sourceId,
                        ],
                        [
                            'to_store_id'             => $toStoreId,
                            'source_collection_slug'  => $collection['slug'] ?? null,
                            'source_collection_name'  => $collection['name'] ?? null,
                            'status'                  => 'failed',
                            'error_message'           => $err,
                        ]
                    );

                    WixHelper::log('Import Catalog', "Failed to import collection/category '{$collection['name']}': ".$err, 'error');
                }
            }
        }

        // ---------- PREPARE RELATION MAPS (V3) ----------
        $relationMaps = $this->prepareRelationMaps($catalogVersion, $fromStoreId, $toStoreId, $accessToken);

        // ---------- IMPORT PRODUCTS + INVENTORY ----------
        $productsImported   = 0;
        $inventoryUpdated   = 0;
        $productErrors      = [];
        $collectionSlugMap  = [];

        if (!empty($products)) {
            // Oldest-first
            usort($products, function ($a, $b) {
                $dateA = isset($a['createdDate']) ? strtotime($a['createdDate']) : 0;
                $dateB = isset($b['createdDate']) ? strtotime($b['createdDate']) : 0;
                return $dateA <=> $dateB;
            });

            foreach ($products as $product) {
                [$result, $error] = $this->importSingleProduct(
                    $catalogVersion,
                    $accessToken,
                    $product,
                    $fromStoreId,
                    $toStoreId,
                    $userId,
                    $relationMaps,
                    $collectionSlugMap
                );

                if ($result['imported'])         $productsImported++;
                if ($result['inventoryUpdated']) $inventoryUpdated += $result['inventoryUpdated'];
                if ($result['error'])            $productErrors[] = $result['error'];
            }
        }

        // ---------- SUMMARY ----------
        $summary = [
            'summary'           => "{$collectionsImported} collection(s) imported. {$productsImported} product(s) imported. {$inventoryUpdated} inventory item(s) created/updated.",
            'collectionErrors'  => $collectionErrors,
            'productErrors'     => $productErrors,
        ];

        WixHelper::log('Import Catalog', json_encode($summary, JSON_UNESCAPED_UNICODE), (count($collectionErrors) || count($productErrors)) ? 'error' : 'success');

        if ($productsImported > 0 || $collectionsImported > 0) {
            return back()->with('success', $summary['summary']);
        }
        // If nothing to import but files were valid, still show a helpful message.
        return back()->with('error', $summary['summary']);
    }

    /**
     * NEW: Resolve uploaded JSON(s).
     * Accepts:
     *  - merged:   file input name "catalog_json" with keys from_store_id, collections, products
     *  - legacy 1: "categories_json" with keys from_store_id + collections (or "categories")
     *  - legacy 2: "products_inventory_json" with keys from_store_id + products
     * You may upload any single file; we also scan all files just in case names differ.
     */
    private function resolveCatalogUploads(Request $request): array
    {
        $fromStoreId = null;
        $collections = [];
        $products    = [];

        $files = [];
        // Known names first (preferred)
        foreach (['catalog_json', 'categories_json', 'products_inventory_json'] as $name) {
            if ($request->hasFile($name)) $files[$name] = $request->file($name);
        }
        // Fallback: include any other uploaded files (defensive)
        foreach ($request->files->all() as $name => $file) {
            if (!isset($files[$name])) $files[$name] = $file;
        }

        if (empty($files)) {
            return ['ok' => false, 'error' => 'No file uploaded. Upload catalog_json or the legacy files categories_json/products_inventory_json.', 'fromStoreId' => null, 'collections' => [], 'products' => []];
        }

        $anyUseful = false;

        foreach ($files as $name => $file) {
            try {
                $json = file_get_contents($file->getRealPath());
            } catch (\Throwable $e) {
                continue;
            }
            $decoded = json_decode($json, true);
            if (!is_array($decoded)) continue;

            // capture from_store_id if present
            if (!empty($decoded['from_store_id']) && !$fromStoreId) {
                $fromStoreId = $decoded['from_store_id'];
            }

            // merged format
            if (isset($decoded['collections']) && is_array($decoded['collections'])) {
                $collections = array_merge($collections, $decoded['collections']);
                $anyUseful = true;
            } elseif (isset($decoded['categories']) && is_array($decoded['categories'])) {
                // normalize "categories" -> "collections"
                $normalized = [];
                foreach ($decoded['categories'] as $c) {
                    $normalized[] = [
                        'id'    => $c['id']   ?? null,
                        'slug'  => $c['slug'] ?? null,
                        'name'  => $c['name'] ?? null,
                        'media' => $c['media'] ?? null,
                    ];
                }
                $collections = array_merge($collections, $normalized);
                $anyUseful = true;
            }

            if (isset($decoded['products']) && is_array($decoded['products'])) {
                $products = array_merge($products, $decoded['products']);
                $anyUseful = true;
            }
        }

        if (!$anyUseful) {
            return ['ok' => false, 'error' => 'Invalid JSON structure. Provide a merged catalog.json or a legacy collections/products file.', 'fromStoreId' => null, 'collections' => [], 'products' => []];
        }

        // Even if fromStoreId is missing, proceed — it’s only used for migration rows
        return [
            'ok'          => true,
            'error'       => null,
            'fromStoreId' => $fromStoreId ?? 'unknown',
            'collections' => $collections,
            'products'    => $products,
        ];
    }

    // =========================================================
    // Collections / Categories fetch (normalized)
    // =========================================================
    private function getCollectionsNormalized(string $accessToken, string $catalogVersion)
    {
        if ($catalogVersion === 'V3_CATALOG') {
            // V3 categories
            $items   = [];
            $offset  = 0;
            $hasMore = true;
            $page    = 0;

            do {
                $body = [
                    'query' => [
                        'cursorPaging' => ['limit' => 100],
                        'sort'         => [['fieldName' => 'createdDate', 'order' => 'ASC']],
                    ],
                    'fields' => []
                ];
                if ($offset > 0) $body['query']['cursorPaging']['offset'] = $offset;

                $resp = Http::withHeaders([
                    'Authorization' => $accessToken,
                    'Content-Type'  => 'application/json'
                ])->post('https://www.wixapis.com/stores/v3/categories/query', $body);

                $json = $resp->json();
                $cats = $json['categories'] ?? [];

                foreach ($cats as $c) {
                    $items[] = [
                        'id'    => $c['id'] ?? null,
                        'slug'  => $c['slug'] ?? null,
                        'name'  => $c['name'] ?? null,
                        'media' => $c['media'] ?? null,
                    ];
                }

                $hasMore = count($cats) === 100;
                if ($hasMore) $offset += 100;
                $page++;
                if ($page > 200) break;
            } while ($hasMore);

            return ['collections' => $items];
        }

        // V1 collections
        $response = Http::withHeaders([
            'Authorization' => $accessToken,
            'Content-Type'  => 'application/json'
        ])->post('https://www.wixapis.com/stores-reader/v1/collections/query', ['query' => (object)[]]);

        WixHelper::log('Export Catalog', 'Wix API raw collections response: '.$response->body(), 'debug');

        $json = $response->json();
        return ['collections' => $json['collections'] ?? []];
    }

    // =========================================================
    // V1/V3 aware collection/category creation
    // =========================================================
    private function createCollectionInWix(string $accessToken, array $collection, string $catalogVersion = 'V1_CATALOG')
    {
        // Skip pseudo "All Products"
        $nameLower = mb_strtolower(trim($collection['name'] ?? ''));
        if ($nameLower === 'all products') {
            return ['collection' => ['id' => null]];
        }

        if ($catalogVersion === 'V3_CATALOG') {
            return $this->createCategoryV3($accessToken, $collection);
        }

        // V1
        $body = ['collection' => $collection];
        $response = Http::withHeaders([
            'Authorization' => $accessToken,
            'Content-Type'  => 'application/json'
        ])->post('https://www.wixapis.com/stores/v1/collections', $body);

        return $response->json();
    }

    private function createCategoryV3(string $accessToken, array $collection)
    {
        $category = [
            'name' => $collection['name'] ?? 'Untitled',
        ];

        if (!empty($collection['media'])) {
            $mediaItems = $this->extractV3MediaItems($collection['media']);
            if ($mediaItems) {
                $category['media'] = ['itemsInfo' => ['items' => $mediaItems]];
            }
        }

        $resp = Http::withHeaders([
            'Authorization' => $accessToken,
            'Content-Type'  => 'application/json'
        ])->post('https://www.wixapis.com/stores/v3/categories', ['category' => $category]);

        WixHelper::log('Import Catalog', [
            'step'   => 'createCategoryV3',
            'ok'     => $resp->ok(),
            'status' => $resp->status(),
            'req'    => $category,
            'res'    => $resp->body()
        ], $resp->ok() ? 'success' : 'error');

        if ($resp->ok() && isset($resp->json()['category']['id'])) {
            return ['collection' => ['id' => $resp->json()['category']['id']]];
        }
        return $resp->json();
    }

    // =========================================================
    // Products fetch / inventory
    // =========================================================
    public function getAllProducts($accessToken, $store)
    {
        $products = [];
        $callCount = 0;
        $hasMore = true;
        $cursor = null;

        $catalogVersion = WixHelper::getCatalogVersion($accessToken);

        do {
            if ($catalogVersion === 'V3_CATALOG') {
                $body = [
                    'fields' => [],
                    'query' => [
                        'sort' => [
                            [
                                'order' => 'ASC',
                                'fieldName' => 'createdDate'
                            ]
                        ]
                    ]
                ];
                if ($cursor) {
                    $body['query']['paging'] = ['offset' => $cursor];
                }
                $endpoint = 'https://www.wixapis.com/stores/v3/products/query';
            } else {
                $query = new \stdClass();
                if ($cursor) {
                    $query->paging = ['offset' => $cursor];
                }
                $body = [
                    'query' => $query
                ];
                $endpoint = 'https://www.wixapis.com/stores/v1/products/query';
            }

            $response = Http::withHeaders([
                'Authorization' => $accessToken,
                'Content-Type'  => 'application/json'
            ])->post($endpoint, $body);

            $result = $response->json();
            $batch = $result['products'] ?? [];
            $products = array_merge($products, $batch);

            $hasMore = count($batch) === 100;
            if ($hasMore) {
                $cursor = count($products);
            }
            $callCount++;

            WixHelper::log('Export Catalog', "Fetched batch #$callCount, products so far: ".count($products), 'info');
        } while ($hasMore);

        WixHelper::log('Export Catalog', "Total products fetched for export: ".count($products), 'info');
        return ['products' => $products];
    }

    public function queryInventoryItems($accessToken, $query = [])
    {
        $catalogVersion = WixHelper::getCatalogVersion($accessToken);

        if ($catalogVersion === 'V3_CATALOG') {
            $endpoint = 'https://www.wixapis.com/stores/v3/inventory-items/query';
            $body = [
                'query' => $query
            ];
        } else {
            $endpoint = 'https://www.wixapis.com/stores-reader/v2/inventoryItems/query';
            $body = [
                'query' => (object) $query
            ];
        }

        $response = Http::withHeaders([
            'Authorization' => $accessToken,
            'Content-Type'  => 'application/json'
        ])->post($endpoint, $body);

        return $response->json();
    }

    // =========================================================
    // Import: orchestrators
    // =========================================================
    private function importSingleProduct($catalogVersion, $accessToken, $product, $fromStoreId, $toStoreId, $userId, $relationMaps, &$collectionSlugMap)
    {
        $migrationKey = [
            'user_id'           => $userId,
            'from_store_id'     => $fromStoreId,
            'source_product_id' => $product['id'] ?? null,
        ];

        $migrationData = [
            'source_product_sku'   => $product['sku'] ?? null,
            'source_product_name'  => $product['name'] ?? null,
            'status'               => 'pending',
            'error_message'        => null,
            'destination_product_id' => null,
        ];

        $imported = 0;
        $inventoryUpdated = 0;
        $error = null;

        try {
            WixHelper::log('Import Products+Inventory', [
                'step' => 'processing',
                'name' => $product['name'] ?? '[No Name]',
                'sku' => $product['sku'] ?? '[No SKU]'
            ]);

            if ($catalogVersion === 'V3_CATALOG') {
                [$newProduct, $errMsg] = $this->importProductV3($accessToken, $product, $relationMaps);
                if ($newProduct) {
                    $imported++;
                    $inventoryUpdated += max(1, count($newProduct['variantsInfo']['variants'] ?? []));
                    $migrationData['status'] = 'success';
                    $migrationData['destination_product_id'] = $newProduct['id'];
                } else {
                    $migrationData['status'] = 'failed';
                    $migrationData['error_message'] = $errMsg;
                    $error = $errMsg;
                }
            } elseif ($catalogVersion === 'V1_CATALOG') {
                [$prodId, $invCount, $errMsg] = $this->importProductV1($accessToken, $product, $fromStoreId, $toStoreId, $collectionSlugMap);
                if ($prodId) {
                    $imported++;
                    $inventoryUpdated += $invCount;
                    $migrationData['status'] = 'success';
                    $migrationData['destination_product_id'] = $prodId;
                } else {
                    $migrationData['status'] = 'failed';
                    $migrationData['error_message'] = $errMsg;
                    $error = $errMsg;
                }
            } else {
                $migrationData['status'] = 'failed';
                $migrationData['error_message'] = "Unknown or unsupported catalog version: $catalogVersion";
                $error = $migrationData['error_message'];
            }
        } catch (\Throwable $e) {
            $migrationData['status'] = 'failed';
            $migrationData['error_message'] = $e->getMessage();
            $error = "Exception for product {$product['name']}: " . $e->getMessage();
        }

        WixProductMigration::updateOrCreate($migrationKey, $migrationData);

        return [
            [
                'imported' => $imported,
                'inventoryUpdated' => $inventoryUpdated,
                'error' => $error
            ],
            $error
        ];
    }

    // =========================================================
    // Product Import (V3)
    // =========================================================
    private function importProductV3($accessToken, $product, array &$relationMaps)
    {
        // ----- Brand & Ribbon -----
        $destBrandId = null;
        if (!empty($product['brand'])) {
            $brandPayload = $product['brand'];
            if (empty($brandPayload['name']) && !empty($product['brand_export']['name'])) {
                $brandPayload['name'] = $product['brand_export']['name'];
            }
            $destBrandId = $this->ensureBrandIdV3($accessToken, $brandPayload, $relationMaps);
        }

        $destRibbonId = null;
        if (!empty($product['ribbon'])) {
            $ribbonPayload = $product['ribbon'];
            if (empty($ribbonPayload['name']) && !empty($product['ribbon_export']['name'])) {
                $ribbonPayload['name'] = $product['ribbon_export']['name'];
            }
            $destRibbonId = $this->ensureRibbonIdV3($accessToken, $ribbonPayload, $relationMaps);
        }

        // ----- Base product -----
        $productType = strtoupper($product['productType'] ?? 'PHYSICAL');
        $validTypes  = ['PHYSICAL','DIGITAL','UNSPECIFIED_PRODUCT_TYPE'];
        if (!in_array($productType, $validTypes, true)) $productType = 'PHYSICAL';

        $body = [
            'product' => [
                'name'             => $product['name'] ?? 'Unnamed Product',
                'slug'             => $product['slug'] ?? null,
                'plainDescription' => $product['description'] ?? '',
                'visible'          => (bool)($product['visible'] ?? true),
                'productType'      => $productType,
                'variantsInfo'     => ['variants' => []],
            ],
            'returnEntity' => true,
        ];
        if ($productType === 'PHYSICAL') {
            $body['product']['physicalProperties'] = (object)[];
        }
        if ($destBrandId)  $body['product']['brand']  = ['id' => $destBrandId];
        if ($destRibbonId) $body['product']['ribbon'] = ['id' => $destRibbonId];

        // Media
        if (!empty($product['media'])) {
            $sanitized = $this->sanitizeMediaForV3($product['media']);
            if (!empty($sanitized)) $body['product']['media'] = $sanitized;
        }

        // Info Sections
        $workInfoSections       = $product['infoSections'] ?? [];
        $workInfoSectionsExport = $product['infoSections_export'] ?? [];

        if (empty($workInfoSections) && !empty($product['additionalInfoSections']) && is_array($product['additionalInfoSections'])) {
            $workInfoSections = [];
            $workInfoSectionsExport = [];
            $i = 0;
            foreach ($product['additionalInfoSections'] as $ais) {
                $title  = trim((string)($ais['title'] ?? 'Info Section'));
                $unique = $this->slugifyUnique($title ?: ('info-section-'.(++$i)));
                $workInfoSections[] = ['uniqueName' => $unique, 'title' => $title];
                $workInfoSectionsExport[] = [
                    'uniqueName'       => $unique,
                    'title'            => $title,
                    'plainDescription' => is_string($ais['description'] ?? null) ? $ais['description'] :
                        ($ais['descriptionHtml'] ?? ($ais['descriptionPlainText'] ?? ''))
                ];
            }
        }

        if (!empty($workInfoSections)) {
            $body['product']['infoSections'] = [];
            $exportByKey = [];
            foreach ($workInfoSectionsExport as $is) {
                if (!empty($is['id']))         $exportByKey['id:'.$is['id']] = $is;
                if (!empty($is['uniqueName'])) $exportByKey['u:'.mb_strtolower($is['uniqueName'])] = $is;
            }
            foreach ($workInfoSections as $info) {
                $src = is_array($info) ? $info : ['id' => $info];
                $enr = null;
                if (!empty($src['id']) && isset($exportByKey['id:'.$src['id']])) {
                    $enr = $exportByKey['id:'.$src['id']];
                } elseif (!empty($src['uniqueName'])) {
                    $key = 'u:'.mb_strtolower($src['uniqueName']);
                    if (isset($exportByKey[$key])) $enr = $exportByKey[$key];
                }
                $destId = $this->ensureInfoSectionIdV3($accessToken, $src, $enr, $relationMaps);
                if ($destId) $body['product']['infoSections'][] = ['id' => $destId];
            }
        }

        // Options (from productOptions / variants)
        $options = $this->buildV3OptionsFromSource($product);
        if (!empty($options)) $body['product']['options'] = $options;

        $optionsOrder = isset($body['product']['options'])
            ? array_map(fn($o) => $o['name'], $body['product']['options'])
            : [];

        // Modifiers & custom text
        $modifiers = [];

        if (!empty($product['customizations']) && is_array($product['customizations'])) {
            $exportById = [];
            foreach (($product['customizations_export'] ?? []) as $cx) {
                if (!empty($cx['id'])) $exportById[$cx['id']] = $cx;
            }
            foreach ($product['customizations'] as $cust) {
                $src = is_array($cust) ? $cust : ['id' => $cust];
                $enr = (!empty($src['id']) && isset($exportById[$src['id']])) ? $exportById[$src['id']] : null;
                if (empty($src['name']) && !empty($enr['name'])) $src['name'] = $enr['name'];
                $destId = $this->ensureCustomizationIdV3($accessToken, $src, $enr, $relationMaps);
                if ($destId) {
                    $ctype = $enr['customizationType'] ?? $src['customizationType'] ?? null;
                    if ($ctype === 'MODIFIER') {
                        $modifiers[] = ['id' => $destId];
                    } elseif ($ctype === 'PRODUCT_OPTION') {
                        if (empty($body['product']['options'])) {
                            $body['product']['options'] = [['id' => $destId]];
                            $optionsOrder = [];
                        }
                    }
                }
            }
        }

        if (!empty($product['customTextFields']) && is_array($product['customTextFields'])) {
            foreach ($product['customTextFields'] as $ctf) {
                $name      = $ctf['title'] ?? 'Custom Text';
                $mandatory = (bool)($ctf['required'] ?? false);
                $created = $this->createCustomizationV3($accessToken, [
                    'name'                    => $name,
                    'customizationType'       => 'MODIFIER',
                    'customizationRenderType' => 'FREE_TEXT',
                    'freeTextInput'           => [
                        'minCharCount'      => 0,
                        'maxCharCount'      => 500,
                        'defaultAddedPrice' => '0',
                        'title'             => $name,
                    ],
                ]);
                if (!empty($created['id'])) {
                    $modifiers[] = ['id' => $created['id'], 'mandatory' => $mandatory];
                }
            }
        }

        if (!empty($modifiers)) $body['product']['modifiers'] = $modifiers;

        // Variants
        $variantSource = !empty($product['variants_full']) ? $product['variants_full'] : ($product['variants'] ?? []);

        if (!empty($variantSource)) {
            foreach ($variantSource as $v) {
                $norm = $this->normalizeV3Variant($v, $product);

                // Backfill missing option pairs based on declared options
                if (!empty($optionsOrder)) {
                    $have = [];
                    $pairsInVariant = $norm['choices']['optionChoiceNames'] ?? [];
                    foreach ($pairsInVariant as $p) {
                        $n = mb_strtolower($p['optionName'] ?? '');
                        if ($n !== '') $have[$n] = true;
                    }
                    foreach ($optionsOrder as $idx => $optName) {
                        if (!isset($have[mb_strtolower($optName)])) {
                            $first = $body['product']['options'][$idx]['choicesSettings']['choices'][0]['name'] ?? null;
                            if ($first !== null && $first !== '') {
                                if (!isset($norm['choices'])) $norm['choices'] = ['optionChoiceNames' => []];
                                $norm['choices']['optionChoiceNames'][] = [
                                    'optionName' => $optName,
                                    'choiceName' => $first
                                ];
                            }
                        }
                    }
                }

                if (isset($norm['price']['actualPrice']['amount'])) {
                    $norm['price']['actualPrice']['amount'] = (string)$norm['price']['actualPrice']['amount'];
                }

                $body['product']['variantsInfo']['variants'][] = $norm;
            }
        } else {
            // Single variant
            $priceVal = $product['price']['price'] ?? 0;
            $quantity = $product['stock']['quantity'] ?? null;
            $inStock  = $product['stock']['inStock'] ?? true;

            $inv = [];
            if ($quantity !== null && $quantity !== '') {
                $inv['quantity'] = (int)$quantity;
            } else {
                $inv['inStock'] = (bool)$inStock;
            }

            $body['product']['variantsInfo']['variants'][] = [
                'sku'           => $product['sku'] ?? ('SKU-' . uniqid()),
                'price'         => ['actualPrice' => ['amount' => (string)$priceVal]],
                'inventoryItem' => $inv,
                'visible'       => (bool)($product['visible'] ?? true),
            ];
        }

        // Create with duplicate-slug auto-retry
        WixHelper::log('Import Products+Inventory', ['step' => 'posting V3', 'payload' => $body]);
        [$newProduct, $err] = $this->postProductWithInventoryV3($accessToken, $body);

        WixHelper::log(
            'Import Products+Inventory',
            ['step' => 'V3 response', 'ok' => (bool)$newProduct, 'responseError' => $err],
            $newProduct ? 'success' : 'error'
        );

        if (!$newProduct) {
            $firstVariant = $body['product']['variantsInfo']['variants'][0] ?? [];
            $snippet = json_encode($firstVariant, JSON_UNESCAPED_UNICODE);
            return [null, ($err ?: 'Unknown V3 error').' | firstVariant='.$snippet];
        }

        return [$newProduct, null];
    }

    // =========================================================
    // Product Import (V1)
    // =========================================================
    private function importProductV1($accessToken, $product, $fromStoreId, $toStoreId, &$collectionSlugMap)
    {
        $inventoryUpdated = 0;
        $productId = null;

        $filteredProduct = $this->filterWixProductForImport($product);
        if (empty($filteredProduct['sku'])) {
            $filteredProduct['sku'] = 'SKU-' . uniqid();
        }

        if (!empty($product['customTextFields'])) {
            $filteredProduct['customTextFields'] = $product['customTextFields'];
        }

        WixHelper::log('Import Products+Inventory', ['step' => 'posting V1', 'payload' => $filteredProduct]);

        $response = Http::withHeaders([
            'Authorization' => $accessToken,
            'Content-Type'  => 'application/json'
        ])->post('https://www.wixapis.com/stores/v1/products', ["product" => $filteredProduct]);

        WixHelper::log('Import Products+Inventory', ['step' => 'V1 response', 'response' => $response->body()], $response->ok() ? 'success' : 'error');

        $result = $response->json();
        if ($response->status() === 200 && isset($result['product']['id'])) {
            $productId = $result['product']['id'];
            $createdProduct = $result['product'];
            $hasVariants = !empty($createdProduct['variants']);

            $inventoryBody = [
                'inventoryItem' => [
                    'trackQuantity' => true,
                    'variants' => [],
                ]
            ];

            if ($hasVariants) {
                $variantSource = !empty($product['variants_full']) ? $product['variants_full'] : ($product['variants'] ?? []);
                foreach ($createdProduct['variants'] as $i => $variant) {
                    $origVariant = $variantSource[$i] ?? [];
                    $flat = isset($origVariant['variant']) ? $origVariant['variant'] : $origVariant;
                    $quantity = $flat['stock']['quantity'] ?? $origVariant['stock']['quantity'] ?? $product['stock']['quantity'] ?? null;
                    $inventoryBody['inventoryItem']['variants'][] = [
                        'variantId' => $variant['id'],
                        'quantity'  => $quantity,
                    ];
                }
            } else {
                $quantity = $product['stock']['quantity'] ?? null;
                $inventoryBody['inventoryItem']['variants'][] = [
                    'variantId' => '00000000-0000-0000-0000-000000000000',
                    'quantity' => $quantity,
                ];
            }

            WixHelper::log('Import Products+Inventory', ['step' => 'patching inventory V1', 'product_id' => $productId, 'payload' => $inventoryBody]);

            $invRes = Http::withHeaders([
                'Authorization' => $accessToken,
                'Content-Type'  => 'application/json'
            ])->patch("https://www.wixapis.com/stores/v2/inventoryItems/product/{$productId}", $inventoryBody);

            WixHelper::log('Import Products+Inventory', ['step' => 'V1 Inventory PATCH response', 'response' => $invRes->body()], $invRes->ok() ? 'success' : 'error');
            if ($invRes->ok()) $inventoryUpdated++;

            // Variants PATCH (full)
            $variantSource = !empty($product['variants_full']) ? $product['variants_full'] : ($product['variants'] ?? []);
            $hasVariants = !empty($createdProduct['variants']);
            if ($hasVariants && !empty($variantSource)) {
                $variantsPayload = [];
                foreach ($variantSource as $variantData) {
                    $variant = $variantData['variant'] ?? [];
                    $variantUpdate = [
                        'choices' => $variantData['choices'] ?? [],
                    ];
                    if (isset($variant['priceData']['price'])) {
                        $variantUpdate['price'] = $variant['priceData']['price'];
                    }
                    if (isset($variant['costAndProfitData']['itemCost'])) {
                        $variantUpdate['cost'] = $variant['costAndProfitData']['itemCost'];
                    }
                    if (isset($variant['weight'])) {
                        $variantUpdate['weight'] = $variant['weight'];
                    }
                    if (!empty($variant['sku'])) {
                        $variantUpdate['sku'] = $variant['sku'];
                    }
                    if (isset($variant['visible'])) {
                        $variantUpdate['visible'] = $variant['visible'];
                    }
                    $variantsPayload[] = $variantUpdate;
                }

                if (count($variantsPayload)) {
                    $variantsRes = Http::withHeaders([
                        'Authorization' => $accessToken,
                        'Content-Type'  => 'application/json'
                    ])->patch("https://www.wixapis.com/stores/v1/products/{$productId}/variants", [
                        'variants' => $variantsPayload
                    ]);
                    WixHelper::log('Import Products+Inventory', [
                        'step' => 'PATCH variants',
                        'product_id' => $productId,
                        'payload' => $variantsPayload,
                        'response' => $variantsRes->body()
                    ], $variantsRes->ok() ? 'success' : 'error');
                }
            }

            // Media
            if (!empty($product['media']['items'])) {
                $mediaItems = [];
                foreach ($product['media']['items'] as $media) {
                    if (!empty($media['id'])) {
                        $mediaItems[] = ['mediaId' => $media['id']];
                    } elseif (!empty($media['image']['url'])) {
                        $mediaItem = ['url' => $media['image']['url']];
                        if (!empty($media['choice'])) $mediaItem['choice'] = $media['choice'];
                        $mediaItems[] = $mediaItem;
                    }
                }
                if (count($mediaItems)) {
                    $mediaRes = Http::withHeaders([
                        'Authorization' => $accessToken,
                        'Content-Type'  => 'application/json'
                    ])->post("https://www.wixapis.com/stores/v1/products/{$productId}/media", [
                        'media' => $mediaItems
                    ]);
                    WixHelper::log('Import Products+Inventory', [
                        'step' => 'POST product media',
                        'product_id' => $productId,
                        'mediaItems' => $mediaItems,
                        'response' => $mediaRes->body()
                    ], $mediaRes->ok() ? 'success' : 'error');
                }
            }

            // Attach to V1 collections by slug
            if (!empty($product['collectionSlugs']) && is_array($product['collectionSlugs'])) {
                foreach ($product['collectionSlugs'] as $slug) {
                    if (!is_string($slug) || trim($slug) === '') continue;

                    if (isset($collectionSlugMap[$slug])) {
                        $collectionId = $collectionSlugMap[$slug];
                    } else {
                        $urlSlug = urlencode($slug);
                        $collectionResp = Http::withHeaders([
                            'Authorization' => $accessToken,
                            'Content-Type'  => 'application/json'
                        ])->get("https://www.wixapis.com/stores/v1/collections/slug/{$urlSlug}");

                        $collection = $collectionResp->json('collection');
                        if ($collectionResp->ok() && isset($collection['id'])) {
                            $collectionId = $collection['id'];
                            $collectionSlugMap[$slug] = $collectionId;
                        } else {
                            continue;
                        }
                    }

                    if ($collectionId === '00000000-0000-0000-0000-000000000001') continue;

                    $addResp = Http::withHeaders([
                        'Authorization' => $accessToken,
                        'Content-Type'  => 'application/json'
                    ])->post("https://www.wixapis.com/stores/v1/collections/{$collectionId}/productIds", [
                        'productIds' => [$productId]
                    ]);

                    if (!$addResp->ok()) {
                        $status = $addResp->status();
                        $body   = $addResp->body();

                        if ($status === 409) {
                            WixHelper::log('Import Products+Inventory', [
                                'step'          => 'Add to collection skipped (already present)',
                                'product_id'    => $productId,
                                'collection_id' => $collectionId,
                                'slug'          => $slug,
                                'response'      => $body,
                            ], 'info');
                        } elseif ($status === 429) {
                            $maxRetries = 3;
                            $retry = 0;
                            $success = false;

                            while ($retry < $maxRetries && !$success) {
                                $delayMs = (int) pow(2, $retry) * 250;
                                usleep($delayMs * 1000);

                                $retryResp = Http::withHeaders([
                                    'Authorization' => $accessToken,
                                    'Content-Type'  => 'application/json'
                                ])->post("https://www.wixapis.com/stores/v1/collections/{$collectionId}/productIds", [
                                    'productIds' => [$productId]
                                ]);

                                if ($retryResp->ok()) {
                                    WixHelper::log('Import Products+Inventory', [
                                        'step'          => 'Added product to collection (retry)',
                                        'attempt'       => $retry + 1,
                                        'product_id'    => $productId,
                                        'collection_id' => $collectionId,
                                        'slug'          => $slug,
                                        'response'      => $retryResp->body()
                                    ], 'success');
                                    $success = true;
                                } else {
                                    WixHelper::log('Import Products+Inventory', [
                                        'step'          => 'Retry add to collection failed',
                                        'attempt'       => $retry + 1,
                                        'status'        => $retryResp->status(),
                                        'product_id'    => $productId,
                                        'collection_id' => $collectionId,
                                        'slug'          => $slug,
                                        'response'      => $retryResp->body()
                                    ], 'error');
                                }
                                $retry++;
                            }

                            if (!$success) {
                                WixHelper::log('Import Products+Inventory', [
                                    'step'          => 'Add to collection ultimately failed after retries',
                                    'status'        => $status,
                                    'product_id'    => $productId,
                                    'collection_id' => $collectionId,
                                    'slug'          => $slug,
                                    'response'      => $body
                                ], 'error');
                            }
                        } else {
                            WixHelper::log('Import Products+Inventory', [
                                'step'          => 'Failed to add product to collection',
                                'status'        => $status,
                                'product_id'    => $productId,
                                'collection_id' => $collectionId,
                                'slug'          => $slug,
                                'response'      => $body
                            ], 'error');
                        }
                    } else {
                        WixHelper::log('Import Products+Inventory', [
                            'step' => 'Added product to collection',
                            'product_id' => $productId,
                            'collection_id' => $collectionId,
                            'slug' => $slug,
                            'response' => $addResp->body()
                        ], 'success');
                    }
                }
            }

            return [$productId, $inventoryUpdated, null];
        } else {
            $errorMsg = "V1 product creation failed: " . $response->body();
            return [null, 0, $errorMsg];
        }
    }

    // =========================================================
    // V3 duplicate slug handler + helpers
    // =========================================================
    private function baseSlug(string $text): string
    {
        $t = trim(mb_strtolower($text));
        $t = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $t);
        $t = preg_replace('~[^a-z0-9]+~', '-', $t);
        $t = trim($t, '-');
        return $t ?: ('product-'.uniqid());
    }

    private function postProductWithInventoryV3(string $accessToken, array $body)
    {
        $maxTries = 8;
        $try = 0;

        $name = $body['product']['name'] ?? 'product';
        $slug = $body['product']['slug'] ?? $this->baseSlug($name);
        $body['product']['slug'] = $slug;

        while ($try < $maxTries) {
            $resp = Http::withHeaders([
                'Authorization' => $accessToken,
                'Content-Type'  => 'application/json'
            ])->post('https://www.wixapis.com/stores/v3/products-with-inventory', $body);

            if ($resp->ok() && isset($resp->json()['product']['id'])) {
                return [$resp->json()['product'], null];
            }

            $json = $resp->json();
            $code = $json['details']['applicationError']['code'] ?? null;
            if ($resp->status() === 409 && $code === 'DUPLICATE_SLUG_ERROR') {
                $try++;
                $body['product']['slug'] = $slug.'-'.$try;
                WixHelper::log('Import Products+Inventory', [
                    'step' => 'retry duplicate slug',
                    'attempt' => $try,
                    'newSlug' => $body['product']['slug']
                ], 'info');
                continue;
            }

            return [null, 'V3 product-with-inventory failed: '.$resp->body()];
        }

        return [null, 'V3 product-with-inventory failed: too many duplicate slug retries'];
    }

    // =========================================================
    // V3/V1 Helpers & Maps
    // =========================================================
    private function filterWixProductForImport($product)
    {
        $allowedFields = [
            "name","slug","visible","productType","description","sku","media",
            "manageVariants","productOptions","additionalInfoSections","ribbon","brand",
            "infoSections","modifiers","price","priceData","discount","weight","stock","costAndProfitData","customTextFields"
        ];
        $filtered = [];
        foreach ($allowedFields as $field) {
            if (isset($product[$field])) $filtered[$field] = $product[$field];
        }
        $validTypes = ['unspecified_product_type', 'physical', 'digital'];
        $filtered['productType'] = isset($filtered['productType']) && in_array(strtolower($filtered['productType']), $validTypes)
            ? strtolower($filtered['productType'])
            : 'physical';
        unset($filtered['id']);
        return $filtered;
    }

    private function mapV1ChoicesToV3(array $choicesAssoc): array
    {
        $out = [];
        foreach ($choicesAssoc as $optionName => $choiceVal) {
            if ($choiceVal === null || $choiceVal === '') continue;
            $out[] = ['option' => (string)$optionName, 'choice' => (string)$choiceVal];
        }
        return $out;
    }

    private function buildV3OptionsFromSource(array $product): array
    {
        $options = [];

        $addOption = function(string $name, array $sourceChoices, string $renderType = 'TEXT_CHOICES') use (&$options) {
            $seen = [];
            $choices = [];

            foreach ($sourceChoices as $c) {
                $label = $c['description'] ?? $c['value'] ?? $c['choice'] ?? (is_string($c) ? $c : null);
                if ($label === null || $label === '') continue;

                $key = mb_strtolower(trim((string)$label));
                if (isset($seen[$key])) continue;
                $seen[$key] = true;

                $choiceOut = [
                    'name' => (string)$label,
                    'choiceType' => 'CHOICE_TEXT',
                ];

                if ($renderType === 'SWATCH_CHOICES') {
                    $choiceOut['choiceType'] = 'ONE_COLOR';
                }

                if (!empty($c['media']) && is_array($c['media'])) {
                    $linked = $this->extractV3MediaItems($c['media']);
                    if ($linked) $choiceOut['linkedMedia'] = $linked;
                }

                $choices[] = $choiceOut;
            }

            if ($choices) {
                $options[] = [
                    'name' => $name,
                    'optionRenderType' => $renderType,
                    'choicesSettings' => ['choices' => $choices],
                ];
            }
        };

        if (!empty($product['productOptions']) && is_array($product['productOptions'])) {
            foreach ($product['productOptions'] as $opt) {
                $name = $opt['name'] ?? null;
                if (!$name) continue;
                $renderType = 'TEXT_CHOICES';
                if (strtolower($opt['optionType'] ?? '') === 'color') $renderType = 'SWATCH_CHOICES';
                $addOption((string)$name, (array)($opt['choices'] ?? []), $renderType);
            }
        }

        if (!$options) {
            $variantSource = !empty($product['variants_full']) ? $product['variants_full'] : ($product['variants'] ?? []);
            $optMap = [];
            foreach ($variantSource as $v) {
                $flat = isset($v['variant']) ? $v['variant'] : $v;
                $choices = $v['choices'] ?? $flat['choices'] ?? [];
                if ($choices && !array_is_list($choices)) {
                    foreach ($choices as $optName => $val) {
                        if ($val === null || $val === '') continue;
                        $optMap[$optName][mb_strtolower(trim((string)$val))] = ['name' => (string)$val, 'choiceType' => 'CHOICE_TEXT'];
                    }
                } elseif (is_array($choices)) {
                    foreach ($choices as $c) {
                        $opt = $c['option'] ?? $c['name'] ?? null;
                        $val = $c['choice'] ?? $c['value'] ?? $c['description'] ?? null;
                        if (!$opt || $val === null || $val === '') continue;
                        $optMap[$opt][mb_strtolower(trim((string)$val))] = ['name' => (string)$val, 'choiceType' => 'CHOICE_TEXT'];
                    }
                }
            }
            foreach ($optMap as $name => $vals) {
                $options[] = [
                    'name' => (string)$name,
                    'optionRenderType' => 'TEXT_CHOICES',
                    'choicesSettings' => ['choices' => array_values($vals)],
                ];
            }
        }

        return $options;
    }

    private function normalizeV3Variant(array $variant, array $product): array
    {
        $flat = isset($variant['variant']) && is_array($variant['variant']) ? $variant['variant'] : $variant;

        // Collect optionChoiceNames
        $choicesRaw = $variant['choices'] ?? $flat['choices'] ?? [];
        $pairs = [];

        if (is_array($choicesRaw)) {
            if (isset($choicesRaw[0]) && is_array($choicesRaw[0]) && array_key_exists('option', $choicesRaw[0])) {
                foreach ($choicesRaw as $c) {
                    $opt = isset($c['option']) ? (string)$c['option'] : null;
                    $val = isset($c['choice']) ? (string)$c['choice'] : null;
                    if ($opt !== null && $val !== null && $val !== '') {
                        $pairs[] = ['optionName' => $opt, 'choiceName' => $val];
                    }
                }
            } elseif (!array_is_list($choicesRaw)) {
                foreach ($choicesRaw as $optName => $val) {
                    if ($val === null) continue;
                    $val = (string)$val;
                    if ($val === '') continue;
                    $pairs[] = ['optionName' => (string)$optName, 'choiceName' => $val];
                }
            } elseif (isset($choicesRaw[0]) && is_array($choicesRaw[0])) {
                foreach ($choicesRaw as $c) {
                    $opt = $c['option'] ?? $c['name'] ?? $c['key'] ?? null;
                    $val = $c['choice'] ?? $c['value'] ?? $c['description'] ?? null;
                    if ($opt !== null && $val !== null && $val !== '') {
                        $pairs[] = ['optionName' => (string)$opt, 'choiceName' => (string)$val];
                    }
                }
            }
        }

        $skuRaw = $flat['sku'] ?? $variant['sku'] ?? null;
        $sku    = (is_string($skuRaw) && trim($skuRaw) !== '') ? $skuRaw : ('SKU-' . uniqid());

        $priceVal = $flat['priceData']['price'] ?? $flat['price'] ?? 0;

        $quantity = $flat['stock']['quantity']
            ?? ($variant['stock']['quantity'] ?? ($product['stock']['quantity'] ?? null));
        $inStock  = $flat['stock']['inStock']
            ?? ($variant['stock']['inStock'] ?? ($product['stock']['inStock'] ?? true));

        $inventoryItem = [];
        if ($quantity !== null && $quantity !== '') {
            $inventoryItem['quantity'] = (int)$quantity;
        } else {
            $inventoryItem['inStock'] = (bool)$inStock;
        }

        $out = [
            'sku'           => $sku,
            'price'         => ['actualPrice' => ['amount' => (string)$priceVal]],
            'inventoryItem' => $inventoryItem,
            'visible'       => (bool)($flat['visible'] ?? true),
        ];

        if (!empty($pairs)) {
            // IMPORTANT: V3 expects an OBJECT with optionChoiceNames, not an array
            $out['choices'] = ['optionChoiceNames' => $pairs];
        }

        if (array_key_exists('weight', $flat) && $flat['weight'] !== null) {
            $out['physicalProperties'] = ['weight' => $flat['weight']];
        }

        return $out;
    }

    private function sanitizeMediaForV3(array $media): array
    {
        $items = $this->extractV3MediaItems($media);
        return $items ? ['itemsInfo' => ['items' => $items]] : [];
    }

    private function extractV3MediaItems(array $media): array
    {
        $items = [];
        $push = function ($m) use (&$items) {
            if (!is_array($m)) return;
            if (!empty($m['image']['url'])) { $items[] = ['url' => $m['image']['url']]; return; }
            if (!empty($m['url']))          { $items[] = ['url' => $m['url']];          return; }
            if (!empty($m['id']))           { $items[] = ['id'  => $m['id']];           return; }
        };

        if (!empty($media['items']) && is_array($media['items'])) {
            foreach ($media['items'] as $m) { $push($m); }
        }
        if (empty($items) && !empty($media['mainMedia'])) {
            $push($media['mainMedia']);
        }
        return $items;
    }

    private function slugifyUnique(string $text): string
    {
        $t = trim(mb_strtolower($text));
        $t = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $t);
        $t = preg_replace('~[^a-z0-9]+~', '-', $t);
        $t = trim($t, '-');
        if ($t === '' || $t === false) {
            $t = 'info-section-'.uniqid();
        }
        return $t;
    }

    // =========================================================
    // Relation maps & ensure/create (V3)
    // =========================================================
    private function prepareRelationMaps($catalogVersion, $fromStoreId, $toStoreId, $accessToken = null)
    {
        $maps = [];
        if ($catalogVersion === 'V3_CATALOG') {
            $maps = [
                'brandIdMap' => WixBrandMigration::where('from_store_id', $fromStoreId)
                    ->where('to_store_id', $toStoreId)
                    ->whereNotNull('destination_brand_id')
                    ->pluck('destination_brand_id', 'source_brand_id')
                    ->toArray(),
                'ribbonIdMap' => WixRibbonMigration::where('from_store_id', $fromStoreId)
                    ->where('to_store_id', $toStoreId)
                    ->whereNotNull('destination_ribbon_id')
                    ->pluck('destination_ribbon_id', 'source_ribbon_id')
                    ->toArray(),
                'customizationIdMap' => WixCustomizationMigration::where('from_store_id', $fromStoreId)
                    ->where('to_store_id', $toStoreId)
                    ->whereNotNull('destination_customization_id')
                    ->pluck('destination_customization_id', 'source_customization_id')
                    ->toArray(),
                'infoSectionIdMap' => WixInfoSectionMigration::where('from_store_id', $fromStoreId)
                    ->where('to_store_id', $toStoreId)
                    ->whereNotNull('destination_info_section_id')
                    ->pluck('destination_info_section_id', 'source_info_section_id')
                    ->toArray(),

                'destBrandsByName'          => [],
                'destRibbonsByName'         => [],
                'destCustomizationsByName'  => [],
                'destInfoSectionsByName'    => [],
            ];

            if ($accessToken) {
                $maps['destBrandsByName']          = $this->getAllBrandsNameMapV3($accessToken);
                $maps['destRibbonsByName']         = $this->getAllRibbonsNameMapV3($accessToken);
                $maps['destCustomizationsByName']  = $this->getAllCustomizationsNameMapV3($accessToken);
                $maps['destInfoSectionsByName']    = $this->getAllInfoSectionsNameMapV3($accessToken);
            }
        }

        $maps['_fromStoreId'] = $fromStoreId;
        $maps['_toStoreId']   = $toStoreId;

        return $maps;
    }

    private function getAllBrandsNameMapV3($accessToken)
    {
        $nameMap = [];
        $cursor = null;
        $hasMore = true;
        $page = 0;

        do {
            $body = [
                'query' => [
                    'cursorPaging' => ['limit' => 100],
                    'sort' => [['fieldName' => 'createdDate', 'order' => 'DESC']],
                ],
                'fields' => ['ASSIGNED_PRODUCTS_COUNT']
            ];
            if ($cursor !== null) {
                $body['query']['cursorPaging']['offset'] = $cursor;
            }

            $resp = Http::withHeaders([
                'Authorization' => $accessToken,
                'Content-Type'  => 'application/json'
            ])->post('https://www.wixapis.com/stores/v3/brands/query', $body);

            $json = $resp->json();
            $brands = $json['brands'] ?? [];
            foreach ($brands as $b) {
                if (!empty($b['name']) && !empty($b['id'])) {
                    $nameMap[mb_strtolower(trim($b['name']))] = $b['id'];
                }
            }

            $hasMore = count($brands) === 100;
            if ($hasMore) $cursor = ($cursor ?? 0) + 100;
            $page++;
            if ($page > 200) break;
        } while ($hasMore);

        return $nameMap;
    }

    private function getAllRibbonsNameMapV3($accessToken)
    {
        $nameMap = [];
        $cursor = null;
        $hasMore = true;
        $page = 0;

        do {
            $body = [
                'query' => [
                    'cursorPaging' => ['limit' => 100],
                    'sort' => [['fieldName' => 'createdDate', 'order' => 'DESC']],
                ],
                'fields' => ['ASSIGNED_PRODUCTS_COUNT']
            ];
            if ($cursor !== null) {
                $body['query']['cursorPaging']['offset'] = $cursor;
            }

            $resp = Http::withHeaders([
                'Authorization' => $accessToken,
                'Content-Type'  => 'application/json'
            ])->post('https://www.wixapis.com/stores/v3/ribbons/query', $body);

            $json = $resp->json();
            $ribbons = $json['ribbons'] ?? [];
            foreach ($ribbons as $r) {
                if (!empty($r['name']) && !empty($r['id'])) {
                    $nameMap[mb_strtolower(trim($r['name']))] = $r['id'];
                }
            }

            $hasMore = count($ribbons) === 100;
            if ($hasMore) $cursor = ($cursor ?? 0) + 100;
            $page++;
            if ($page > 200) break;
        } while ($hasMore);

        return $nameMap;
    }

    private function getAllCustomizationsNameMapV3($accessToken)
    {
        $nameMap = [];
        $cursor = null;
        $hasMore = true;
        $page = 0;

        do {
            $body = [
                'query' => [
                    'cursorPaging' => ['limit' => 100],
                    'sort' => [['fieldName' => 'createdDate', 'order' => 'DESC']],
                ],
                'fields' => ['ASSIGNED_PRODUCTS_COUNT']
            ];
            if ($cursor !== null) {
                $body['query']['cursorPaging']['offset'] = $cursor;
            }

            $resp = Http::withHeaders([
                'Authorization' => $accessToken,
                'Content-Type'  => 'application/json'
            ])->post('https://www.wixapis.com/stores/v3/customizations/query', $body);

            $json = $resp->json();
            $customizations = $json['customizations'] ?? [];
            foreach ($customizations as $c) {
                if (!empty($c['name']) && !empty($c['id'])) {
                    $nameMap[mb_strtolower(trim($c['name']))] = $c['id'];
                }
            }

            $hasMore = count($customizations) === 100;
            if ($hasMore) $cursor = ($cursor ?? 0) + 100;
            $page++;
            if ($page > 200) break;
        } while ($hasMore);

        return $nameMap;
    }

    private function getAllInfoSectionsNameMapV3($accessToken)
    {
        $nameMap = [];
        $cursor = null;
        $hasMore = true;
        $page = 0;

        do {
            $body = [
                'query' => [
                    'cursorPaging' => ['limit' => 100],
                    'sort' => [['fieldName' => 'createdDate', 'order' => 'DESC']],
                ],
                'fields' => ['ASSIGNED_PRODUCTS_COUNT'],
            ];
            if ($cursor !== null) {
                $body['query']['cursorPaging']['offset'] = $cursor;
            }

            $resp = Http::withHeaders([
                'Authorization' => $accessToken,
                'Content-Type'  => 'application/json'
            ])->post('https://www.wixapis.com/stores/v3/info-sections/query', $body);

            $json = $resp->json();
            $sections = $json['infoSections'] ?? [];
            foreach ($sections as $s) {
                if (!empty($s['uniqueName']) && !empty($s['id'])) {
                    $nameMap[mb_strtolower(trim($s['uniqueName']))] = $s['id'];
                }
            }

            $hasMore = count($sections) === 100;
            if ($hasMore) $cursor = ($cursor ?? 0) + 100;
            $page++;
            if ($page > 200) break;
        } while ($hasMore);

        return $nameMap;
    }

    // ---- Ensure/Create entities on V3 ----
    private function createBrandV3($accessToken, $name)
    {
        $name = trim((string)$name);
        if ($name === '') return null;

        $resp = Http::withHeaders([
            'Authorization' => $accessToken,
            'Content-Type'  => 'application/json'
        ])->post('https://www.wixapis.com/stores/v3/brands', ['brand' => ['name' => $name]]);

        $j = $resp->json();
        return $j['brand'] ?? null;
    }

    private function ensureBrandIdV3($accessToken, $sourceBrand, array &$relationMaps)
    {
        if (empty($sourceBrand)) return null;

        $fromStoreId = $relationMaps['_fromStoreId'] ?? null;
        $toStoreId   = $relationMaps['_toStoreId'] ?? null;

        $srcId   = is_array($sourceBrand) ? ($sourceBrand['id']   ?? null) : null;
        $srcName = is_array($sourceBrand) ? ($sourceBrand['name'] ?? null) : null;
        $srcNameKey = $srcName ? mb_strtolower(trim($srcName)) : null;

        if ($srcId && !empty($relationMaps['brandIdMap'][$srcId])) {
            return $relationMaps['brandIdMap'][$srcId];
        }

        if ($srcNameKey && !empty($relationMaps['destBrandsByName'][$srcNameKey])) {
            $destId = $relationMaps['destBrandsByName'][$srcNameKey];
            if ($srcId && $fromStoreId && $toStoreId) {
                WixBrandMigration::updateOrCreate(
                    [
                        'user_id'         => Auth::id() ?: 1,
                        'from_store_id'   => $fromStoreId,
                        'to_store_id'     => $toStoreId,
                        'source_brand_id' => $srcId,
                    ],
                    [
                        'source_brand_name'     => $srcName,
                        'destination_brand_id'  => $destId,
                        'status'                => 'success',
                        'error_message'         => null,
                    ]
                );
                $relationMaps['brandIdMap'][$srcId] = $destId;
            }
            return $destId;
        }

        if ($srcName) {
            $created = $this->createBrandV3($accessToken, $srcName);
            if (!empty($created['id'])) {
                $destId = $created['id'];
                $relationMaps['destBrandsByName'][mb_strtolower($created['name'])] = $destId;

                if ($srcId && $fromStoreId && $toStoreId) {
                    WixBrandMigration::updateOrCreate(
                        [
                            'user_id'         => Auth::id() ?: 1,
                            'from_store_id'   => $fromStoreId,
                            'to_store_id'     => $toStoreId,
                            'source_brand_id' => $srcId,
                        ],
                        [
                            'source_brand_name'     => $created['name'] ?? $srcName,
                            'destination_brand_id'  => $destId,
                            'status'                => 'success',
                            'error_message'         => null,
                        ]
                    );
                    $relationMaps['brandIdMap'][$srcId] = $destId;
                }

                WixHelper::log('Import Products+Inventory', "Brand created in DEST: {$srcName} ({$destId})", 'success');
                return $destId;
            }
            WixHelper::log('Import Products+Inventory', "Failed to create brand: {$srcName}", 'error');
        }

        return null;
    }

    private function createRibbonV3($accessToken, $name)
    {
        $name = trim((string)$name);
        if ($name === '') return null;

        $resp = Http::withHeaders([
            'Authorization' => $accessToken,
            'Content-Type'  => 'application/json'
        ])->post('https://www.wixapis.com/stores/v3/ribbons', ['ribbon' => ['name' => $name]]);

        return $resp->json('ribbon') ?? null;
    }

    private function ensureRibbonIdV3($accessToken, $sourceRibbon, array &$relationMaps)
    {
        if (empty($sourceRibbon)) return null;

        $fromStoreId = $relationMaps['_fromStoreId'] ?? null;
        $toStoreId   = $relationMaps['_toStoreId'] ?? null;

        $srcId   = is_array($sourceRibbon) ? ($sourceRibbon['id']   ?? null) : null;
        $srcName = is_array($sourceRibbon) ? ($sourceRibbon['name'] ?? null) : null;
        $srcNameKey = $srcName ? mb_strtolower(trim($srcName)) : null;

        if ($srcId && !empty($relationMaps['ribbonIdMap'][$srcId])) {
            return $relationMaps['ribbonIdMap'][$srcId];
        }

        if ($srcNameKey && !empty($relationMaps['destRibbonsByName'][$srcNameKey])) {
            $destId = $relationMaps['destRibbonsByName'][$srcNameKey];

            if ($srcId && $fromStoreId && $toStoreId) {
                WixRibbonMigration::updateOrCreate(
                    [
                        'user_id'          => Auth::id() ?: 1,
                        'from_store_id'    => $fromStoreId,
                        'to_store_id'      => $toStoreId,
                        'source_ribbon_id' => $srcId,
                    ],
                    [
                        'source_ribbon_name'     => $srcName,
                        'destination_ribbon_id'  => $destId,
                        'status'                 => 'success',
                        'error_message'          => null,
                    ]
                );
                $relationMaps['ribbonIdMap'][$srcId] = $destId;
            }

            return $destId;
        }

        if ($srcName) {
            $created = $this->createRibbonV3($accessToken, $srcName);
            if (!empty($created['id'])) {
                $destId = $created['id'];
                $relationMaps['destRibbonsByName'][mb_strtolower($created['name'])] = $destId;

                if ($srcId && $fromStoreId && $toStoreId) {
                    WixRibbonMigration::updateOrCreate(
                        [
                            'user_id'          => Auth::id() ?: 1,
                            'from_store_id'    => $fromStoreId,
                            'to_store_id'      => $toStoreId,
                            'source_ribbon_id' => $srcId,
                        ],
                        [
                            'source_ribbon_name'     => $created['name'] ?? $srcName,
                            'destination_ribbon_id'  => $destId,
                            'status'                 => 'success',
                            'error_message'          => null,
                        ]
                    );
                    $relationMaps['ribbonIdMap'][$srcId] = $destId;
                }

                WixHelper::log('Import Products+Inventory', "Ribbon created in DEST: {$srcName} ({$destId})", 'success');
                return $destId;
            }
            WixHelper::log('Import Products+Inventory', "Failed to create ribbon: {$srcName}", 'error');
        }

        return null;
    }

    private function createCustomizationV3($accessToken, array $src)
    {
        $name  = trim((string)($src['name'] ?? ''));
        if ($name === '') return null;

        $payload = [
            'name'                   => $name,
            'customizationType'      => $src['customizationType'] ?? ($src['type'] ?? ''),
            'customizationRenderType'=> $src['customizationRenderType'] ?? ($src['renderType'] ?? ''),
        ];

        $renderType = $payload['customizationRenderType'];
        if (!empty($src['choicesSettings']) && in_array($renderType, ['SWATCH_CHOICES','TEXT_CHOICES'], true)) {
            $payload['choicesSettings'] = $src['choicesSettings'];
        }
        if (!empty($src['freeTextInput']) && $renderType === 'FREE_TEXT') {
            $payload['freeTextInput'] = $src['freeTextInput'];
        }

        $resp = Http::withHeaders([
            'Authorization' => $accessToken,
            'Content-Type'  => 'application/json'
        ])->post('https://www.wixapis.com/stores/v3/customizations', [
            'customization' => $payload
        ]);

        return $resp->json('customization') ?? null;
    }

    private function ensureCustomizationIdV3($accessToken, array $source, ?array $sourceEnriched, array &$relationMaps)
    {
        $fromStoreId = $relationMaps['_fromStoreId'] ?? null;
        $toStoreId   = $relationMaps['_toStoreId'] ?? null;

        $srcId   = $source['id']   ?? null;
        $srcName = $source['name'] ?? ($sourceEnriched['name'] ?? null);
        $srcNameKey = $srcName ? mb_strtolower(trim($srcName)) : null;

        if ($srcId && !empty($relationMaps['customizationIdMap'][$srcId])) {
            return $relationMaps['customizationIdMap'][$srcId];
        }

        if ($srcNameKey && !empty($relationMaps['destCustomizationsByName'][$srcNameKey])) {
            $destId = $relationMaps['destCustomizationsByName'][$srcNameKey];

            if ($srcId && $fromStoreId && $toStoreId) {
                WixCustomizationMigration::updateOrCreate(
                    [
                        'user_id'                   => Auth::id() ?: 1,
                        'from_store_id'             => $fromStoreId,
                        'to_store_id'               => $toStoreId,
                        'source_customization_id'   => $srcId,
                    ],
                    [
                        'source_customization_name'    => $srcName,
                        'destination_customization_id'  => $destId,
                        'status'                        => 'success',
                        'error_message'                 => null,
                    ]
                );
                $relationMaps['customizationIdMap'][$srcId] = $destId;
            }

            return $destId;
        }

        $toCreate = $sourceEnriched ?: $source;
        if (!empty($toCreate['name'])) {
            $created = $this->createCustomizationV3($accessToken, $toCreate);
            if (!empty($created['id'])) {
                $destId = $created['id'];
                $relationMaps['destCustomizationsByName'][mb_strtolower($created['name'])] = $destId;

                if ($srcId && $fromStoreId && $toStoreId) {
                    WixCustomizationMigration::updateOrCreate(
                        [
                            'user_id'                   => Auth::id() ?: 1,
                            'from_store_id'             => $fromStoreId,
                            'to_store_id'               => $toStoreId,
                            'source_customization_id'   => $srcId,
                        ],
                        [
                            'source_customization_name'    => $created['name'] ?? $toCreate['name'],
                            'destination_customization_id'  => $destId,
                            'status'                        => 'success',
                            'error_message'                 => null,
                        ]
                    );
                    $relationMaps['customizationIdMap'][$srcId] = $destId;
                }

                WixHelper::log('Import Products+Inventory', "Customization created in DEST: {$toCreate['name']} ({$destId})", 'success');
                return $destId;
            }
            WixHelper::log('Import Products+Inventory', "Failed to create customization: {$toCreate['name']}", 'error');
        }

        return null;
    }

    private function createInfoSectionV3($accessToken, array $src)
    {
        $uniqueName = trim((string)($src['uniqueName'] ?? ''));
        if ($uniqueName === '') return null;

        $payload = [
            'uniqueName'       => $uniqueName,
            'title'            => $src['title'] ?? '',
            'plainDescription' => is_string($src['plainDescription'] ?? null) ? $src['plainDescription'] : ''
        ];

        $resp = Http::withHeaders([
            'Authorization' => $accessToken,
            'Content-Type'  => 'application/json'
        ])->post('https://www.wixapis.com/stores/v3/info-sections', [
            'infoSection' => $payload
        ]);

        return $resp->json('infoSection') ?? null;
    }

    private function ensureInfoSectionIdV3($accessToken, array $source, ?array $enriched, array &$relationMaps)
    {
        $fromStoreId = $relationMaps['_fromStoreId'] ?? null;
        $toStoreId   = $relationMaps['_toStoreId'] ?? null;

        $srcId        = $source['id'] ?? null;
        $srcUnique    = $source['uniqueName'] ?? ($enriched['uniqueName'] ?? null);
        $srcUniqueKey = $srcUnique ? mb_strtolower(trim($srcUnique)) : null;

        if ($srcId && !empty($relationMaps['infoSectionIdMap'][$srcId])) {
            return $relationMaps['infoSectionIdMap'][$srcId];
        }

        if ($srcUniqueKey && !empty($relationMaps['destInfoSectionsByName'][$srcUniqueKey])) {
            $destId = $relationMaps['destInfoSectionsByName'][$srcUniqueKey];

            if ($srcId && $fromStoreId && $toStoreId) {
                WixInfoSectionMigration::updateOrCreate(
                    [
                        'user_id'                => Auth::id() ?: 1,
                        'from_store_id'          => $fromStoreId,
                        'to_store_id'            => $toStoreId,
                        'source_info_section_id' => $srcId,
                    ],
                    [
                        'source_info_section_name'     => $srcUnique,
                        'destination_info_section_id'  => $destId,
                        'status'                       => 'success',
                        'error_message'                => null,
                    ]
                );
                $relationMaps['infoSectionIdMap'][$srcId] = $destId;
            }

            return $destId;
        }

        $toCreate = $enriched ?: $source;
        if (!empty($toCreate['uniqueName'])) {
            $created = $this->createInfoSectionV3($accessToken, $toCreate);
            if (!empty($created['id'])) {
                $destId = $created['id'];
                $relationMaps['destInfoSectionsByName'][mb_strtolower($created['uniqueName'])] = $destId;

                if ($srcId && $fromStoreId && $toStoreId) {
                    WixInfoSectionMigration::updateOrCreate(
                        [
                            'user_id'                => Auth::id() ?: 1,
                            'from_store_id'          => $fromStoreId,
                            'to_store_id'            => $toStoreId,
                            'source_info_section_id' => $srcId,
                        ],
                        [
                            'source_info_section_name'     => $created['uniqueName'] ?? $toCreate['uniqueName'],
                            'destination_info_section_id'  => $destId,
                            'status'                       => 'success',
                            'error_message'                => null,
                        ]
                    );
                    $relationMaps['infoSectionIdMap'][$srcId] = $destId;
                }

                WixHelper::log('Import Products+Inventory', "InfoSection created in DEST: {$toCreate['uniqueName']} ({$destId})", 'success');
                return $destId;
            }

            WixHelper::log('Import Products+Inventory', "Failed to create InfoSection: {$toCreate['uniqueName']}", 'error');
        }

        return null;
    }

    // =========================================================
    // V3 entity batch fetch helper
    // =========================================================
    private function fetchAllV3EntityMap(string $accessToken, string $label, string $endpoint, string $key): array
    {
        $map = [];
        $cursor = null;
        $hasMore = true;
        $page = 0;

        do {
            $body = [
                'query' => [
                    'cursorPaging' => ['limit' => 100],
                    'sort' => [['fieldName' => 'createdDate', 'order' => 'DESC']],
                ],
                'fields' => ['ASSIGNED_PRODUCTS_COUNT']
            ];
            if ($cursor !== null) {
                $body['query']['cursorPaging']['offset'] = $cursor;
            }

            $resp = Http::withHeaders([
                'Authorization' => $accessToken,
                'Content-Type'  => 'application/json'
            ])->post($endpoint, $body);

            $json = $resp->json();
            $items = $json[$key] ?? [];
            foreach ($items as $it) {
                if (!empty($it['id'])) $map[$it['id']] = $it;
            }

            $hasMore = count($items) === 100;
            if ($hasMore) $cursor = ($cursor ?? 0) + 100;
            $page++;
            if ($page > 200) break;
        } while ($hasMore);

        Log::debug("Wix export: fetched V3 {$label}", ['count' => count($map)]);
        return $map;
    }
}
