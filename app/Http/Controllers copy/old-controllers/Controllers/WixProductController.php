<?php

namespace App\Http\Controllers;

use App\Helpers\WixHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use App\Models\WixStore;
use Illuminate\Support\Facades\Log;

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

    // --- EXPORT PRODUCTS + INVENTORY ---
    public function export(WixStore $store)
    {
        WixHelper::log('Export Products+Inventory', "Export started for store: $store->store_name", 'info');
    
        $accessToken = WixHelper::getAccessToken($store->instance_id);
        if (!$accessToken) {
            return response()->json(['error' => 'Could not get Wix access token.'], 401);
        }
    
        // 1. Fetch all collections once
        $collectionsResp = Http::withHeaders([
            'Authorization' => $accessToken,
            'Content-Type'  => 'application/json'
        ])->post('https://www.wixapis.com/stores-reader/v1/collections/query', [
            "paging" => ["limit" => 1000]
        ]);
        $collections = $collectionsResp->json('collections') ?? [];
        $collectionIdToSlug = [];
        foreach ($collections as $c) {
            if (!empty($c['id']) && !empty($c['slug'])) {
                $collectionIdToSlug[$c['id']] = $c['slug'];
            }
        }
    
        // 2. Get all products
        $productsResponse = $this->getAllProducts($accessToken, $store);
        $products = $productsResponse['products'] ?? [];
    
        // 3. Get all inventory items
        $inventoryItems = $this->queryInventoryItems($accessToken)['inventoryItems'] ?? [];
        $skuInventoryMap = [];
        foreach ($inventoryItems as $inv) {
            if (!empty($inv['sku'])) {
                $skuInventoryMap[$inv['sku']] = $inv;
            }
        }
    
        // 4. For each product, get detailed variant info, attach inventory, and resolve collectionSlugs
        foreach ($products as &$product) {
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
        }
    
        WixHelper::log('Export Products+Inventory', "Exported " . count($products) . " products with inventory and variants.", 'success');
    
        return response()->streamDownload(function() use ($products) {
            echo json_encode($products, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }, 'products_and_inventory.json', [
            'Content-Type' => 'application/json'
        ]);
    }

    // --- IMPORT PRODUCTS + INVENTORY ---
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

        // Get products from uploaded file
        $file = $request->file('products_inventory_json');
        $json = file_get_contents($file->getRealPath());
        $products = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($products)) {
            WixHelper::log('Import Products+Inventory', 'Uploaded file is not valid JSON.', 'error');
            return back()->with('error', 'Uploaded file is not valid JSON.');
        }

        // Sort products by createdDate ascending (oldest first)
        usort($products, function ($a, $b) {
            $dateA = isset($a['createdDate']) ? strtotime($a['createdDate']) : 0;
            $dateB = isset($b['createdDate']) ? strtotime($b['createdDate']) : 0;
            return $dateA <=> $dateB;
        });

        // Step 1: Detect catalog version
        $catalogVersionResp = Http::withHeaders([
            'Authorization' => $accessToken,
            'Content-Type'  => 'application/json'
        ])->get('https://www.wixapis.com/stores/v3/provision/version');
        $catalogVersion = $catalogVersionResp->json('catalogVersion');
        WixHelper::log('Import Products+Inventory', "Detected catalog version: $catalogVersion");

        $imported = 0;
        $inventoryUpdated = 0;
        $errors = [];

        // Prepare the collectionSlugMap cache once for all products
        $collectionSlugMap = [];

        foreach ($products as $product) {
            WixHelper::log('Import Products+Inventory', [
                'step' => 'processing',
                'name' => $product['name'] ?? '[No Name]',
                'sku' => $product['sku'] ?? '[No SKU]'
            ]);

            // --- V3 FLOW (Not tested) ---
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

                // Prefer variants_full over legacy variants
                $variantSource = !empty($product['variants_full']) ? $product['variants_full'] : ($product['variants'] ?? []);
                if (!empty($variantSource)) {
                    foreach ($variantSource as $variant) {
                        $flat = isset($variant['variant']) ? $variant['variant'] : $variant;
                        $choices = $variant['choices'] ?? $flat['choices'] ?? [];
                        $sku = $flat['sku'] ?? $variant['sku'] ?? null;
                        $weight = $flat['weight'] ?? null;
                        $visible = $flat['visible'] ?? true;
                        // Price
                        $price = $flat['priceData']['price'] ?? $flat['price'] ?? 0;
                        // Quantity: try to preserve your pattern
                        $quantity = $flat['stock']['quantity'] ?? $variant['stock']['quantity'] ?? $product['stock']['quantity'] ?? null;
                        $inStock = $flat['stock']['inStock'] ?? $variant['stock']['inStock'] ?? $product['stock']['inStock'] ?? true;

                        $body['product']['variantsInfo']['variants'][] = [
                            'sku' => $sku ?? 'SKU-' . uniqid(),
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
                    $imported++;
                    $inventoryUpdated++;
                } else {
                    $errors[] = 'V3 product-with-inventory failed: ' . $response->body();
                }
            }
            // --- V1 FLOW ---
            elseif ($catalogVersion === 'V1_CATALOG') {
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
                    $imported++;
                    $newProductId = $result['product']['id'];
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
                        'product_id' => $newProductId,
                        'payload' => $inventoryBody
                    ]);

                    $invRes = Http::withHeaders([
                        'Authorization' => $accessToken,
                        'Content-Type'  => 'application/json'
                    ])->patch("https://www.wixapis.com/stores/v2/inventoryItems/product/{$newProductId}", $inventoryBody);

                    WixHelper::log('Import Products+Inventory', [
                        'step' => 'V1 Inventory PATCH response',
                        'response' => $invRes->body()
                    ], $invRes->ok() ? 'success' : 'error');

                    if ($invRes->ok()) {
                        $inventoryUpdated++;
                    } else {
                        $errors[] = "V1 inventory failed for SKU {$filteredProduct['sku']}: " . $invRes->body();
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
                            ])->patch("https://www.wixapis.com/stores/v1/products/{$newProductId}/variants", [
                                'variants' => $variantsPayload
                            ]);
                            WixHelper::log('Import Products+Inventory', [
                                'step' => 'PATCH variants',
                                'product_id' => $newProductId,
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
                            ])->post("https://www.wixapis.com/stores/v1/products/{$newProductId}/media", [
                                'media' => $mediaItems
                            ]);
                            WixHelper::log('Import Products+Inventory', [
                                'step' => 'POST product media',
                                'product_id' => $newProductId,
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
                                    ])->patch("https://www.wixapis.com/stores/v1/products/{$newProductId}/choices/media", $patchBody);

                                    WixHelper::log('Import Products+Inventory', [
                                        'step' => 'PATCH media to choices (productOptions)',
                                        'product_id' => $newProductId,
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
                                    $errors[] = "Could not find collection for slug '{$slug}'.";
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
                                'productIds' => [$newProductId]
                            ]);
                            if (!$addResp->ok()) {
                                $errors[] = "Failed to add product to collection '{$slug}': " . $addResp->body();
                            } else {
                                WixHelper::log('Import Products+Inventory', [
                                    'step' => 'Added product to collection',
                                    'product_id' => $newProductId,
                                    'collection_id' => $collectionId,
                                    'slug' => $slug,
                                    'response' => $addResp->body()
                                ], 'success');
                            }
                        }
                    }

                } else {
                    $errors[] = "V1 product creation failed: " . $response->body();
                }
            }
            else {
                $errors[] = "Unknown or unsupported catalog version: $catalogVersion";
            }
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



    // --- Utility: Get all products with paging
    public function getAllProducts($accessToken, $store)
    {
        $products = [];
        $body = ['query' => new \stdClass()];
        $hasMore = true;
        $cursor = null;
        $callCount = 0;
        while ($hasMore) {
            if ($cursor) $body['query']->paging = ['offset' => $cursor];
            $response = Http::withHeaders([
                'Authorization' => $accessToken,
                'Content-Type'  => 'application/json'
            ])->post('https://www.wixapis.com/stores/v1/products/query', $body);

            $result = $response->json();
            $products = array_merge($products, $result['products'] ?? []);
            $hasMore = isset($result['products']) && count($result['products']) === 100;
            if ($hasMore) {
                $cursor = count($products);
            }
            $callCount++;
            WixHelper::log('Export Products+Inventory', "Fetched batch #$callCount, products so far: ".count($products), 'info');
        }
        WixHelper::log('Export Products+Inventory', "Total products fetched for export: ".count($products), 'info');
        return ['products' => $products];
    }

    // --- Utility: Get all inventory items ---
    public function queryInventoryItems($accessToken, $query = [])
    {
        $body = ['query' => (object) $query];
        $response = Http::withHeaders([
            'Authorization' => $accessToken,
            'Content-Type'  => 'application/json'
        ])->post('https://www.wixapis.com/stores-reader/v2/inventoryItems/query', $body);

        return $response->json();
    }

    // Import Product function for automated migration
    public function importProductArray($accessToken, $product)
    {
        // Detect catalog version
        $catalogVersionResp = Http::withHeaders([
            'Authorization' => $accessToken,
            'Content-Type'  => 'application/json'
        ])->get('https://www.wixapis.com/stores/v3/provision/version');
        $catalogVersion = $catalogVersionResp->json('catalogVersion') ?? 'V1_CATALOG';

        try {
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
                            'variants' => [], // Add variants if you want
                        ],
                        'physicalProperties' => [],
                    ]
                ];
                $response = Http::withHeaders([
                    'Authorization' => $accessToken,
                    'Content-Type'  => 'application/json'
                ])->post('https://www.wixapis.com/stores/v3/products-with-inventory', $body);

                if ($response->ok() && isset($response->json()['product']['id'])) {
                    return ['success' => true, 'id' => $response->json()['product']['id']];
                } else {
                    return ['success' => false, 'msg' => $response->body()];
                }
            } else {
                $filteredProduct = $this->filterWixProductForImport($product);

                if (empty($filteredProduct['sku'])) {
                    $filteredProduct['sku'] = 'SKU-' . uniqid();
                }

                $response = Http::withHeaders([
                    'Authorization' => $accessToken,
                    'Content-Type'  => 'application/json'
                ])->post('https://www.wixapis.com/stores/v1/products', ["product" => $filteredProduct]);

                $json = $response->json();
                if ($response->ok() && isset($json['product']['id'])) {
                    return ['success' => true, 'id' => $json['product']['id']];
                } else {
                    return ['success' => false, 'msg' => $response->body()];
                }
            }
        } catch (\Throwable $e) {
            return ['success' => false, 'msg' => $e->getMessage()];
        }
    }

}
