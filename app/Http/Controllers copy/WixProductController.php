<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

use App\Models\WixStore;
use App\Helpers\WixHelper;

use App\Models\WixProductMigration;
use App\Models\WixBrandMigration;
use App\Models\WixCollectionMigration;
use App\Models\WixRibbonMigration;
use App\Models\WixCustomizationMigration;
use App\Models\WixInfoSectionMigration;
use Illuminate\Support\Facades\DB;

class WixProductController extends Controller
{
    // Filter product array for import
    public function filterWixProductForImport($product)
    {
        $allowedFields = [
            "name", "slug", "visible", "productType", "description", "sku", "media",
            "manageVariants", "productOptions", "additionalInfoSections", "ribbon", "brand",
            "infoSections", "modifiers", "price", "priceData", "discount", "weight", "stock", "costAndProfitData", 'customTextFields'
        ];

        $filtered = [];
        foreach ($allowedFields as $field) {
            if (isset($product[$field])) {
                $filtered[$field] = $product[$field];
            }
        }

        $validTypes = ['unspecified_product_type', 'physical', 'digital'];
        $filtered['productType'] = isset($filtered['productType']) && in_array(strtolower($filtered['productType']), $validTypes)
            ? strtolower($filtered['productType'])
            : 'physical';

        // Ensure a valid slug for V1 as well (Wix may validate)
        $productName = $product['name'] ?? '';
        $desiredSlug = $product['slug'] ?? null;
        $filtered['slug'] = $this->slugifyProduct($productName, $desiredSlug);

        unset($filtered['id']);
        return $filtered;
    }


    // ========================================================= Automatic Migrator =========================================================
    public function migrateAuto(Request $request)
    {
        // Only accept values from the form body
        $data = $request->validate([
            'from_store' => 'required|string',
            'to_store'   => 'required|string|different:from_store',
        ]);

        $fromInput = $data['from_store'];
        $toInput   = $data['to_store'];

        // Resolve by numeric id OR by instance_id string
        $from = is_numeric($fromInput)
            ? \App\Models\WixStore::find((int) $fromInput)
            : \App\Models\WixStore::where('instance_id', $fromInput)->first();

        $to = is_numeric($toInput)
            ? \App\Models\WixStore::find((int) $toInput)
            : \App\Models\WixStore::where('instance_id', $toInput)->first();

        if (!$from || !$to) {
            return back()->with('error', 'Could not resolve one or both stores from the selected values.');
        }

        if ($from->instance_id === $to->instance_id) {
            return back()->with('error', 'Source and target stores must be different.');
        }

        // Optional: nicer logs using your hidden fields
        $step   = $request->input('module_step', 'Products');
        $module = $request->input('module', 'products');
        \App\Helpers\WixHelper::log('Auto Products', "BEGIN [$module/$step]: {$from->store_name} → {$to->store_name}", 'info');

        // Reuse your existing pipeline (unchanged)
        return $this->migrator($request, $from, $to);
    }

    /**
     * Core pipeline: read from source, enrich (variants/inventory/collections),
     * then import into target and update WixProductMigration rows as we go.
     */
    public function migrator(Request $request, WixStore $from, WixStore $to)
    {
        $userId      = Auth::id() ?: 1;
        $fromStoreId = $from->instance_id;
        $toStoreId   = $to->instance_id;

        WixHelper::log('Auto Products', "BEGIN: {$from->store_name} → {$to->store_name}", 'info');

        $fromToken = WixHelper::getAccessToken($fromStoreId);
        $toToken   = WixHelper::getAccessToken($toStoreId);
        if (!$fromToken || !$toToken) {
            return back()->with('error', 'Unauthorized: missing access token(s).');
        }

        $max    = (int) $request->input('max', 0);
        $dryRun = filter_var($request->input('dry_run', false), FILTER_VALIDATE_BOOLEAN);

        // ---------- Fetch source products ----------
        $allProductsResp = $this->getAllProducts($fromToken, $from);
        $products        = $allProductsResp['products'] ?? [];

        // Fetch source inventory (to attach quantities by SKU)
        $inventoryItems  = $this->queryInventoryItems($fromToken)['inventoryItems'] ?? [];
        $skuInventoryMap = [];
        foreach ($inventoryItems as $inv) {
            if (!empty($inv['sku'])) $skuInventoryMap[$inv['sku']] = $inv;
        }

        // Map collections (ID -> slug) for the source so we can attach slugs
        $collectionIdToSlug = [];
        try {
            $catController = app(\App\Http\Controllers\WixCategoryController::class);
            $collectionsArr = $catController->getCollectionsFromWix($fromToken);
            foreach (($collectionsArr['collections'] ?? []) as $c) {
                if (!empty($c['id']) && !empty($c['slug'])) {
                    $collectionIdToSlug[$c['id']] = $c['slug'];
                }
            }
        } catch (\Throwable $e) {
            $collectionsResp = Http::withHeaders([
                'Authorization' => $fromToken,
                'Content-Type'  => 'application/json'
            ])->post('https://www.wixapis.com/stores-reader/v1/collections/query', [
                "paging" => ["limit" => 1000]
            ]);
            foreach ($collectionsResp->json('collections') ?? [] as $c) {
                if (!empty($c['id']) && !empty($c['slug'])) {
                    $collectionIdToSlug[$c['id']] = $c['slug'];
                }
            }
        }

        // Enrich each product with variants (+inventory) and collection slugs
        foreach ($products as &$p) {
            // Attach product-level inventory
            if (!empty($p['sku']) && isset($skuInventoryMap[$p['sku']])) {
                $inv = $skuInventoryMap[$p['sku']];
                $p['stock'] = [
                    'quantity' => $inv['quantity'] ?? null,
                    'inStock'  => $inv['inStock'] ?? (isset($inv['quantity']) ? ((int)$inv['quantity'] > 0) : true),
                ];
            }

            // Variants full (for richer import on V3/V1)
            if (!empty($p['id'])) {
                $variantResp = Http::withHeaders([
                    'Authorization' => $fromToken,
                    'Content-Type'  => 'application/json'
                ])->post("https://www.wixapis.com/stores-reader/v1/products/{$p['id']}/variants/query", [
                    "includeMerchantSpecificData" => true
                ]);
                $variantsFull = $variantResp->json('variants') ?? [];

                // Attach inventory to each variant by SKU (as stock)
                foreach ($variantsFull as &$vf) {
                    $flatSku = $vf['variant']['sku'] ?? $vf['sku'] ?? null;
                    if ($flatSku && isset($skuInventoryMap[$flatSku])) {
                        $inv = $skuInventoryMap[$flatSku];
                        $vf['stock'] = [
                            'quantity' => $inv['quantity'] ?? null,
                            'inStock'  => $inv['inStock'] ?? (isset($inv['quantity']) ? ((int)$inv['quantity'] > 0) : true),
                        ];
                    }
                }
                unset($vf);

                if ($variantsFull) {
                    $p['variants_full'] = $variantsFull;
                }
            }

            // Add collectionSlugs array for this product
            $p['collectionSlugs'] = [];
            if (!empty($p['collectionIds']) && is_array($p['collectionIds'])) {
                foreach ($p['collectionIds'] as $colId) {
                    if (isset($collectionIdToSlug[$colId])) {
                        $p['collectionSlugs'][] = $collectionIdToSlug[$colId];
                    }
                }
            }
        }
        unset($p);

        // Oldest-first (createdDate)
        usort($products, function ($a, $b) {
            $ta = isset($a['createdDate']) ? strtotime($a['createdDate']) : 0;
            $tb = isset($b['createdDate']) ? strtotime($b['createdDate']) : 0;
            return $ta <=> $tb;
        });

        if ($max > 0) {
            $products = array_slice($products, 0, $max);
        }

        // ---------- Prepare destination mapping caches ----------
        $destCatalogVersion = WixHelper::getCatalogVersion($toToken);
        $relationMaps       = $this->prepareRelationMaps($destCatalogVersion, $fromStoreId, $toStoreId, $toToken);
        $collectionSlugMap  = []; // V1 fallback cache

        // ---------- Process ----------
        $imported         = 0;
        $inventoryUpdated = 0;
        $failed           = 0;
        $skipped          = 0;

        foreach ($products as $product) {
            $sourceId  = $product['id'] ?? null;
            $sourceSku = $product['sku'] ?? null;

            // Ensure/merge a migration row for this product and target
            $migrationKey = [
                'user_id'           => $userId,
                'from_store_id'     => $fromStoreId,
                'to_store_id'       => $toStoreId,
                'source_product_id' => $sourceId,
            ];
            \App\Models\WixProductMigration::updateOrCreate(
                $migrationKey,
                [
                    'source_product_sku'   => $sourceSku,
                    'source_product_name'  => $product['name'] ?? null,
                    'status'               => 'pending',
                    'error_message'        => null,
                    'destination_product_id' => null,
                ]
            );

            if ($dryRun) {
                \App\Models\WixProductMigration::where($migrationKey)->update([
                    'status'        => 'skipped',
                    'error_message' => 'dry_run: would import this product'
                ]);
                $skipped++;
                continue;
            }

            // Import into DEST
            [$result, $err] = $this->importSingleProduct(
                $destCatalogVersion,
                $toToken,
                $product,
                $fromStoreId,
                $toStoreId,
                $userId,
                $relationMaps,
                $collectionSlugMap
            );

            // Update row
            if ($result['imported']) {
                \App\Models\WixProductMigration::where($migrationKey)->update([
                    'status'                 => 'success',
                    'error_message'          => null,
                    'destination_product_id' => \Illuminate\Support\Arr::get($result, 'destination_product_id')
                        ?: (\Illuminate\Support\Arr::get($result, 'product_id') ?: null),
                ]);
                $imported += (int) $result['imported'];
                $inventoryUpdated += (int) $result['inventoryUpdated'];
            } else {
                \App\Models\WixProductMigration::where($migrationKey)->update([
                    'status'        => 'failed',
                    'error_message' => $result['error'] ?: ($err ?: 'Unknown error'),
                ]);
                $failed++;
            }
        }

        // ---------- Summary ----------
        $msg = "Auto Products: imported={$imported}, inventory={$inventoryUpdated}, skipped={$skipped}, failed={$failed}";
        WixHelper::log('Auto Products', $msg, $failed ? 'warn' : 'success');

        if ($imported > 0) {
            return back()->with('success', $msg);
        }
        return back()->with($failed ? 'error' : 'success', $msg);
    }

    // ========================================================= Manual Migrator =========================================================
    // =========================================================
    // Export PRODUCTS + INVENTORY
    // =========================================================
    public function export(WixStore $store)
    {
        WixHelper::log('Export Products+Inventory', "Export started for store: $store->store_name", 'info');

        $accessToken = WixHelper::getAccessToken($store->instance_id);
        if (!$accessToken) {
            return response()->json(['error' => 'Could not get Wix access token.'], 401);
        }

        // Get catalog version
        $catalogVersion = WixHelper::getCatalogVersion($accessToken);

        // Fetch all collections
        $collectionIdToSlug = [];
        try {
            $catController = app(\App\Http\Controllers\WixCategoryController::class);
            $collectionsArr = $catController->getCollectionsFromWix($accessToken);

            foreach (($collectionsArr['collections'] ?? []) as $c) {
                if (!empty($c['id']) && !empty($c['slug'])) {
                    $collectionIdToSlug[$c['id']] = $c['slug'];
                }
            }
            // Debug
            Log::debug('Wix export: collections', [
                'count' => count($collectionIdToSlug),
                'sample' => array_slice($collectionIdToSlug, 0, 3)
            ]);
        } catch (\Throwable $e) {
            $collectionsResp = Http::withHeaders([
                'Authorization' => $accessToken,
                'Content-Type'  => 'application/json'
            ])->post('https://www.wixapis.com/stores-reader/v1/collections/query', [
                "paging" => ["limit" => 1000]
            ]);
            $collections = $collectionsResp->json('collections') ?? [];
            foreach ($collections as $c) {
                if (!empty($c['id']) && !empty($c['slug'])) {
                    $collectionIdToSlug[$c['id']] = $c['slug'];
                }
            }
            Log::debug('Wix export: collections fallback', [
                'count' => count($collectionIdToSlug),
                'sample' => array_slice($collectionIdToSlug, 0, 3),
                'error' => $e->getMessage()
            ]);
        }

        // Get all products
        $productsResponse = $this->getAllProducts($accessToken, $store);
        $products = $productsResponse['products'] ?? [];

        // Get all inventory items
        $inventoryItems = $this->queryInventoryItems($accessToken)['inventoryItems'] ?? [];
        $skuInventoryMap = [];
        foreach ($inventoryItems as $inv) {
            if (!empty($inv['sku'])) {
                $skuInventoryMap[$inv['sku']] = $inv;
            }
        }

        $userId = Auth::id() ?? 1;
        $fromStoreId = $store->instance_id;

        // Fetch brands + ribbons + infoSections + customizations
        $brands = $ribbons = $infoSections = $customizations = [];
        if ($catalogVersion === 'V3_CATALOG') {
            // --- BRANDS ---
            $brandsResp = Http::withHeaders([
                'Authorization' => $accessToken,
                'Content-Type'  => 'application/json'
            ])->post('https://www.wixapis.com/stores/v3/brands/query', [
                'query' => ['cursorPaging' => ['limit' => 100]],
                'fields' => [],
            ]);
            foreach ($brandsResp->json('brands') ?? [] as $brand) {
                $brands[$brand['id']] = $brand;
            }

            // --- RIBBONS ---
            $ribbonsResp = Http::withHeaders([
                'Authorization' => $accessToken,
                'Content-Type'  => 'application/json'
            ])->post('https://www.wixapis.com/stores/v3/ribbons/query', [
                'query' => ['cursorPaging' => ['limit' => 100]],
                'fields' => [],
            ]);
            foreach ($ribbonsResp->json('ribbons') ?? [] as $ribbon) {
                $ribbons[$ribbon['id']] = $ribbon;
            }

            // --- INFO SECTIONS ---
            $infoSectionsResp = Http::withHeaders([
                'Authorization' => $accessToken,
                'Content-Type'  => 'application/json'
            ])->post('https://www.wixapis.com/stores/v3/info-sections/query', [
                'query' => ['cursorPaging' => ['limit' => 100]],
                'fields' => [],
            ]);
            foreach ($infoSectionsResp->json('infoSections') ?? [] as $info) {
                $infoSections[$info['id']] = $info;
            }

            // --- CUSTOMIZATIONS ---
            $customizationsResp = Http::withHeaders([
                'Authorization' => $accessToken,
                'Content-Type'  => 'application/json'
            ])->post('https://www.wixapis.com/stores/v3/customizations/query', [
                'query' => ['cursorPaging' => ['limit' => 100]],
                'fields' => [],
            ]);
            foreach ($customizationsResp->json('customizations') ?? [] as $cust) {
                $customizations[$cust['id']] = $cust;
            }
        }

        foreach ($products as &$product) {
            // Attach inventory by SKU
            $sku = $product['sku'] ?? null;
            if ($sku && isset($skuInventoryMap[$sku])) {
                $product['inventory'] = $skuInventoryMap[$sku];
            }

            // Query full variant details
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

            // Attach inventory to each variant by SKU
            if ($variants_full) {
                foreach ($variants_full as &$v) {
                    $vSku = $v['variant']['sku'] ?? null;
                    if ($vSku && isset($skuInventoryMap[$vSku])) {
                        $v['inventory'] = $skuInventoryMap[$vSku];
                    }
                }
                $product['variants_full'] = $variants_full;
            }

            // Add collectionSlugs array for this product
            $product['collectionSlugs'] = [];
            if (!empty($product['collectionIds']) && is_array($product['collectionIds'])) {
                foreach ($product['collectionIds'] as $colId) {
                    if (isset($collectionIdToSlug[$colId])) {
                        $product['collectionSlugs'][] = $collectionIdToSlug[$colId];
                    }
                }
            }

            // --- Export details of brands + ribbons + infoSections + customizations (if any) ---
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
                        $id = is_array($info) ? $info['id'] ?? $info : $info;
                        if ($id && isset($infoSections[$id])) {
                            $product['infoSections_export'][] = $infoSections[$id];
                        }
                    }
                }
                if (!empty($product['customizations'])) {
                    $product['customizations_export'] = [];
                    foreach ($product['customizations'] as $cust) {
                        $id = is_array($cust) ? $cust['id'] ?? $cust : $cust;
                        if ($id && isset($customizations[$id])) {
                            $product['customizations_export'][] = $customizations[$id];
                        }
                    }
                }
            }

            // Store migration info in DB
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

        WixHelper::log('Export Products+Inventory', "Exported " . count($products) . " products with inventory and variants.", 'success');

        return response()->streamDownload(function () use ($products, $store) {
            echo json_encode([
                'from_store_id' => $store->instance_id,
                'products' => $products
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }, 'products_and_inventory.json', [
            'Content-Type' => 'application/json'
        ]);
    }

    // =========================================================
    // Import PRODUCTS + INVENTORY
    // =========================================================
    public function import(Request $request, WixStore $store)
    {
        $accessToken = WixHelper::getAccessToken($store->instance_id);
        if (!$accessToken) {
            WixHelper::log('Import Products+Inventory', 'Could not get Wix access token.', 'error');
            return back()->with('error', 'Could not get Wix access token.');
        }

        if (!$request->hasFile('products_json')) {
            WixHelper::log('Import Products+Inventory', 'No file uploaded.', 'error');
            return back()->with('error', 'No file uploaded.');
        }

        $file = $request->file('products_json');
        $json = file_get_contents($file->getRealPath());
        $decoded = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE || !isset($decoded['from_store_id'], $decoded['products']) || !is_array($decoded['products'])) {
            WixHelper::log('Import Products+Inventory', 'Invalid JSON structure.', 'error');
            return back()->with('error', 'Invalid JSON structure. Required keys: from_store_id and products.');
        }

        $fromStoreId = $decoded['from_store_id'];
        $products = $decoded['products'];

        usort($products, function ($a, $b) {
            $dateA = isset($a['createdDate']) ? strtotime($a['createdDate']) : 0;
            $dateB = isset($b['createdDate']) ? strtotime($b['createdDate']) : 0;
            return $dateA <=> $dateB;
        });

        $catalogVersion = WixHelper::getCatalogVersion($accessToken);

        $imported = 0;
        $inventoryUpdated = 0;
        $errors = [];

        $collectionSlugMap = [];
        $userId = Auth::id() ?? 1;

        // pass $accessToken so we can pre-cache destination brands for dedupe
        $relationMaps = $this->prepareRelationMaps($catalogVersion, $fromStoreId, $store->instance_id, $accessToken);

        foreach ($products as $product) {
            [$result, $error] = $this->importSingleProduct(
                $catalogVersion,
                $accessToken,
                $product,
                $fromStoreId,
                $store->instance_id,
                $userId,
                $relationMaps,
                $collectionSlugMap
            );

            if ($result['imported']) $imported++;
            if ($result['inventoryUpdated']) $inventoryUpdated++;
            if ($result['error']) $errors[] = $result['error'];
        }

        if ($imported > 0) {
            WixHelper::log('Import Products+Inventory', [
                'imported' => $imported,
                'inventoryUpdated' => $inventoryUpdated,
                'errors' => $errors
            ], count($errors) ? 'error' : 'success');
            return back()->with('success', "$imported product(s) imported. $inventoryUpdated inventory item(s) created.");
        } else {
            WixHelper::log('Import Products+Inventory', [
                'imported' => $imported,
                'inventoryUpdated' => $inventoryUpdated,
                'errors' => $errors
            ], 'error');
            return back()->with('error', 'No products imported. Errors: ' . implode("; ", $errors));
        }
    }

    // =========================================================
    // Utility
    // =========================================================
    // Get all products with paging
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
                                'field_name' => 'createdDate'
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

            WixHelper::log('Export Products+Inventory', "Fetched batch #$callCount, products so far: ".count($products), 'info');
        } while ($hasMore);

        WixHelper::log('Export Products+Inventory', "Total products fetched for export: ".count($products), 'info');
        return ['products' => $products];
    }

    // Get all inventory items ---
    public function queryInventoryItems($accessToken, $query = [])
    {
        // Detect catalog version
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

    // ========= Map brands + ribbons + infoSections + customizations to Products =========
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


    /**
     * Query ALL brands in the destination (V3) and return [strtolower(name) => id].
     * Handles paging safely.
     */
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

            // naive paging by offset
            $hasMore = count($brands) === 100;
            if ($hasMore) $cursor = ($cursor ?? 0) + 100;
            $page++;
            if ($page > 200) break;
        } while ($hasMore);

        return $nameMap;
    }

    /** Create a brand in V3, return ['id' => '...', 'name' => '...'] or null */
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

    /** Ensure destination brand exists (accepts string or array). Returns dest brand ID or null. */
    private function ensureBrandIdV3($accessToken, $sourceBrand, array &$relationMaps)
    {
        if (empty($sourceBrand)) return null;

        $fromStoreId = $relationMaps['_fromStoreId'] ?? null;
        $toStoreId   = $relationMaps['_toStoreId'] ?? null;

        // Allow string input from V1 (e.g. "Nike")
        if (is_string($sourceBrand)) {
            $srcId = null;
            $srcName = trim($sourceBrand);
        } else {
            $srcId   = $sourceBrand['id']   ?? null;
            $srcName = $sourceBrand['name'] ?? null;
        }
        $srcNameKey = $srcName ? mb_strtolower(trim($srcName)) : null;

        // 1) Existing migration by source ID
        if ($srcId && !empty($relationMaps['brandIdMap'][$srcId])) {
            return $relationMaps['brandIdMap'][$srcId];
        }

        // 2) Try name cache from DEST
        if ($srcNameKey && !empty($relationMaps['destBrandsByName'][$srcNameKey])) {
            $destId = $relationMaps['destBrandsByName'][$srcNameKey];

            // Upsert migration map so future imports by source ID are mapped
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

        // 3) Create brand if we have a name
        if ($srcName) {
            $created = $this->createBrandV3($accessToken, $srcName);
            if (!empty($created['id'])) {
                $destId = $created['id'];
                $createdName = $created['name'] ?? $srcName;
                $relationMaps['destBrandsByName'][mb_strtolower($createdName)] = $destId;

                if ($srcId && $fromStoreId && $toStoreId) {
                    WixBrandMigration::updateOrCreate(
                        [
                            'user_id'         => Auth::id() ?: 1,
                            'from_store_id'   => $fromStoreId,
                            'to_store_id'     => $toStoreId,
                            'source_brand_id' => $srcId,
                        ],
                        [
                            'source_brand_name'     => $createdName,
                            'destination_brand_id'  => $destId,
                            'status'                => 'success',
                            'error_message'         => null,
                        ]
                    );
                    $relationMaps['brandIdMap'][$srcId] = $destId;
                }

                WixHelper::log('Import Products+Inventory', "Brand ensured in DEST: {$createdName} ({$destId})", 'success');
                return $destId;
            }
            WixHelper::log('Import Products+Inventory', "Failed to create brand: {$srcName}", 'error');
        }

        return null;
    }

    /**
     * Query ALL ribbons in V3 and return [strtolower(name) => id]
     */
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

    /** Create a ribbon in V3, return ['id' => '...', 'name' => '...'] or null */
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

    /** Ensure destination ribbon exists (accepts string or array). Returns dest ribbon ID or null. */
    private function ensureRibbonIdV3($accessToken, $sourceRibbon, array &$relationMaps)
    {
        if (empty($sourceRibbon)) return null;

        $fromStoreId = $relationMaps['_fromStoreId'] ?? null;
        $toStoreId   = $relationMaps['_toStoreId'] ?? null;

        // Allow string input from V1 (e.g. "Sale")
        if (is_string($sourceRibbon)) {
            $srcId = null;
            $srcName = trim($sourceRibbon);
        } else {
            $srcId   = $sourceRibbon['id']   ?? null;
            $srcName = $sourceRibbon['name'] ?? null;
        }
        $srcNameKey = $srcName ? mb_strtolower(trim($srcName)) : null;

        // 1) existing migration by source ID
        if ($srcId && !empty($relationMaps['ribbonIdMap'][$srcId])) {
            return $relationMaps['ribbonIdMap'][$srcId];
        }

        // 2) name cache in DEST
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

        // 3) create ribbon if we have a name
        if ($srcName) {
            $created = $this->createRibbonV3($accessToken, $srcName);
            if (!empty($created['id'])) {
                $destId = $created['id'];
                $createdName = $created['name'] ?? $srcName;
                $relationMaps['destRibbonsByName'][mb_strtolower($createdName)] = $destId;

                if ($srcId && $fromStoreId && $toStoreId) {
                    WixRibbonMigration::updateOrCreate(
                        [
                            'user_id'          => Auth::id() ?: 1,
                            'from_store_id'    => $fromStoreId,
                            'to_store_id'      => $toStoreId,
                            'source_ribbon_id' => $srcId,
                        ],
                        [
                            'source_ribbon_name'     => $createdName,
                            'destination_ribbon_id'  => $destId,
                            'status'                 => 'success',
                            'error_message'          => null,
                        ]
                    );
                    $relationMaps['ribbonIdMap'][$srcId] = $destId;
                }

                WixHelper::log('Import Products+Inventory', "Ribbon ensured in DEST: {$createdName} ({$destId})", 'success');
                return $destId;
            }
            WixHelper::log('Import Products+Inventory', "Failed to create ribbon: {$srcName}", 'error');
        }

        return null;
    }

    /**
     * Query ALL customizations in DEST (V3) and return [strtolower(name) => id]
     */
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

    /**
     * Fetch ALL info sections in DEST (V3).
     * Return map: [strtolower(uniqueName) => id]
     */
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

    /** Create an info section in V3; prefer plainDescription if RICOS not available */
    private function createInfoSectionV3($accessToken, array $src)
    {
        $uniqueName = trim((string)($src['uniqueName'] ?? ''));
        if ($uniqueName === '') return null;

        $payload = [
            'uniqueName'  => $uniqueName,
            'title'       => $src['title'] ?? '',
        ];

        // If we have a rich description array -> pass as 'description', otherwise use 'plainDescription'
        if (!empty($src['description']) && is_array($src['description'])) {
            $payload['description'] = $src['description'];
        } else {
            $plain = $src['plainDescription'] ?? $src['description'] ?? '';
            if (!is_string($plain)) $plain = '';
            $payload['plainDescription'] = $plain;
        }

        $resp = Http::withHeaders([
            'Authorization' => $accessToken,
            'Content-Type'  => 'application/json'
        ])->post('https://www.wixapis.com/stores/v3/info-sections', [
            'infoSection' => $payload
        ]);

        return $resp->json('infoSection') ?? null;
    }

    /**
     * Ensure DEST info section exists.
     */
    private function ensureInfoSectionIdV3($accessToken, array $source, ?array $enriched, array &$relationMaps)
    {
        $fromStoreId = $relationMaps['_fromStoreId'] ?? null;
        $toStoreId   = $relationMaps['_toStoreId'] ?? null;

        $srcId        = $source['id'] ?? null;
        $srcUnique    = $source['uniqueName'] ?? ($enriched['uniqueName'] ?? null);
        $srcUniqueKey = $srcUnique ? mb_strtolower(trim($srcUnique)) : null;

        // 1) existing migration by id
        if ($srcId && !empty($relationMaps['infoSectionIdMap'][$srcId])) {
            return $relationMaps['infoSectionIdMap'][$srcId];
        }

        // 2) match by uniqueName in DEST
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

        // 3) create if we have enough info
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

    private function importSingleProduct($catalogVersion, $accessToken, $product, $fromStoreId, $toStoreId, $userId, $relationMaps, &$collectionSlugMap)
    {
        $migrationKey = [
            'user_id'           => $userId,
            'from_store_id'     => $fromStoreId,
            'source_product_id' => $product['id'] ?? null,
        ];

        $migrationData = [
            'source_product_sku'     => $product['sku'] ?? null,
            'source_product_name'    => $product['name'] ?? null,
            'status'                 => 'pending',
            'error_message'          => null,
            'destination_product_id' => null,
        ];

        $imported = 0;
        $inventoryUpdated = 0;
        $error = null;
        $createdId = null; // <-- track created product id for return

        try {
            WixHelper::log('Import Products+Inventory', [
                'step' => 'processing',
                'name' => $product['name'] ?? '[No Name]',
                'sku'  => $product['sku']  ?? '[No SKU]'
            ]);

            if ($catalogVersion === 'V3_CATALOG') {
                // pass relationMaps by reference so brand cache/maps update in-place
                [$result, $errMsg] = $this->importProductV3($accessToken, $product, $relationMaps);
                if ($result) {
                    $imported++;
                    $inventoryUpdated++;
                    $createdId = $result['id'] ?? null; // <-- capture id
                    $migrationData['status']                 = 'success';
                    $migrationData['destination_product_id'] = $createdId;
                } else {
                    $migrationData['status']        = 'failed';
                    $migrationData['error_message'] = $errMsg;
                    $error = $errMsg;
                }
            } elseif ($catalogVersion === 'V1_CATALOG') {
                [$result, $inventory, $errMsg] = $this->importProductV1($accessToken, $product, $fromStoreId, $toStoreId, $collectionSlugMap);
                if ($result) {
                    $imported++;
                    $inventoryUpdated += $inventory;
                    $createdId = $result; // V1 returns the new product id directly
                    $migrationData['status']                 = 'success';
                    $migrationData['destination_product_id'] = $createdId;
                } else {
                    $migrationData['status']        = 'failed';
                    $migrationData['error_message'] = $errMsg;
                    $error = $errMsg;
                }
            } else {
                $migrationData['status']        = 'failed';
                $migrationData['error_message'] = "Unknown or unsupported catalog version: $catalogVersion";
                $error = $migrationData['error_message'];
            }
        } catch (\Throwable $e) {
            $migrationData['status']        = 'failed';
            $migrationData['error_message'] = $e->getMessage();
            $error = "Exception for product {$product['name']}: " . $e->getMessage();
        }

        WixProductMigration::updateOrCreate($migrationKey, $migrationData);

        // Return now includes the created id so migrator() can persist it
        return [
            [
                'imported'               => $imported,
                'inventoryUpdated'       => $inventoryUpdated,
                'error'                  => $error,
                'destination_product_id' => $createdId,   // <-- added
                'product_id'             => $createdId,   // <-- alias for existing code
            ],
            $error
        ];
    }
    
    // ====== Product Import functions ======
    private function sanitizeProductName(?string $raw): string {
        $name = trim((string) $raw);
        $name = preg_replace('/\p{C}+/u', '', strip_tags($name));
        if ($name === '') {
            $name = 'Unnamed Product';
        }
        if (mb_strlen($name) > 80) {
            $name = mb_substr($name, 0, 80);
        }
        return $name;
    }

    /**
     * Create a V3 product (with inventory) and attach categories.
     * - Sanitizes incoming slug and ensures uniqueness to avoid "invalid slug" and "duplicate slug" errors.
     * - Enforces unique variant SKUs to avoid DUPLICATE_SKU_ERROR.
     * - Uses retry wrapper to handle 429/5xx rate limits when creating product and attaching categories.
     */
    private function importProductV3($accessToken, $product, array &$relationMaps)
    {
        // ----- Brand & Ribbon -----
        $brandPayload  = null;
        $ribbonPayload = null;

        if (array_key_exists('brand', $product) && $product['brand'] !== null && $product['brand'] !== '') {
            if (is_string($product['brand'])) {
                $brandPayload = ['name' => trim($product['brand'])];
            } elseif (is_array($product['brand'])) {
                $brandPayload = $product['brand'];
                if (empty($brandPayload['name']) && !empty($product['brand_export']['name'])) {
                    $brandPayload['name'] = $product['brand_export']['name'];
                }
            }
        }

        if (array_key_exists('ribbon', $product) && $product['ribbon'] !== null && $product['ribbon'] !== '') {
            if (is_string($product['ribbon'])) {
                $ribbonPayload = ['name' => trim($product['ribbon'])];
            } elseif (is_array($product['ribbon'])) {
                $ribbonPayload = $product['ribbon'];
                if (empty($ribbonPayload['name']) && !empty($product['ribbon_export']['name'])) {
                    $ribbonPayload['name'] = $product['ribbon_export']['name'];
                }
            }
        }

        $destBrandId  = $brandPayload  ? $this->ensureBrandIdV3($accessToken, $brandPayload,  $relationMaps) : null;
        $destRibbonId = $ribbonPayload ? $this->ensureRibbonIdV3($accessToken, $ribbonPayload, $relationMaps) : null;

        // ----- Base product -----
        $productType = strtoupper($product['productType'] ?? 'PHYSICAL');
        $validTypes  = ['PHYSICAL','DIGITAL','UNSPECIFIED_PRODUCT_TYPE'];
        if (!in_array($productType, $validTypes, true)) $productType = 'PHYSICAL';

        $productName   = $this->sanitizeProductName($product['name'] ?? '');
        $requestedSlug = $this->slugifyProduct($productName, $product['slug'] ?? null); // sanitize incoming slug

        // Media (prefer URLs)
        $mediaBlock = [];
        if (!empty($product['media'])) {
            $sanitized = $this->sanitizeMediaForV3($product['media']);
            if ($sanitized) $mediaBlock = $sanitized;
        }

        // ----- Info Sections (ensure/create & attach by id) -----
        $workInfoSections       = $product['infoSections'] ?? [];
        $workInfoSectionsExport = $product['infoSections_export'] ?? [];
        if (empty($workInfoSections) && !empty($product['additionalInfoSections'])) {
            $workInfoSections = [];
            $workInfoSectionsExport = [];
            $i = 0;
            foreach ((array)$product['additionalInfoSections'] as $ais) {
                $title  = trim((string)($ais['title'] ?? 'Info Section'));
                $unique = $this->slugifyUnique($title ?: ('info-section-'.(++$i)));
                $workInfoSections[] = ['uniqueName' => $unique, 'title' => $title];
                $workInfoSectionsExport[] = [
                    'uniqueName'       => $unique,
                    'title'            => $title,
                    'plainDescription' => $ais['description'] ?? $ais['descriptionHtml'] ?? ($ais['descriptionPlainText'] ?? '')
                ];
            }
        }

        $infoSectionRefs = [];
        if ($workInfoSections) {
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
                if ($destId) $infoSectionRefs[] = ['id' => $destId];
            }
        }

        // ===== OPTIONS & MODIFIERS =====
        $options   = [];
        $modifiers = [];
        $optionsByName = [];

        $addOrMergeOption = function(array $opt) use (&$options, &$optionsByName) {
            if (empty($opt['name'])) return;
            unset($opt['id']);
            if (!empty($opt['choicesSettings']['choices'])) {
                foreach ($opt['choicesSettings']['choices'] as &$ch) {
                    unset($ch['id'], $ch['choiceId']);
                }
            }
            $key = mb_strtolower($opt['name']);
            if (!isset($optionsByName[$key])) {
                $optionsByName[$key] = count($options);
                $options[] = $opt;
                return;
            }
            $idx = $optionsByName[$key];
            $existing = &$options[$idx];

            if (empty($existing['optionRenderType']) && !empty($opt['optionRenderType'])) {
                $existing['optionRenderType'] = $opt['optionRenderType'];
            }

            if (empty($existing['choicesSettings']['choices'])) {
                $existing['choicesSettings']['choices'] = [];
            }
            $seen = [];
            foreach ($existing['choicesSettings']['choices'] as $c) {
                $nm = mb_strtolower($c['name'] ?? '');
                if ($nm !== '') $seen[$nm] = true;
            }
            if (!empty($opt['choicesSettings']['choices'])) {
                foreach ($opt['choicesSettings']['choices'] as $c) {
                    $nm = mb_strtolower($c['name'] ?? '');
                    if ($nm !== '' && !isset($seen[$nm])) {
                        $existing['choicesSettings']['choices'][] = $c;
                    }
                }
            }
        };

        if (!empty($product['productOptions']) && is_array($product['productOptions'])) {
            foreach ($product['productOptions'] as $opt) {
                $optName = trim((string)($opt['name'] ?? ''));
                if ($optName === '') continue;

                $renderType = (strtolower($opt['optionType'] ?? '') === 'color')
                    ? 'SWATCH_CHOICES' : 'TEXT_CHOICES';

                $seen = [];
                $choices = [];
                foreach ((array)($opt['choices'] ?? []) as $c) {
                    $label = $c['description'] ?? $c['value'] ?? null;
                    if ($label === null || $label === '') continue;
                    $k = mb_strtolower(trim((string)$label));
                    if (isset($seen[$k])) continue;
                    $seen[$k] = true;

                    $choiceOut = [
                        'name'       => (string)$label,
                        'choiceType' => ($renderType === 'SWATCH_CHOICES') ? 'ONE_COLOR' : 'CHOICE_TEXT',
                    ];
                    if ($renderType === 'SWATCH_CHOICES' && !empty($c['value']) && is_string($c['value'])) {
                        $choiceOut['colorCode'] = $c['value'];
                    }
                    if (!empty($c['media']) && is_array($c['media'])) {
                        $linked = $this->extractV3MediaItems($c['media']);
                        if ($linked) $choiceOut['linkedMedia'] = $linked;
                    }
                    if (array_key_exists('inStock', $c)) $choiceOut['inStock'] = (bool)$c['inStock'];
                    if (array_key_exists('visible', $c)) $choiceOut['visible'] = (bool)$c['visible'];

                    $choices[] = $choiceOut;
                }

                if ($choices) {
                    $addOrMergeOption([
                        'name'             => $optName,
                        'optionRenderType' => $renderType,
                        'choicesSettings'  => ['choices' => $choices],
                    ]);
                }
            }
        }

        // customizations → options / modifiers
        $exportById = [];
        foreach (($product['customizations_export'] ?? []) as $cx) {
            if (!empty($cx['id'])) $exportById[$cx['id']] = $cx;
        }
        if (!empty($product['customizations']) && is_array($product['customizations'])) {
            foreach ($product['customizations'] as $cust) {
                $src  = is_array($cust) ? $cust : ['id' => $cust];
                $full = $src;
                if (!empty($src['id']) && isset($exportById[$src['id']])) {
                    $full = $exportById[$src['id']] + $src;
                }

                $ctype = $full['customizationType'] ?? $full['type'] ?? null;
                if ($ctype === 'PRODUCT_OPTION') {
                    $mapped = $this->mapCustomizationToProductOption($full);
                    if ($mapped) $addOrMergeOption($mapped);
                } elseif ($ctype === 'MODIFIER') {
                    $mapped = $this->mapCustomizationToProductModifier($full);
                    if ($mapped) {
                        if (is_array($src) && array_key_exists('mandatory', $src)) {
                            $mapped['mandatory'] = (bool)$src['mandatory'];
                        }
                        unset($mapped['id']);
                        $modifiers[] = $mapped;
                    }
                }
            }
        }

        // customTextFields -> FREE_TEXT modifiers
        if (!empty($product['customTextFields']) && is_array($product['customTextFields'])) {
            foreach ($product['customTextFields'] as $ctf) {
                $title = $ctf['title'] ?? 'Custom Text';
                $modifiers[] = [
                    'name'               => $title,
                    'modifierRenderType' => 'FREE_TEXT',
                    'mandatory'          => (bool)($ctf['mandatory'] ?? $ctf['required'] ?? false),
                    'freeTextSettings'   => [
                        'title'            => $title,
                        'minCharCount'     => 0,
                        'maxCharCount'     => (int)($ctf['maxLength'] ?? 500),
                        'defaultAddedPrice'=> '0',
                    ],
                ];
            }
        }

        // ===== VARIANTS =====
        $optionRenderTypeByName = [];
        foreach ($options as $o) {
            if (!empty($o['name'])) {
                $optionRenderTypeByName[$o['name']] = $o['optionRenderType'] ?? 'TEXT_CHOICES';
            }
        }

        $optionsOrder = array_map(fn($o) => $o['name'], $options ?: []);
        $firstChoiceFor = [];
        foreach ($options as $o) {
            $firstChoiceFor[$o['name']] = $o['choicesSettings']['choices'][0]['name'] ?? null;
        }

        $variants = [];
        $variantSource = !empty($product['variants_full']) ? $product['variants_full'] : ($product['variants'] ?? []);
        if (!empty($variantSource)) {
            foreach ($variantSource as $v) {
                $flat = isset($v['variant']) && is_array($v['variant']) ? $v['variant'] : $v;

                // Collect pairs as names
                $pairs = [];
                $choicesRaw = $v['choices'] ?? $flat['choices'] ?? [];
                if ($choicesRaw && !array_is_list($choicesRaw)) {
                    foreach ($choicesRaw as $oName => $val) {
                        if ($val === null || $val === '') continue;
                        $pairs[] = ['optionName' => (string)$oName, 'choiceName' => (string)$val];
                    }
                } elseif (is_array($choicesRaw)) {
                    foreach ($choicesRaw as $c) {
                        $oName = $c['option'] ?? $c['name'] ?? $c['key'] ?? null;
                        $val   = $c['choice'] ?? $c['value'] ?? $c['description'] ?? null;
                        if ($oName && $val !== null && $val !== '') {
                            $pairs[] = ['optionName' => $oName, 'choiceName' => $val];
                        }
                    }
                }

                // Fill missing options with first choice
                if (!empty($optionsOrder)) {
                    $have = [];
                    foreach ($pairs as $p) { $have[mb_strtolower($p['optionName'])] = true; }
                    foreach ($optionsOrder as $oName) {
                        if (!isset($have[mb_strtolower($oName)]) && !empty($firstChoiceFor[$oName])) {
                            $pairs[] = ['optionName' => $oName, 'choiceName' => $firstChoiceFor[$oName]];
                        }
                    }
                }

                // Prices & Inventory
                $actual  = $flat['priceData']['discountedPrice'] ?? $flat['price'] ?? 0;
                $compare = $flat['priceData']['price']            ?? null;

                $quantity = $flat['stock']['quantity']
                    ?? ($v['stock']['quantity'] ?? ($product['stock']['quantity'] ?? null));
                $inStock  = $flat['stock']['inStock']
                    ?? ($v['stock']['inStock'] ?? ($product['stock']['inStock'] ?? true));

                $inventoryItem = [];
                if ($quantity !== null && $quantity !== '') {
                    $inventoryItem['quantity'] = (int)$quantity;
                } else {
                    $inventoryItem['inStock'] = (bool)$inStock;
                }

                $oneVariant = [
                    'sku'           => (is_string($flat['sku'] ?? null) && trim($flat['sku']) !== '') ? $flat['sku'] : ('SKU-' . uniqid()),
                    'visible'       => (bool)($flat['visible'] ?? true),
                    'price'         => ['actualPrice' => ['amount' => (string)$actual]],
                    'inventoryItem' => $inventoryItem,
                    'choices'       => [],
                ];
                if ($compare !== null && $compare !== '') {
                    $oneVariant['price']['compareAtPrice'] = ['amount' => (string)$compare];
                }
                if (array_key_exists('weight', $flat) && $flat['weight'] !== null) {
                    $oneVariant['physicalProperties'] = ['weight' => $flat['weight']];
                }

                foreach ($pairs as $p) {
                    $renderType = $optionRenderTypeByName[$p['optionName']] ?? 'TEXT_CHOICES';
                    $oneVariant['choices'][] = [
                        'optionChoiceNames' => [
                            'optionName' => $p['optionName'],
                            'choiceName' => $p['choiceName'],
                            'renderType' => $renderType,
                        ],
                    ];
                }

                $variants[] = $oneVariant;
            }
        } else {
            // Single-variant fallback
            $priceVal = $product['price']['discountedPrice']
                ?? ($product['priceData']['discountedPrice'] ?? ($product['price']['price'] ?? 0));
            $compare  = $product['price']['price'] ?? ($product['priceData']['price'] ?? null);

            $quantity = $product['stock']['quantity'] ?? null;
            $inStock  = $product['stock']['inStock']  ?? true;

            $inv = [];
            if ($quantity !== null && $quantity !== '') $inv['quantity'] = (int)$quantity;
            else $inv['inStock'] = (bool)$inStock;

            $variants[] = [
                'sku'           => (is_string($product['sku'] ?? null) && trim($product['sku']) !== '') ? $product['sku'] : ('SKU-' . uniqid()),
                'choices'       => [],
                'visible'       => (bool)($product['visible'] ?? true),
                'price'         => array_filter([
                    'actualPrice'    => ['amount' => (string)$priceVal],
                    'compareAtPrice' => $compare !== null ? ['amount' => (string)$compare] : null,
                ]),
                'inventoryItem' => $inv,
            ];
        }

        // ===== Enforce unique variant SKUs to avoid DUPLICATE_SKU_ERROR =====
        $seen = [];
        foreach ($variants as &$v) {
            $raw = trim((string)($v['sku'] ?? ''));
            if ($raw === '') $raw = 'SKU-' . uniqid();

            $key = mb_strtolower($raw);
            if (!isset($seen[$key])) {
                $seen[$key] = 1;
                $v['sku'] = $raw;
                continue;
            }

            // Collision: append -{n}
            $base = $raw;
            $n = $seen[$key];
            do {
                $candidate = $base . '-' . $n;
                $ck = mb_strtolower($candidate);
                $n++;
            } while (isset($seen[$ck]));
            $v['sku'] = $candidate;
            $seen[$ck] = 1;
            $seen[$key]++;
        }
        unset($v);

        // ===== Map legacy collections → destination category IDs BEFORE create/patch =====
        $legacySourceIds   = [];
        $legacySourceSlugs = [];

        if (!empty($product['collectionIds']) && is_array($product['collectionIds'])) {
            $legacySourceIds = array_merge($legacySourceIds, $product['collectionIds']);
        }
        if (!empty($product['directCategories']) && is_array($product['directCategories'])) {
            foreach ($product['directCategories'] as $dc) {
                if (is_array($dc) && !empty($dc['id'])) $legacySourceIds[] = $dc['id'];
                if (is_array($dc) && !empty($dc['slug'])) $legacySourceSlugs[] = mb_strtolower($dc['slug']);
            }
        }
        if (!empty($product['collectionSlugs']) && is_array($product['collectionSlugs'])) {
            foreach ($product['collectionSlugs'] as $s) {
                if (is_string($s) && $s !== '') $legacySourceSlugs[] = mb_strtolower($s);
            }
        }

        $legacySourceIds   = array_values(array_unique(array_filter($legacySourceIds, fn($v) => is_string($v) && $v !== '')));
        $legacySourceSlugs = array_values(array_unique(array_filter($legacySourceSlugs, fn($v) => is_string($v) && $v !== '')));

        $fromStoreId = $relationMaps['_fromStoreId'] ?? ($product['from_store_id'] ?? null);
        $toStoreId   = $relationMaps['_toStoreId']   ?? ($product['to_store_id']   ?? null);
        $userId      = $relationMaps['user_id']      ?? (Auth::id() ?: 1);

        $destCategoryIds = [];

        if ($legacySourceIds) {
            $q = WixCollectionMigration::query()
                ->whereIn('source_collection_id', $legacySourceIds)
                ->where('user_id', $userId)
                ->whereNotNull('destination_collection_id')
                ->where('status', 'success');

            if ($fromStoreId) $q->where('from_store_id', $fromStoreId);
            if ($toStoreId)   $q->where('to_store_id',   $toStoreId);

            $destCategoryIds = $q->pluck('destination_collection_id')->unique()->values()->all();
        }

        if (empty($destCategoryIds) && $legacySourceSlugs) {
            $q2 = WixCollectionMigration::query()
                ->whereIn(DB::raw('LOWER(source_collection_slug)'), $legacySourceSlugs)
                ->where('user_id', $userId)
                ->whereNotNull('destination_collection_id')
                ->where('status', 'success');

            if ($fromStoreId) $q2->where('from_store_id', $fromStoreId);
            if ($toStoreId)   $q2->where('to_store_id',   $toStoreId);

            $destCategoryIds = $q2->pluck('destination_collection_id')->unique()->values()->all();
        }

        if (empty($destCategoryIds) && $legacySourceSlugs) {
            $appNamespace = $relationMaps['appNamespace'] ?? '@wix/stores';
            $treeKey      = $relationMaps['treeKey']      ?? null;

            $findV3CategoryIdBySlug = function(string $slug) use ($accessToken, $appNamespace, $treeKey) {
                $body = [
                    'search' => [
                        'paging' => ['limit' => 50],
                        'search' => ['expression' => $slug]
                    ],
                    'treeReference' => ['appNamespace' => $appNamespace]
                ];
                if ($treeKey !== null && trim((string)$treeKey) !== '') {
                    $body['treeReference']['treeKey'] = $treeKey;
                }
                $resp = Http::withHeaders([
                    'Authorization' => "Bearer {$accessToken}",
                    'Content-Type'  => 'application/json'
                ])->post('https://www.wixapis.com/categories/v1/categories/search', $body);

                if (!$resp->ok()) return null;
                $cats = $resp->json('categories') ?? [];
                foreach ($cats as $c) {
                    if (isset($c['slug']) && mb_strtolower($c['slug']) === mb_strtolower($slug)) {
                        return $c['id'] ?? null;
                    }
                }
                return null;
            };

            $looked = [];
            foreach ($legacySourceSlugs as $s) {
                $cid = $findV3CategoryIdBySlug($s);
                if ($cid) $looked[] = $cid;
            }
            if ($looked) {
                $destCategoryIds = array_values(array_unique($looked));
            }
        }

        // ===== Compose product payload (include directCategories if available) =====
        // Ensure UNIQUE slug before create to avoid duplicate-slug errors
        $uniqueSlug = $this->ensureUniqueSlugV3($accessToken, $requestedSlug);

        $productBody = [
            'name'               => $productName,
            'slug'               => $uniqueSlug,
            'plainDescription'   => is_string($product['description'] ?? null) ? $product['description'] : '',
            'visible'            => (bool)($product['visible'] ?? true),
            'productType'        => $productType,
            'variantsInfo'       => ['variants' => $variants],
            'physicalProperties' => (object)[],
        ];
        if ($mediaBlock)               { $productBody['media']        = $mediaBlock; }
        if (!empty($infoSectionRefs))  { $productBody['infoSections'] = $infoSectionRefs; }
        if (!empty($options))          { $productBody['options']      = $options; }
        if (!empty($modifiers))        { $productBody['modifiers']    = $modifiers; }
        if ($destBrandId)              { $productBody['brand']        = ['id' => $destBrandId]; }
        elseif (!empty($brandPayload['name']))  { $productBody['brand']  = ['name' => $brandPayload['name']]; }
        if ($destRibbonId)             { $productBody['ribbon']       = ['id' => $destRibbonId]; }
        elseif (!empty($ribbonPayload['name'])) { $productBody['ribbon'] = ['name' => $ribbonPayload['name']]; }

        if (!empty($destCategoryIds)) {
            $productBody['directCategories'] = array_map(fn($id) => ['id' => $id], array_slice($destCategoryIds, 0, 100));
        }

        // ===== Always CREATE (avoid accidental overwrite by slug lookups) =====
        $createBody = [
            'product'      => $productBody,
            'returnEntity' => true
        ];

        WixHelper::log('Import Products+Inventory', ['step' => 'creating V3', 'payload' => $createBody]);

        // Use robust retry to handle rate limits (429) and transient 5xx
        $response = $this->httpWithRetry(
            'POST',
            'https://www.wixapis.com/stores/v3/products-with-inventory',
            ['Authorization' => "Bearer {$accessToken}", 'Content-Type' => 'application/json'],
            $createBody,
            5
        );

        WixHelper::log(
            'Import Products+Inventory',
            ['step' => 'V3 response', 'ok' => $response->ok(), 'status' => $response->status(), 'response' => $response->body()],
            $response->ok() ? 'success' : 'error'
        );

        if (!($response->ok() && isset($response->json()['product']['id']))) {
            $firstVariant = $variants[0] ?? [];
            $snippet = json_encode($firstVariant, JSON_UNESCAPED_UNICODE);
            return [null, 'V3 product-with-inventory failed: '.$response->body().' | firstVariant='.$snippet];
        }

        // ===== Attach categories via Categories API (also with retry) =====
        $newProduct = $response->json()['product'];
        $productId  = $newProduct['id'];

        if (!empty($destCategoryIds)) {
            $storesAppId  = env('WIX_STORES_APP_ID', '215238eb-22a5-4c36-9e7b-e7c08025e04e');
            $appNamespace = $relationMaps['appNamespace'] ?? '@wix/stores';
            $treeKey      = $relationMaps['treeKey']      ?? null;

            $catPayload = [
                'item' => [
                    'catalogItemId' => $productId,
                    'appId'         => $storesAppId,
                ],
                'categoryIds'   => array_slice(array_values(array_unique($destCategoryIds)), 0, 100),
                'treeReference' => [
                    'appNamespace' => $appNamespace,
                ],
            ];
            if ($treeKey !== null && trim((string)$treeKey) !== '') {
                $catPayload['treeReference']['treeKey'] = $treeKey;
            }

            WixHelper::log('Import Products+Inventory', [
                'step'    => 'categories.add-item',
                'payload' => $catPayload
            ], 'info');

            $catResp = $this->httpWithRetry(
                'POST',
                'https://www.wixapis.com/categories/v1/bulk/categories/add-item',
                ['Authorization' => "Bearer {$accessToken}", 'Content-Type' => 'application/json'],
                $catPayload,
                4
            );

            WixHelper::log('Import Products+Inventory', [
                'step'     => 'categories.add-item response',
                'ok'       => $catResp->ok(),
                'status'   => $catResp->status(),
                'response' => $catResp->body(),
            ], $catResp->ok() ? 'success' : 'warn');
        } else {
            WixHelper::log('Import Products+Inventory', [
                'step'  => 'categories',
                'note'  => 'No destination categories mapped; skipping',
                'from'  => $fromStoreId,
                'to'    => $toStoreId,
                'user'  => $userId,
                'srcIds'=> $legacySourceIds,
                'slugs' => $legacySourceSlugs,
            ], 'warn');
        }

        return [$newProduct, null];
    }

    /**
     * Map a Customization (PRODUCT_OPTION) to inline Product Option.
     * Accepts shapes from customizations_export (V3).
     */
    private function mapCustomizationToProductOption(array $c): ?array
    {
        $name = trim((string)($c['name'] ?? ''));
        if ($name === '') return null;

        $renderType = $c['customizationRenderType'] ?? $c['renderType'] ?? 'TEXT_CHOICES';

        $opt = [
            'name' => $name,
            'optionRenderType' => $renderType,
        ];

        if (!empty($c['choicesSettings'])) {
            // Pass through choicesSettings as-is (structure matches product.options)
            $opt['choicesSettings'] = $c['choicesSettings'];
        }
        return $opt;
    }

    /**
     * Map a Customization (MODIFIER) to inline Product Modifier.
     */
    private function mapCustomizationToProductModifier(array $c): ?array
    {
        $name = trim((string)($c['name'] ?? ''));
        if ($name === '') return null;

        $renderType = $c['customizationRenderType'] ?? $c['renderType'] ?? 'TEXT_CHOICES';

        $mod = [
            'name' => $name,
            'modifierRenderType' => $renderType,
        ];

        if (!empty($c['freeTextInput'])) {
            $fti = $c['freeTextInput'];
            $mod['freeTextSettings'] = [
                'title'            => $fti['title']            ?? $name,
                'minCharCount'     => (int)($fti['minCharCount']     ?? 0),
                'maxCharCount'     => (int)($fti['maxCharCount']     ?? 500),
                'defaultAddedPrice'=> (string)($fti['defaultAddedPrice'] ?? '0'),
            ];
        }
        if (!empty($c['choicesSettings'])) {
            $mod['choicesSettings'] = $c['choicesSettings'];
        }
        return $mod;
    }

    private function importProductV1($accessToken, $product, $fromStoreId, $toStoreId, &$collectionSlugMap)
    {
        $inventoryUpdated = 0;
        $productId = null;

        $filteredProduct = $this->filterWixProductForImport($product);

        if (empty($filteredProduct['sku'])) {
            $filteredProduct['sku'] = 'SKU-' . uniqid();
        }

        // Add customTextFields if present
        if (!empty($product['customTextFields'])) {
            $filteredProduct['customTextFields'] = $product['customTextFields'];
        }

        WixHelper::log('Import Products+Inventory', [
            'step' => 'posting V1',
            'payload' => $filteredProduct
        ]);
        $response = Http::withHeaders([
            'Authorization' => $accessToken,
            'Content-Type'  => 'application/json'
        ])->post('https://www.wixapis.com/stores/v1/products', ["product" => $filteredProduct]);

        WixHelper::log('Import Products+Inventory', [
            'step' => 'V1 response',
            'response' => $response->body()
        ], $response->ok() ? 'success' : 'error');

        $result = $response->json();
        if ($response->status() === 200 && isset($result['product']['id'])) {
            $productId = $result['product']['id'];
            $createdProduct = $result['product'];
            $hasVariants = !empty($createdProduct['variants']);

            // ---- Inventory PATCH unchanged ----
            $inventoryBody = [
                'inventoryItem' => [
                    'trackQuantity' => true,
                    'variants' => [],
                ]
            ];

            if ($hasVariants) {
                foreach ($createdProduct['variants'] as $i => $variant) {
                    $origVariant = ($product['variants_full'][$i] ?? null) ?: ($product['variants'][$i] ?? []);
                    $flat = isset($origVariant['variant']) ? $origVariant['variant'] : $origVariant;
                    $quantity = $flat['stock']['quantity'] ?? $origVariant['stock']['quantity'] ?? $product['stock']['quantity'] ?? null;
                    $inventoryBody['inventoryItem']['variants'][] = [
                        'variantId' => $variant['id'],
                        'quantity' => $quantity,
                    ];
                }
            } else {
                $quantity = $product['stock']['quantity'] ?? null;
                $inventoryBody['inventoryItem']['variants'][] = [
                    'variantId' => '00000000-0000-0000-0000-000000000000',
                    'quantity' => $quantity,
                ];
            }

            WixHelper::log('Import Products+Inventory', [
                'step' => 'patching inventory V1',
                'product_id' => $productId,
                'payload' => $inventoryBody
            ]);

            $invRes = Http::withHeaders([
                'Authorization' => $accessToken,
                'Content-Type'  => 'application/json'
            ])->patch("https://www.wixapis.com/stores/v2/inventoryItems/product/{$productId}", $inventoryBody);

            WixHelper::log('Import Products+Inventory', [
                'step' => 'V1 Inventory PATCH response',
                'response' => $invRes->body()
            ], $invRes->ok() ? 'success' : 'error');

            if ($invRes->ok()) {
                $inventoryUpdated++;
            }

            // ---------- FULL VARIANT DATA ----------
            $variantSource = !empty($product['variants_full']) ? $product['variants_full'] : ($product['variants'] ?? []);
            if ($hasVariants && !empty($variantSource)) {
                $variantsPayload = [];
                foreach ($product['variants_full'] as $variantData) {
                    $variant = $variantData['variant'] ?? [];
                    $variantUpdate = [
                        'choices' => $variantData['choices'] ?? [],
                    ];
                    // These fields are all optional in PATCH, but set if present:
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

                // Then PATCH to update all variant fields for the product
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

            // ---------- Media ----------
            if (!empty($product['media']['items'])) {
                $mediaItems = [];
                foreach ($product['media']['items'] as $media) {
                    if (!empty($media['id'])) {
                        $mediaItems[] = ['mediaId' => $media['id']];
                    } elseif (!empty($media['image']['url'])) {
                        $mediaItem = ['url' => $media['image']['url']];
                        if (!empty($media['choice'])) {
                            $mediaItem['choice'] = $media['choice'];
                        }
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

            // ---------- Add Media to Choices from productOptions ----------
            if (!empty($product['productOptions'])) {
                foreach ($product['productOptions'] as $option) {
                    if (empty($option['choices']) || empty($option['name'])) continue;

                    foreach ($option['choices'] as $choice) {
                        $mediaIds = [];

                        // Add mainMedia id if present
                        if (!empty($choice['media']['mainMedia']['id'])) {
                            $mediaIds[] = $choice['media']['mainMedia']['id'];
                        }
                        // Add each item id if present
                        if (!empty($choice['media']['items']) && is_array($choice['media']['items'])) {
                            foreach ($choice['media']['items'] as $mediaItem) {
                                if (!empty($mediaItem['id'])) {
                                    $mediaIds[] = $mediaItem['id'];
                                }
                            }
                        }

                        if (count($mediaIds)) {
                            $optionName = $option['name'];
                            $choiceName = $choice['description'] ?? $choice['value'] ?? null;
                            if (!$choiceName) continue;

                            $patchBody = [
                                'media' => [[
                                    'option' => $optionName,
                                    'choice' => $choiceName,
                                    'mediaIds' => array_unique($mediaIds),
                                ]]
                            ];

                            $mediaToChoicesRes = Http::withHeaders([
                                'Authorization' => $accessToken,
                                'Content-Type'  => 'application/json'
                            ])->patch("https://www.wixapis.com/stores/v1/products/{$productId}/choices/media", $patchBody);

                            WixHelper::log('Import Products+Inventory', [
                                'step' => 'PATCH media to choices (productOptions)',
                                'product_id' => $productId,
                                'patchBody' => $patchBody,
                                'response' => $mediaToChoicesRes->body()
                            ], $mediaToChoicesRes->ok() ? 'success' : 'error');
                        }
                    }
                }
            }

            // --- CONNECT PRODUCT TO COLLECTIONS ---
            if (!empty($product['collectionSlugs']) && is_array($product['collectionSlugs'])) {
                foreach ($product['collectionSlugs'] as $slug) {
                    if (!is_string($slug) || trim($slug) === '') continue;
                    // Check map/cache
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
                            // Can't add, continue
                            continue;
                        }
                    }
                    // Skip All Products collection
                    if ($collectionId === '00000000-0000-0000-0000-000000000001') continue;

                    // Add product to collection
                    $addResp = Http::withHeaders([
                        'Authorization' => $accessToken,
                        'Content-Type'  => 'application/json'
                    ])->post("https://www.wixapis.com/stores/v1/collections/{$collectionId}/productIds", [
                        'productIds' => [$productId]
                    ]);
                    if (!$addResp->ok()) {
                        $status = $addResp->status();
                        $body   = $addResp->body();

                        // 409 = already in collection -> treat as success/skip
                        if ($status === 409) {
                            WixHelper::log('Import Products+Inventory', [
                                'step'          => 'Add to collection skipped (already present)',
                                'product_id'    => $productId,
                                'collection_id' => $collectionId,
                                'slug'          => $slug,
                                'response'      => $body,
                            ], 'info');
                        }
                        // 429 = rate limited -> retry with exponential backoff (max 3 tries)
                        elseif ($status === 429) {
                            $maxRetries = 3;
                            $retry = 0;
                            $success = false;

                            while ($retry < $maxRetries && !$success) {
                                $delayMs = (int) pow(2, $retry) * 250; // 250ms, 500ms, 1000ms
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
                        }
                        // 404 = collection not found (or other errors) -> log error
                        else {
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


    private function normalizeV3Variant(array $variant, array $product): array
    {
        $flat = isset($variant['variant']) && is_array($variant['variant']) ? $variant['variant'] : $variant;

        // ---- Collect pairs as scalar tuples
        $choicesRaw = $variant['choices'] ?? $flat['choices'] ?? [];
        $pairs = [];

        if (is_array($choicesRaw)) {
            // [{option, choice}]
            if (isset($choicesRaw[0]) && is_array($choicesRaw[0]) && array_key_exists('option', $choicesRaw[0])) {
                foreach ($choicesRaw as $c) {
                    $opt = isset($c['option']) ? (string)$c['option'] : null;
                    $val = isset($c['choice']) ? (string)$c['choice'] : null;
                    if ($opt !== null && $val !== null && $val !== '') {
                        $pairs[] = [$opt, $val];
                    }
                }
            }
            // {"Color":"Red", "Size":"M"}
            elseif (!array_is_list($choicesRaw)) {
                foreach ($choicesRaw as $optName => $val) {
                    if ($val === null) continue;
                    $val = (string)$val;
                    if ($val === '') continue;
                    $pairs[] = [(string)$optName, $val];
                }
            }
            // Odd shapes
            elseif (isset($choicesRaw[0]) && is_array($choicesRaw[0])) {
                foreach ($choicesRaw as $c) {
                    $opt = $c['option'] ?? $c['name'] ?? $c['key'] ?? null;
                    $val = $c['choice'] ?? $c['value'] ?? $c['description'] ?? null;
                    if ($opt !== null && $val !== null && $val !== '') {
                        $pairs[] = [(string)$opt, (string)$val];
                    }
                }
            }
        }

        // SKU
        $skuRaw = $flat['sku'] ?? $variant['sku'] ?? null;
        $sku    = (is_string($skuRaw) && trim($skuRaw) !== '') ? $skuRaw : ('SKU-' . uniqid());

        // PRICE (actual + optional compareAt)
        $priceVal     = $flat['priceData']['discountedPrice'] ?? $flat['priceData']['price'] ?? $flat['price'] ?? 0;
        $compareAtVal = $flat['priceData']['price'] ?? null;

        // INVENTORY (one-of)
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

        // Build choices as an array of objects, each with a SINGLE optionChoiceNames OBJECT
        if (!empty($pairs)) {
            $choiceObjs = [];
            foreach ($pairs as [$opt, $val]) {
                $choiceObjs[] = [
                    'optionChoiceNames' => [
                        'optionName' => $opt,
                        'choiceName' => $val,
                    ],
                ];
            }
            $out['choices'] = $choiceObjs;
        }

        if ($compareAtVal !== null && $compareAtVal !== '') {
            $out['price']['compareAtPrice'] = ['amount' => (string)$compareAtVal];
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
            // Prefer URL (works cross-site). Use id only if guaranteed in target Media Manager.
            if (!empty($m['image']['url'])) { $items[] = ['url' => $m['image']['url']]; return; }
            if (!empty($m['video']['resolutions'][0]['url'])) { $items[] = ['url' => $m['video']['resolutions'][0]['url']]; return; }
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
        // transliterate basic accents
        $t = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $t);
        $t = preg_replace('~[^a-z0-9]+~', '-', $t);
        $t = trim($t, '-');
        if ($t === '' || $t === false) {
            $t = 'info-section-'.uniqid();
        }
        return $t;
    }

    // Make a URL-safe slug. If $slug provided but invalid, we normalize it; otherwise derive from $name.
    private function slugifyProduct(string $name, ?string $slug = null): string
    {
        $base = trim((string)($slug ?? $name));

        // Replace separators we commonly see
        $base = preg_replace('/[\/|_]+/u', '-', $base);   // slashes, pipes, underscores -> dash
        $base = preg_replace('/[“”"‘’\']+/u', '', $base); // quotes/apostrophes -> remove
        $base = preg_replace('/[(){}\[\]]+/u', '', $base);// parentheses/brackets -> remove

        // Lowercase, transliterate accents
        $t = mb_strtolower($base);
        $t = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $t);

        // Keep alnum + dash
        $t = preg_replace('~[^a-z0-9\-]+~', '-', $t);

        // Collapse multiple dashes
        $t = preg_replace('/-+/', '-', $t);

        // Trim dashes
        $t = trim($t, '-');

        // Fallback if empty
        if ($t === '' || $t === false) {
            $t = 'product-' . substr(sha1((string) microtime(true)), 0, 8);
        }

        // Wix slugs are typically limited; keep it sane
        if (mb_strlen($t) > 80) {
            $t = mb_substr($t, 0, 80);
            $t = rtrim($t, '-');
        }

        return $t;
    }

    // Check if a slug already exists in DEST V3. Returns product array if found, null if free.
    // NOTE: We ALWAYS try with GET /stores/v3/products/slug/{slug}
    private function findV3ProductBySlug($accessToken, string $slug): ?array
    {
        $resp = Http::withHeaders([
            'Authorization' => "Bearer {$accessToken}",
            'Content-Type'  => 'application/json'
        ])->get('https://www.wixapis.com/stores/v3/products/slug/' . rawurlencode($slug));

        if ($resp->ok() && !empty($resp->json('product.id'))) {
            return $resp->json('product');
        }
        return null;
    }

    // Ensure slug is UNIQUE in DEST V3. If taken, append -2, -3, ...
    private function ensureUniqueSlugV3($accessToken, string $slug): string
    {
        $base = $slug;
        $suffix = 2;

        while (true) {
            $existing = $this->findV3ProductBySlug($accessToken, $slug);
            if (!$existing) return $slug;

            // next candidate
            $candidate = $base . '-' . $suffix;
            // keep length reasonable
            if (mb_strlen($candidate) > 80) {
                $candidate = mb_substr($candidate, 0, 80);
                $candidate = rtrim($candidate, '-');
            }
            $slug = $candidate;
            $suffix++;
            if ($suffix > 9999) { // hard stop
                return $base . '-' . substr(sha1((string) microtime(true)), 0, 4);
            }
        }
    }

    // Generic retry wrapper for POST/PATCH/GET against 429 or transient 5xx
    private function httpWithRetry(string $method, string $url, array $headers, array $body = null, int $maxRetries = 5)
    {
        $attempt = 0;
        $delayMs = 300;

        while (true) {
            $req = Http::withHeaders($headers);
            switch (strtoupper($method)) {
                case 'POST':
                    $resp = $req->post($url, $body ?? []);
                    break;
                case 'PATCH':
                    $resp = $req->patch($url, $body ?? []);
                    break;
                case 'GET':
                    $resp = $req->get($url);
                    break;
                default:
                    $resp = $req->send($method, $url, ['json' => $body]);
                    break;
            }

            // Success or client error (except 429) -> return
            if ($resp->ok() || ($resp->status() >= 400 && $resp->status() < 500 && $resp->status() !== 429)) {
                return $resp;
            }

            // Retry on 429/rate limit or transient 5xx
            if ($resp->status() === 429 || ($resp->status() >= 500 && $resp->status() < 600)) {
                if ($attempt >= $maxRetries) {
                    return $resp;
                }
                // exponential backoff with jitter
                usleep(($delayMs + random_int(0, 200)) * 1000);
                $delayMs = min($delayMs * 2, 5000); // cap at 5s
                $attempt++;
                continue;
            }

            // All other statuses -> return
            return $resp;
        }
    }

    // =========================================================
    // Import Product function for automated migration
    // =========================================================
    public function importProductArray($accessToken, $product, $collectionSlugMap = [])
    {
        $imported = 0;
        $inventoryUpdated = 0;
        $errors = [];
        $newProductId = null;

        // 1. Detect catalog version
        $catalogVersionResp = Http::withHeaders([
            'Authorization' => $accessToken,
            'Content-Type'  => 'application/json'
        ])->get('https://www.wixapis.com/stores/v3/provision/version');
        $catalogVersion = $catalogVersionResp->json('catalogVersion') ?? 'V1_CATALOG';

        try {
            // ================== V3 FLOW ==================
            if ($catalogVersion === 'V3_CATALOG') {
                $body = [
                    'product' => [
                        'name' => $product['name'] ?? 'Unnamed Product',
                        'slug' => $product['slug'] ?? null,
                        'plainDescription' => is_string($product['description'] ?? null) ? $product['description'] : '',
                        'visible' => $product['visible'] ?? true,
                        'media' => $this->sanitizeMediaForV3($product['media'] ?? []),
                        'productType' => strtoupper($product['productType'] ?? 'PHYSICAL'),
                        'variantsInfo' => [
                            'variants' => [],
                        ],
                        'physicalProperties' => [],
                    ]
                ];

                // Variants
                $variantSource = !empty($product['variants_full']) ? $product['variants_full'] : ($product['variants'] ?? []);
                if (!empty($variantSource)) {
                    foreach ($variantSource as $v) {
                        $norm = $this->normalizeV3Variant($v, $product);
                        $body['product']['variantsInfo']['variants'][] = $norm;
                    }
                } else {
                    // Single variant fallback
                    $body['product']['variantsInfo']['variants'][] = [
                        'sku' => $product['sku'] ?? 'SKU-' . uniqid(),
                        'choices' => [],
                        'price' => [
                            'actualPrice' => [
                                'amount' => strval($product['price']['price'] ?? 0),
                            ]
                        ],
                        'inventoryItem' => [
                            'quantity' => $product['stock']['quantity'] ?? null,
                            'inStock' => $product['stock']['inStock'] ?? true,
                        ]
                    ];
                }

                $response = Http::withHeaders([
                    'Authorization' => $accessToken,
                    'Content-Type'  => 'application/json'
                ])->post('https://www.wixapis.com/stores/v3/products-with-inventory', $body);

                if ($response->ok() && isset($response->json()['product']['id'])) {
                    $imported++;
                    $inventoryUpdated++;
                    $newProductId = $response->json()['product']['id'];
                } else {
                    $errors[] = 'V3 product-with-inventory failed: ' . $response->body();
                }
            }
            // ================== V1 FLOW ==================
            elseif ($catalogVersion === 'V1_CATALOG') {
                $filteredProduct = $this->filterWixProductForImport($product);

                if (empty($filteredProduct['sku'])) {
                    $filteredProduct['sku'] = 'SKU-' . uniqid();
                }

                // Add customTextFields if present
                if (!empty($product['customTextFields'])) {
                    $filteredProduct['customTextFields'] = $product['customTextFields'];
                }

                $response = Http::withHeaders([
                    'Authorization' => $accessToken,
                    'Content-Type'  => 'application/json'
                ])->post('https://www.wixapis.com/stores/v1/products', ["product" => $filteredProduct]);

                $result = $response->json();
                if ($response->status() === 200 && isset($result['product']['id'])) {
                    $imported++;
                    $newProductId = $result['product']['id'];
                    $createdProduct = $result['product'];
                    $hasVariants = !empty($createdProduct['variants']);

                    // ---- PATCH Inventory ----
                    $inventoryBody = [
                        'inventoryItem' => [
                            'trackQuantity' => true,
                            'variants' => [],
                        ]
                    ];

                    if ($hasVariants) {
                        foreach ($createdProduct['variants'] as $i => $variant) {
                            $origVariant = ($product['variants_full'][$i] ?? null) ?: ($product['variants'][$i] ?? []);
                            $flat = isset($origVariant['variant']) ? $origVariant['variant'] : $origVariant;
                            $quantity = $flat['stock']['quantity'] ?? $origVariant['stock']['quantity'] ?? $product['stock']['quantity'] ?? null;
                            $inventoryBody['inventoryItem']['variants'][] = [
                                'variantId' => $variant['id'],
                                'quantity' => $quantity,
                            ];
                        }
                    } else {
                        $quantity = $product['stock']['quantity'] ?? null;
                        $inventoryBody['inventoryItem']['variants'][] = [
                            'variantId' => '00000000-0000-0000-0000-000000000000',
                            'quantity' => $quantity,
                        ];
                    }

                    $invRes = Http::withHeaders([
                        'Authorization' => $accessToken,
                        'Content-Type'  => 'application/json'
                    ])->patch("https://www.wixapis.com/stores/v2/inventoryItems/product/{$newProductId}", $inventoryBody);

                    if ($invRes->ok()) {
                        $inventoryUpdated++;
                    } else {
                        $errors[] = "V1 inventory failed for SKU {$filteredProduct['sku']}: " . $invRes->body();
                    }

                    // ---------- PATCH Variants (optional) ----------
                    $variantSource = !empty($product['variants_full']) ? $product['variants_full'] : ($product['variants'] ?? []);
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
                            Http::withHeaders([
                                'Authorization' => $accessToken,
                                'Content-Type'  => 'application/json'
                            ])->patch("https://www.wixapis.com/stores/v1/products/{$newProductId}/variants", [
                                'variants' => $variantsPayload
                            ]);
                        }
                    }

                    // ---------- Media ----------
                    if (!empty($product['media']['items'])) {
                        $mediaItems = [];
                        foreach ($product['media']['items'] as $media) {
                            if (!empty($media['id'])) {
                                $mediaItems[] = ['mediaId' => $media['id']];
                            } elseif (!empty($media['image']['url'])) {
                                $mediaItem = ['url' => $media['image']['url']];
                                if (!empty($media['choice'])) {
                                    $mediaItem['choice'] = $media['choice'];
                                }
                                $mediaItems[] = $mediaItem;
                            }
                        }
                        if (count($mediaItems)) {
                            Http::withHeaders([
                                'Authorization' => $accessToken,
                                'Content-Type'  => 'application/json'
                            ])->post("https://www.wixapis.com/stores/v1/products/{$newProductId}/media", [
                                'media' => $mediaItems
                            ]);
                        }
                    }

                    // ---------- Media to Choices (Options) ----------
                    if (!empty($product['productOptions'])) {
                        foreach ($product['productOptions'] as $option) {
                            if (empty($option['choices']) || empty($option['name'])) continue;
                            foreach ($option['choices'] as $choice) {
                                $mediaIds = [];
                                if (!empty($choice['media']['mainMedia']['id'])) {
                                    $mediaIds[] = $choice['media']['mainMedia']['id'];
                                }
                                if (!empty($choice['media']['items']) && is_array($choice['media']['items'])) {
                                    foreach ($choice['media']['items'] as $mediaItem) {
                                        if (!empty($mediaItem['id'])) {
                                            $mediaIds[] = $mediaItem['id'];
                                        }
                                    }
                                }
                                if (count($mediaIds)) {
                                    $optionName = $option['name'];
                                    $choiceName = $choice['description'] ?? $choice['value'] ?? null;
                                    if (!$choiceName) continue;

                                    $patchBody = [
                                        'media' => [[
                                            'option' => $optionName,
                                            'choice' => $choiceName,
                                            'mediaIds' => array_unique($mediaIds),
                                        ]]]
                                    ;
                                    Http::withHeaders([
                                        'Authorization' => $accessToken,
                                        'Content-Type'  => 'application/json'
                                    ])->patch("https://www.wixapis.com/stores/v1/products/{$newProductId}/choices/media", $patchBody);
                                }
                            }
                        }
                    }

                    // --- CONNECT PRODUCT TO COLLECTIONS ---
                    if (!empty($product['collectionSlugs']) && is_array($product['collectionSlugs'])) {
                        foreach ($product['collectionSlugs'] as $slug) {
                            if (!is_string($slug) || trim($slug) === '') continue;
                            $collectionId = null;
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
                                }
                            }
                            if ($collectionId === '00000000-0000-0000-0000-000000000001' || !$collectionId) continue;

                            Http::withHeaders([
                                'Authorization' => $accessToken,
                                'Content-Type'  => 'application/json'
                            ])->post("https://www.wixapis.com/stores/v1/collections/{$collectionId}/productIds", [
                                'productIds' => [$newProductId]
                            ]);
                        }
                    }
                } else {
                    $errors[] = "V1 product creation failed: " . $response->body();
                }
            }
            // ================== UNKNOWN CATALOG ==================
            else {
                $errors[] = "Unknown or unsupported catalog version: $catalogVersion";
            }

            // Persist destination_product_id when created (mirrors collection controller pattern)
            if ($imported > 0 && $newProductId) {
                \App\Models\WixProductMigration::updateOrCreate(
                    [
                        'user_id'           => Auth::id() ?: 1,
                        'from_store_id'     => $product['from_store_id'] ?? null,
                        'to_store_id'       => $product['to_store_id'] ?? null,
                        'source_product_id' => $product['id'] ?? null,
                    ],
                    [
                        'source_product_sku'     => $product['sku'] ?? null,
                        'source_product_name'    => $product['name'] ?? null,
                        'destination_product_id' => $newProductId,
                        'status'                 => 'success',
                        'error_message'          => null,
                    ]
                );
            }

        } catch (\Throwable $e) {
            $errors[] = "Exception for product {$product['name']}: " . $e->getMessage();
        }

        return [
            'success' => $imported > 0,
            'imported' => $imported,
            'inventoryUpdated' => $inventoryUpdated,
            'product_id' => $newProductId,
            'errors' => $errors
        ];
    }

}
