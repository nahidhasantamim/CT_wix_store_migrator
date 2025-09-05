<?php

namespace App\Http\Controllers;

use App\Helpers\WixHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use App\Models\WixStore;
use Illuminate\Support\Facades\Log;
use App\Models\WixProductMigration;
use Illuminate\Support\Facades\Auth;

class WixProductController extends Controller
{
    // Filter product array for import (keep only safe/allowed fields)
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

        unset($filtered['id']);
        return $filtered;
    }

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

        // 1. Get catalog version
        $catalogVersion = WixHelper::getCatalogVersion($accessToken);

        // 2. Fetch all collections using WixCategoryController (for robust results)
        $collectionIdToSlug = [];
        try {
            $catController = app(\App\Http\Controllers\WixCategoryController::class);
            $collectionsArr = $catController->getCollectionsFromWix($accessToken);

            foreach (($collectionsArr['collections'] ?? []) as $c) {
                if (!empty($c['id']) && !empty($c['slug'])) {
                    $collectionIdToSlug[$c['id']] = $c['slug'];
                }
            }
            // Optional debug
            Log::debug('Wix export: collections', [
                'count' => count($collectionIdToSlug),
                'sample' => array_slice($collectionIdToSlug, 0, 3)
            ]);
        } catch (\Throwable $e) {
            // Fallback to direct API if controller is missing or fails
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

        // 3. Get all products (paging, catalog-aware)
        $productsResponse = $this->getAllProducts($accessToken, $store);
        $products = $productsResponse['products'] ?? [];

        // 4. Get all inventory items
        $inventoryItems = $this->queryInventoryItems($accessToken)['inventoryItems'] ?? [];
        $skuInventoryMap = [];
        foreach ($inventoryItems as $inv) {
            if (!empty($inv['sku'])) {
                $skuInventoryMap[$inv['sku']] = $inv;
            }
        }

        // --- DB Tracking Section ---
        $userId = Auth::id() ?? 1;
        $fromStoreId = $store->instance_id;

        // [V3] Optionally fetch brands/ribbons/infoSections/customizations for richer export
        $brands = $ribbons = $infoSections = $customizations = [];
        if ($catalogVersion === 'V3_CATALOG') {
            // --- BRANDS
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

            // --- RIBBONS
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

            // --- INFO SECTIONS
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

            // --- CUSTOMIZATIONS
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

            // --- V3: Export details of brand/ribbon/infoSections/customizations if present ---
            if ($catalogVersion === 'V3_CATALOG') {
                if (!empty($product['brand']['id']) && isset($brands[$product['brand']['id']])) {
                    $product['brand_export'] = $brands[$product['brand']['id']];
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
    // public function import(Request $request, WixStore $store)
    // {
    //     $accessToken = WixHelper::getAccessToken($store->instance_id);
    //     if (!$accessToken) {
    //         WixHelper::log('Import Products+Inventory', 'Could not get Wix access token.', 'error');
    //         return back()->with('error', 'Could not get Wix access token.');
    //     }

    //     if (!$request->hasFile('products_inventory_json')) {
    //         WixHelper::log('Import Products+Inventory', 'No file uploaded.', 'error');
    //         return back()->with('error', 'No file uploaded.');
    //     }

    //     $file = $request->file('products_inventory_json');
    //     $json = file_get_contents($file->getRealPath());
    //     $decoded = json_decode($json, true);

    //     if (json_last_error() !== JSON_ERROR_NONE || !isset($decoded['from_store_id'], $decoded['products']) || !is_array($decoded['products'])) {
    //         WixHelper::log('Import Products+Inventory', 'Invalid JSON structure.', 'error');
    //         return back()->with('error', 'Invalid JSON structure. Required keys: from_store_id and products.');
    //     }

    //     $fromStoreId = $decoded['from_store_id'];
    //     $products = $decoded['products'];

    //     usort($products, function ($a, $b) {
    //         $dateA = isset($a['createdDate']) ? strtotime($a['createdDate']) : 0;
    //         $dateB = isset($b['createdDate']) ? strtotime($b['createdDate']) : 0;
    //         return $dateA <=> $dateB;
    //     });

    //     $catalogVersion = WixHelper::getCatalogVersion($accessToken);

    //     $imported = 0;
    //     $inventoryUpdated = 0;
    //     $errors = [];

    //     $collectionSlugMap = [];
    //     $userId = Auth::id() ?? 1;

    //     // ----- Prepare relation maps (source_id => destination_id) -----
    //     if ($catalogVersion === 'V3_CATALOG') {
    //         $brandIdMap = \App\Models\WixBrandMigration::where('from_store_id', $fromStoreId)
    //             ->where('to_store_id', $store->instance_id)
    //             ->whereNotNull('destination_brand_id')
    //             ->pluck('destination_brand_id', 'source_brand_id')
    //             ->toArray();

    //         $ribbonIdMap = \App\Models\WixRibbonMigration::where('from_store_id', $fromStoreId)
    //             ->where('to_store_id', $store->instance_id)
    //             ->whereNotNull('destination_ribbon_id')
    //             ->pluck('destination_ribbon_id', 'source_ribbon_id')
    //             ->toArray();

    //         $customizationIdMap = \App\Models\WixCustomizationMigration::where('from_store_id', $fromStoreId)
    //             ->where('to_store_id', $store->instance_id)
    //             ->whereNotNull('destination_customization_id')
    //             ->pluck('destination_customization_id', 'source_customization_id')
    //             ->toArray();

    //         $infoSectionIdMap = \App\Models\WixInfoSectionMigration::where('from_store_id', $fromStoreId)
    //             ->where('to_store_id', $store->instance_id)
    //             ->whereNotNull('destination_info_section_id')
    //             ->pluck('destination_info_section_id', 'source_info_section_id')
    //             ->toArray();
    //     }

    //     foreach ($products as $product) {
    //         $migrationKey = [
    //             'user_id'           => $userId,
    //             'from_store_id'     => $fromStoreId,
    //             'source_product_id' => $product['id'] ?? null,
    //         ];

    //         $migrationData = [
    //             'source_product_sku'   => $product['sku'] ?? null,
    //             'source_product_name'  => $product['name'] ?? null,
    //             'status'               => 'pending',
    //             'error_message'        => null,
    //             'destination_product_id' => null,
    //         ];

    //         try {
    //             WixHelper::log('Import Products+Inventory', [
    //                 'step' => 'processing',
    //                 'name' => $product['name'] ?? '[No Name]',
    //                 'sku' => $product['sku'] ?? '[No SKU]'
    //             ]);

    //             // ======================== V3 FLOW ========================
    //             if ($catalogVersion === 'V3_CATALOG') {
    //                 $body = [
    //                     'product' => [
    //                         'name' => $product['name'] ?? 'Unnamed Product',
    //                         'slug' => $product['slug'] ?? null,
    //                         'plainDescription' => $product['description'] ?? '',
    //                         'visible' => $product['visible'] ?? true,
    //                         'media' => $product['media'] ?? [],
    //                         'productType' => strtoupper($product['productType'] ?? 'PHYSICAL'),
    //                         'variantsInfo' => [
    //                             'variants' => [],
    //                         ],
    //                         'physicalProperties' => [],
    //                     ]
    //                 ];

    //                 // --- Map & Assign Relations ---
    //                 // Brand
    //                 if (!empty($product['brand']['id']) && isset($brandIdMap[$product['brand']['id']])) {
    //                     $body['product']['brand'] = ['id' => $brandIdMap[$product['brand']['id']]];
    //                 }
    //                 // Ribbon
    //                 if (!empty($product['ribbon']['id']) && isset($ribbonIdMap[$product['ribbon']['id']])) {
    //                     $body['product']['ribbon'] = ['id' => $ribbonIdMap[$product['ribbon']['id']]];
    //                 }
    //                 // Customizations
    //                 if (!empty($product['customizations']) && is_array($product['customizations'])) {
    //                     $body['product']['customizations'] = [];
    //                     foreach ($product['customizations'] as $customization) {
    //                         $srcId = is_array($customization) ? $customization['id'] ?? null : $customization;
    //                         if ($srcId && isset($customizationIdMap[$srcId])) {
    //                             $body['product']['customizations'][] = ['id' => $customizationIdMap[$srcId]];
    //                         }
    //                     }
    //                 }
    //                 // InfoSections
    //                 if (!empty($product['infoSections']) && is_array($product['infoSections'])) {
    //                     $body['product']['infoSections'] = [];
    //                     foreach ($product['infoSections'] as $infoSection) {
    //                         $srcId = is_array($infoSection) ? $infoSection['id'] ?? null : $infoSection;
    //                         if ($srcId && isset($infoSectionIdMap[$srcId])) {
    //                             $body['product']['infoSections'][] = ['id' => $infoSectionIdMap[$srcId]];
    //                         }
    //                     }
    //                 }

    //                 // Variants
    //                 $variantSource = !empty($product['variants_full']) ? $product['variants_full'] : ($product['variants'] ?? []);
    //                 if (!empty($variantSource)) {
    //                     foreach ($variantSource as $variant) {
    //                         $flat = isset($variant['variant']) ? $variant['variant'] : $variant;
    //                         $choices = $variant['choices'] ?? $flat['choices'] ?? [];
    //                         $sku = $flat['sku'] ?? $variant['sku'] ?? 'SKU-' . uniqid();
    //                         $weight = $flat['weight'] ?? null;
    //                         $visible = $flat['visible'] ?? true;
    //                         $price = $flat['priceData']['price'] ?? $flat['price'] ?? 0;
    //                         $quantity = $flat['stock']['quantity'] ?? $variant['stock']['quantity'] ?? $product['stock']['quantity'] ?? null;
    //                         $inStock = $flat['stock']['inStock'] ?? $variant['stock']['inStock'] ?? $product['stock']['inStock'] ?? true;

    //                         $body['product']['variantsInfo']['variants'][] = [
    //                             'sku' => $sku,
    //                             'choices' => $choices,
    //                             'price' => [
    //                                 'actualPrice' => [
    //                                     'amount' => strval($price),
    //                                 ]
    //                             ],
    //                             'inventoryItem' => [
    //                                 'quantity' => $quantity,
    //                                 'inStock' => $inStock,
    //                             ],
    //                             'weight' => $weight,
    //                             'visible' => $visible,
    //                         ];
    //                     }
    //                 } else {
    //                     $body['product']['variantsInfo']['variants'][] = [
    //                         'sku' => $product['sku'] ?? 'SKU-' . uniqid(),
    //                         'choices' => [],
    //                         'price' => [
    //                             'actualPrice' => [
    //                                 'amount' => strval($product['price']['price'] ?? 0),
    //                             ]
    //                         ],
    //                         'inventoryItem' => [
    //                             'quantity' => $product['stock']['quantity'] ?? null,
    //                             'inStock' => $product['stock']['inStock'] ?? true,
    //                         ]
    //                     ];
    //                 }

    //                 WixHelper::log('Import Products+Inventory', [
    //                     'step' => 'posting V3',
    //                     'payload' => $body
    //                 ]);
    //                 $response = Http::withHeaders([
    //                     'Authorization' => $accessToken,
    //                     'Content-Type'  => 'application/json'
    //                 ])->post('https://www.wixapis.com/stores/v3/products-with-inventory', $body);

    //                 WixHelper::log('Import Products+Inventory', [
    //                     'step' => 'V3 response',
    //                     'response' => $response->body()
    //                 ], $response->ok() ? 'success' : 'error');

    //                 if ($response->ok() && isset($response->json()['product']['id'])) {
    //                     $imported++;
    //                     $inventoryUpdated++;
    //                     $migrationData['status'] = 'success';
    //                     $migrationData['destination_product_id'] = $response->json()['product']['id'];
    //                     $migrationData['error_message'] = null;
    //                 } else {
    //                     $migrationData['status'] = 'failed';
    //                     $migrationData['error_message'] = 'V3 product-with-inventory failed: ' . $response->body();
    //                     $errors[] = $migrationData['error_message'];
    //                 }
    //             }
    //             // ======================== V1 FLOW (untouched) ========================
    //             elseif ($catalogVersion === 'V1_CATALOG') {
    //                 $filteredProduct = $this->filterWixProductForImport($product);

    //                 if (empty($filteredProduct['sku'])) {
    //                     $filteredProduct['sku'] = 'SKU-' . uniqid();
    //                 }

    //                 // Add customTextFields if present
    //                 if (!empty($product['customTextFields'])) {
    //                     $filteredProduct['customTextFields'] = $product['customTextFields'];
    //                 }

    //                 WixHelper::log('Import Products+Inventory', [
    //                     'step' => 'posting V1',
    //                     'payload' => $filteredProduct
    //                 ]);
    //                 $response = Http::withHeaders([
    //                     'Authorization' => $accessToken,
    //                     'Content-Type'  => 'application/json'
    //                 ])->post('https://www.wixapis.com/stores/v1/products', ["product" => $filteredProduct]);

    //                 WixHelper::log('Import Products+Inventory', [
    //                     'step' => 'V1 response',
    //                     'response' => $response->body()
    //                 ], $response->ok() ? 'success' : 'error');

    //                 $result = $response->json();
    //                 if ($response->status() === 200 && isset($result['product']['id'])) {
    //                     $imported++;
    //                     $newProductId = $result['product']['id'];
    //                     $migrationData['status'] = 'success';
    //                     $migrationData['destination_product_id'] = $newProductId;
    //                     $migrationData['error_message'] = null;

    //                     $createdProduct = $result['product'];
    //                     $hasVariants = !empty($createdProduct['variants']);

    //                     // ---- Inventory PATCH unchanged ----
    //                     $inventoryBody = [
    //                         'inventoryItem' => [
    //                             'trackQuantity' => true,
    //                             'variants' => [],
    //                         ]
    //                     ];

    //                     if ($hasVariants) {
    //                         foreach ($createdProduct['variants'] as $i => $variant) {
    //                             $origVariant = ($product['variants_full'][$i] ?? null) ?: ($product['variants'][$i] ?? []);
    //                             $flat = isset($origVariant['variant']) ? $origVariant['variant'] : $origVariant;
    //                             $quantity = $flat['stock']['quantity'] ?? $origVariant['stock']['quantity'] ?? $product['stock']['quantity'] ?? null;
    //                             $inventoryBody['inventoryItem']['variants'][] = [
    //                                 'variantId' => $variant['id'],
    //                                 'quantity' => $quantity,
    //                             ];
    //                         }
    //                     } else {
    //                         $quantity = $product['stock']['quantity'] ?? null;
    //                         $inventoryBody['inventoryItem']['variants'][] = [
    //                             'variantId' => '00000000-0000-0000-0000-000000000000',
    //                             'quantity' => $quantity,
    //                         ];
    //                     }

    //                     WixHelper::log('Import Products+Inventory', [
    //                         'step' => 'patching inventory V1',
    //                         'product_id' => $newProductId,
    //                         'payload' => $inventoryBody
    //                     ]);

    //                     $invRes = Http::withHeaders([
    //                         'Authorization' => $accessToken,
    //                         'Content-Type'  => 'application/json'
    //                     ])->patch("https://www.wixapis.com/stores/v2/inventoryItems/product/{$newProductId}", $inventoryBody);

    //                     WixHelper::log('Import Products+Inventory', [
    //                         'step' => 'V1 Inventory PATCH response',
    //                         'response' => $invRes->body()
    //                     ], $invRes->ok() ? 'success' : 'error');

    //                     if ($invRes->ok()) {
    //                         $inventoryUpdated++;
    //                     } else {
    //                         $errors[] = "V1 inventory failed for SKU {$filteredProduct['sku']}: " . $invRes->body();
    //                     }

    //                     // ---------- FULL VARIANT DATA ----------
    //                     $variantSource = !empty($product['variants_full']) ? $product['variants_full'] : ($product['variants'] ?? []);
    //                     if ($hasVariants && !empty($variantSource)) {
    //                         $variantsPayload = [];
    //                         foreach ($product['variants_full'] as $variantData) {
    //                             $variant = $variantData['variant'] ?? [];
    //                             $variantUpdate = [
    //                                 'choices' => $variantData['choices'] ?? [],
    //                             ];
    //                             // These fields are all optional in PATCH, but set if present:
    //                             if (isset($variant['priceData']['price'])) {
    //                                 $variantUpdate['price'] = $variant['priceData']['price'];
    //                             }
    //                             if (isset($variant['costAndProfitData']['itemCost'])) {
    //                                 $variantUpdate['cost'] = $variant['costAndProfitData']['itemCost'];
    //                             }
    //                             if (isset($variant['weight'])) {
    //                                 $variantUpdate['weight'] = $variant['weight'];
    //                             }
    //                             if (!empty($variant['sku'])) {
    //                                 $variantUpdate['sku'] = $variant['sku'];
    //                             }
    //                             if (isset($variant['visible'])) {
    //                                 $variantUpdate['visible'] = $variant['visible'];
    //                             }
    //                             $variantsPayload[] = $variantUpdate;
    //                         }

    //                         // Then PATCH to update all variant fields for the product
    //                         if (count($variantsPayload)) {
    //                             $variantsRes = Http::withHeaders([
    //                                 'Authorization' => $accessToken,
    //                                 'Content-Type'  => 'application/json'
    //                             ])->patch("https://www.wixapis.com/stores/v1/products/{$newProductId}/variants", [
    //                                 'variants' => $variantsPayload
    //                             ]);
    //                             WixHelper::log('Import Products+Inventory', [
    //                                 'step' => 'PATCH variants',
    //                                 'product_id' => $newProductId,
    //                                 'payload' => $variantsPayload,
    //                                 'response' => $variantsRes->body()
    //                             ], $variantsRes->ok() ? 'success' : 'error');
    //                         }
    //                     }

    //                     // ---------- Media ----------
    //                     if (!empty($product['media']['items'])) {
    //                         $mediaItems = [];
    //                         foreach ($product['media']['items'] as $media) {
    //                             if (!empty($media['id'])) {
    //                                 $mediaItems[] = ['mediaId' => $media['id']];
    //                             } elseif (!empty($media['image']['url'])) {
    //                                 $mediaItem = ['url' => $media['image']['url']];
    //                                 if (!empty($media['choice'])) {
    //                                     $mediaItem['choice'] = $media['choice'];
    //                                 }
    //                                 $mediaItems[] = $mediaItem;
    //                             }
    //                         }
    //                         if (count($mediaItems)) {
    //                             $mediaRes = Http::withHeaders([
    //                                 'Authorization' => $accessToken,
    //                                 'Content-Type'  => 'application/json'
    //                             ])->post("https://www.wixapis.com/stores/v1/products/{$newProductId}/media", [
    //                                 'media' => $mediaItems
    //                             ]);
    //                             WixHelper::log('Import Products+Inventory', [
    //                                 'step' => 'POST product media',
    //                                 'product_id' => $newProductId,
    //                                 'mediaItems' => $mediaItems,
    //                                 'response' => $mediaRes->body()
    //                             ], $mediaRes->ok() ? 'success' : 'error');
    //                         }
    //                     }

    //                     // ---------- Add Media to Choices from productOptions ----------
    //                     if (!empty($product['productOptions'])) {
    //                         foreach ($product['productOptions'] as $option) {
    //                             if (empty($option['choices']) || empty($option['name'])) continue;

    //                             foreach ($option['choices'] as $choice) {
    //                                 $mediaIds = [];

    //                                 // Add mainMedia id if present
    //                                 if (!empty($choice['media']['mainMedia']['id'])) {
    //                                     $mediaIds[] = $choice['media']['mainMedia']['id'];
    //                                 }
    //                                 // Add each item id if present
    //                                 if (!empty($choice['media']['items']) && is_array($choice['media']['items'])) {
    //                                     foreach ($choice['media']['items'] as $mediaItem) {
    //                                         if (!empty($mediaItem['id'])) {
    //                                             $mediaIds[] = $mediaItem['id'];
    //                                         }
    //                                     }
    //                                 }

    //                                 if (count($mediaIds)) {
    //                                     $optionName = $option['name'];
    //                                     $choiceName = $choice['description'] ?? $choice['value'] ?? null;
    //                                     if (!$choiceName) continue;

    //                                     $patchBody = [
    //                                         'media' => [[
    //                                             'option' => $optionName,
    //                                             'choice' => $choiceName,
    //                                             'mediaIds' => array_unique($mediaIds),
    //                                         ]]
    //                                     ];

    //                                     $mediaToChoicesRes = Http::withHeaders([
    //                                         'Authorization' => $accessToken,
    //                                         'Content-Type'  => 'application/json'
    //                                     ])->patch("https://www.wixapis.com/stores/v1/products/{$newProductId}/choices/media", $patchBody);

    //                                     WixHelper::log('Import Products+Inventory', [
    //                                         'step' => 'PATCH media to choices (productOptions)',
    //                                         'product_id' => $newProductId,
    //                                         'patchBody' => $patchBody,
    //                                         'response' => $mediaToChoicesRes->body()
    //                                     ], $mediaToChoicesRes->ok() ? 'success' : 'error');
    //                                 }
    //                             }
    //                         }
    //                     }

    //                     // --- CONNECT PRODUCT TO COLLECTIONS ---
    //                     if (!empty($product['collectionSlugs']) && is_array($product['collectionSlugs'])) {
    //                         foreach ($product['collectionSlugs'] as $slug) {
    //                             if (!is_string($slug) || trim($slug) === '') continue;
    //                             // Check map/cache
    //                             if (isset($collectionSlugMap[$slug])) {
    //                                 $collectionId = $collectionSlugMap[$slug];
    //                             } else {
    //                                 $urlSlug = urlencode($slug);
    //                                 $collectionResp = Http::withHeaders([
    //                                     'Authorization' => $accessToken,
    //                                     'Content-Type'  => 'application/json'
    //                                 ])->get("https://www.wixapis.com/stores/v1/collections/slug/{$urlSlug}");

    //                                 $collection = $collectionResp->json('collection');
    //                                 if ($collectionResp->ok() && isset($collection['id'])) {
    //                                     $collectionId = $collection['id'];
    //                                     $collectionSlugMap[$slug] = $collectionId;
    //                                 } else {
    //                                     $errors[] = "Could not find collection for slug '{$slug}'.";
    //                                     continue;
    //                                 }
    //                             }
    //                             // Skip All Products collection
    //                             if ($collectionId === '00000000-0000-0000-0000-000000000001') continue;

    //                             // Add product to collection
    //                             $addResp = Http::withHeaders([
    //                                 'Authorization' => $accessToken,
    //                                 'Content-Type'  => 'application/json'
    //                             ])->post("https://www.wixapis.com/stores/v1/collections/{$collectionId}/productIds", [
    //                                 'productIds' => [$newProductId]
    //                             ]);
    //                             if (!$addResp->ok()) {
    //                                 $errors[] = "Failed to add product to collection '{$slug}': " . $addResp->body();
    //                             } else {
    //                                 WixHelper::log('Import Products+Inventory', [
    //                                     'step' => 'Added product to collection',
    //                                     'product_id' => $newProductId,
    //                                     'collection_id' => $collectionId,
    //                                     'slug' => $slug,
    //                                     'response' => $addResp->body()
    //                                 ], 'success');
    //                             }
    //                         }
    //                     }
    //                 } else {
    //                     $migrationData['status'] = 'failed';
    //                     $migrationData['error_message'] = "V1 product creation failed: " . $response->body();
    //                     $errors[] = $migrationData['error_message'];
    //                 }
    //             }
    //             else {
    //                 $migrationData['status'] = 'failed';
    //                 $migrationData['error_message'] = "Unknown or unsupported catalog version: $catalogVersion";
    //                 $errors[] = $migrationData['error_message'];
    //             }
    //         } catch (\Throwable $e) {
    //             $migrationData['status'] = 'failed';
    //             $migrationData['error_message'] = $e->getMessage();
    //             $errors[] = "Exception for product {$product['name']}: " . $e->getMessage();
    //         }

    //         WixProductMigration::updateOrCreate($migrationKey, $migrationData);
    //     }

    //     if ($imported > 0) {
    //         WixHelper::log('Import Products+Inventory', [
    //             'imported' => $imported,
    //             'inventoryUpdated' => $inventoryUpdated,
    //             'errors' => $errors
    //         ], count($errors) ? 'error' : 'success');
    //         return back()->with('success', "$imported product(s) imported. $inventoryUpdated inventory item(s) created.");
    //     } else {
    //         WixHelper::log('Import Products+Inventory', [
    //             'imported' => $imported,
    //             'inventoryUpdated' => $inventoryUpdated,
    //             'errors' => $errors
    //         ], 'error');
    //         return back()->with('error', 'No products imported. Errors: ' . implode("; ", $errors));
    //     }
    // }

    public function import(Request $request, WixStore $store)
    {
        $accessToken = WixHelper::getAccessToken($store->instance_id);
        if (!$accessToken) {
            WixHelper::log('Import Products+Inventory', 'Could not get Wix access token.', 'error');
            return back()->with('error', 'Could not get Wix access token.');
        }

        if (!$request->hasFile('products_inventory_json')) {
            WixHelper::log('Import Products+Inventory', 'No file uploaded.', 'error');
            return back()->with('error', 'No file uploaded.');
        }

        $file = $request->file('products_inventory_json');
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

        $relationMaps = $this->prepareRelationMaps($catalogVersion, $fromStoreId, $store->instance_id);

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

        // Detect catalog version
        $catalogVersion = WixHelper::getCatalogVersion($accessToken);

        do {
            if ($catalogVersion === 'V3_CATALOG') {
                // V3 expects query as array
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
                // V1 expects query as object (cast) -- **MINIMAL QUERY**
                $query = new \stdClass();
                if ($cursor) {
                    $query->paging = ['offset' => $cursor];
                }
                $body = [
                    'query' => $query
                ];
                $endpoint = 'https://www.wixapis.com/stores/v1/products/query';
            }

            // Make request
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
            // V3
            $endpoint = 'https://www.wixapis.com/stores/v3/inventory-items/query';
            $body = [
                'query' => $query
            ];
        } else {
            // V1
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


    private function prepareRelationMaps($catalogVersion, $fromStoreId, $toStoreId)
    {
        if ($catalogVersion === 'V3_CATALOG') {
            return [
                'brandIdMap' => \App\Models\WixBrandMigration::where('from_store_id', $fromStoreId)
                    ->where('to_store_id', $toStoreId)
                    ->whereNotNull('destination_brand_id')
                    ->pluck('destination_brand_id', 'source_brand_id')
                    ->toArray(),
                'ribbonIdMap' => \App\Models\WixRibbonMigration::where('from_store_id', $fromStoreId)
                    ->where('to_store_id', $toStoreId)
                    ->whereNotNull('destination_ribbon_id')
                    ->pluck('destination_ribbon_id', 'source_ribbon_id')
                    ->toArray(),
                'customizationIdMap' => \App\Models\WixCustomizationMigration::where('from_store_id', $fromStoreId)
                    ->where('to_store_id', $toStoreId)
                    ->whereNotNull('destination_customization_id')
                    ->pluck('destination_customization_id', 'source_customization_id')
                    ->toArray(),
                'infoSectionIdMap' => \App\Models\WixInfoSectionMigration::where('from_store_id', $fromStoreId)
                    ->where('to_store_id', $toStoreId)
                    ->whereNotNull('destination_info_section_id')
                    ->pluck('destination_info_section_id', 'source_info_section_id')
                    ->toArray(),
            ];
        }
        return [];
    }

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
                [$result, $errMsg] = $this->importProductV3($accessToken, $product, $relationMaps);
                if ($result) {
                    $imported++;
                    $inventoryUpdated++;
                    $migrationData['status'] = 'success';
                    $migrationData['destination_product_id'] = $result['id'];
                } else {
                    $migrationData['status'] = 'failed';
                    $migrationData['error_message'] = $errMsg;
                    $error = $errMsg;
                }
            } elseif ($catalogVersion === 'V1_CATALOG') {
                [$result, $inventory, $errMsg] = $this->importProductV1($accessToken, $product, $fromStoreId, $toStoreId, $collectionSlugMap);
                if ($result) {
                    $imported++;
                    $inventoryUpdated += $inventory;
                    $migrationData['status'] = 'success';
                    $migrationData['destination_product_id'] = $result;
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

    private function importProductV3($accessToken, $product, $relationMaps)
    {
        $body = [
            'product' => [
                'name' => $product['name'] ?? 'Unnamed Product',
                'slug' => $product['slug'] ?? null,
                'plainDescription' => $product['description'] ?? '',
                'visible' => $product['visible'] ?? true,
                'media' => $product['media'] ?? [],
                'productType' => strtoupper($product['productType'] ?? 'PHYSICAL'),
                'variantsInfo' => [
                    'variants' => [],
                ],
                'physicalProperties' => [],
            ]
        ];

        // --- Map & Assign Relations ---
        if (!empty($product['brand']['id']) && isset($relationMaps['brandIdMap'][$product['brand']['id']])) {
            $body['product']['brand'] = ['id' => $relationMaps['brandIdMap'][$product['brand']['id']]];
        }
        if (!empty($product['ribbon']['id']) && isset($relationMaps['ribbonIdMap'][$product['ribbon']['id']])) {
            $body['product']['ribbon'] = ['id' => $relationMaps['ribbonIdMap'][$product['ribbon']['id']]];
        }
        // Customizations
        if (!empty($product['customizations']) && is_array($product['customizations'])) {
            $body['product']['customizations'] = [];
            foreach ($product['customizations'] as $customization) {
                $srcId = is_array($customization) ? $customization['id'] ?? null : $customization;
                if ($srcId && isset($relationMaps['customizationIdMap'][$srcId])) {
                    $body['product']['customizations'][] = ['id' => $relationMaps['customizationIdMap'][$srcId]];
                }
            }
        }
        // InfoSections
        if (!empty($product['infoSections']) && is_array($product['infoSections'])) {
            $body['product']['infoSections'] = [];
            foreach ($product['infoSections'] as $infoSection) {
                $srcId = is_array($infoSection) ? $infoSection['id'] ?? null : $infoSection;
                if ($srcId && isset($relationMaps['infoSectionIdMap'][$srcId])) {
                    $body['product']['infoSections'][] = ['id' => $relationMaps['infoSectionIdMap'][$srcId]];
                }
            }
        }

        // Variants
        $variantSource = !empty($product['variants_full']) ? $product['variants_full'] : ($product['variants'] ?? []);
        if (!empty($variantSource)) {
            foreach ($variantSource as $variant) {
                $flat = isset($variant['variant']) ? $variant['variant'] : $variant;
                $choices = $variant['choices'] ?? $flat['choices'] ?? [];
                $sku = $flat['sku'] ?? $variant['sku'] ?? 'SKU-' . uniqid();
                $weight = $flat['weight'] ?? null;
                $visible = $flat['visible'] ?? true;
                $price = $flat['priceData']['price'] ?? $flat['price'] ?? 0;
                $quantity = $flat['stock']['quantity'] ?? $variant['stock']['quantity'] ?? $product['stock']['quantity'] ?? null;
                $inStock = $flat['stock']['inStock'] ?? $variant['stock']['inStock'] ?? $product['stock']['inStock'] ?? true;

                $body['product']['variantsInfo']['variants'][] = [
                    'sku' => $sku,
                    'choices' => $choices,
                    'price' => [
                        'actualPrice' => [
                            'amount' => strval($price),
                        ]
                    ],
                    'inventoryItem' => [
                        'quantity' => $quantity,
                        'inStock' => $inStock,
                    ],
                    'weight' => $weight,
                    'visible' => $visible,
                ];
            }
        } else {
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

        WixHelper::log('Import Products+Inventory', [
            'step' => 'posting V3',
            'payload' => $body
        ]);
        $response = Http::withHeaders([
            'Authorization' => $accessToken,
            'Content-Type'  => 'application/json'
        ])->post('https://www.wixapis.com/stores/v3/products-with-inventory', $body);

        WixHelper::log('Import Products+Inventory', [
            'step' => 'V3 response',
            'response' => $response->body()
        ], $response->ok() ? 'success' : 'error');

        if ($response->ok() && isset($response->json()['product']['id'])) {
            return [$response->json()['product'], null];
        }
        return [null, 'V3 product-with-inventory failed: ' . $response->body()];
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
                        // Log or collect errors if needed
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
                        'plainDescription' => $product['description'] ?? '',
                        'visible' => $product['visible'] ?? true,
                        'media' => $product['media'] ?? [],
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
                    foreach ($variantSource as $variant) {
                        $flat = isset($variant['variant']) ? $variant['variant'] : $variant;
                        $choices = $variant['choices'] ?? $flat['choices'] ?? [];
                        $sku = $flat['sku'] ?? $variant['sku'] ?? 'SKU-' . uniqid();
                        $weight = $flat['weight'] ?? null;
                        $visible = $flat['visible'] ?? true;
                        $price = $flat['priceData']['price'] ?? $flat['price'] ?? 0;
                        $quantity = $flat['stock']['quantity'] ?? $variant['stock']['quantity'] ?? $product['stock']['quantity'] ?? null;
                        $inStock = $flat['stock']['inStock'] ?? $variant['stock']['inStock'] ?? $product['stock']['inStock'] ?? true;

                        $body['product']['variantsInfo']['variants'][] = [
                            'sku' => $sku,
                            'choices' => $choices,
                            'price' => [
                                'actualPrice' => [
                                    'amount' => strval($price),
                                ]
                            ],
                            'inventoryItem' => [
                                'quantity' => $quantity,
                                'inStock' => $inStock,
                            ],
                            'weight' => $weight,
                            'visible' => $visible,
                        ];
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
                                        ]]
                                    ];
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
                                // Fallback: Try to resolve by slug in target store
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
                            // Skip All Products collection
                            if ($collectionId === '00000000-0000-0000-0000-000000000001' || !$collectionId) continue;

                            // Add product to collection
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
