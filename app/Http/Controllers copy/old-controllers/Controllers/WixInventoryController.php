<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use App\Models\WixStore;
use App\Helpers\WixHelper;

class WixInventoryController extends Controller
{
    // Export all inventory
    public function export(WixStore $store)
    {
        WixHelper::log('Export Inventory', "Export started for store: $store->store_name", 'info');

        $accessToken = WixHelper::getAccessToken($store->instance_id);
        if (!$accessToken) {
            WixHelper::log('Export Inventory', "Failed: Could not get access token.", 'error');
            return response()->json(['error' => 'Could not get Wix access token.'], 401);
        }
        $items = $this->queryInventoryItems($accessToken);

        $count = count($items['inventoryItems'] ?? []);
        WixHelper::log('Export Inventory', "Exported $count inventory items.", 'success');

        return response()->streamDownload(function() use ($items) {
            echo json_encode($items['inventoryItems'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }, 'inventory.json', [
            'Content-Type' => 'application/json'
        ]);
    }

    // Import inventory
    public function import(Request $request, WixStore $store)
    {
        WixHelper::log('Import Inventory', "Import started for store: $store->store_name", 'info');

        $accessToken = WixHelper::getAccessToken($store->instance_id);
        if (!$accessToken) {
            WixHelper::log('Import Inventory', "Failed: Could not get access token.", 'error');
            return back()->with('error', 'Could not get Wix access token.');
        }

        if (!$request->hasFile('inventory_json')) {
            WixHelper::log('Import Inventory', "No file uploaded.", 'error');
            return back()->with('error', 'No file uploaded.');
        }

        $file = $request->file('inventory_json');
        $json = file_get_contents($file->getRealPath());
        $inventoryList = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($inventoryList)) {
            WixHelper::log('Import Inventory', "Uploaded file is not valid JSON.", 'error');
            return back()->with('error', 'Uploaded file is not valid JSON.');
        }

        // === STEP 1: Build SKU → [productId, variantId|null] map from target store ===
        $productsResponse = $this->getAllProducts($accessToken, $store);
        $skuMap = []; // sku => [ 'productId' => ..., 'variantId' => ... (optional) ]
        foreach ($productsResponse['products'] ?? [] as $product) {
            if (!empty($product['sku'])) {
                $skuMap[$product['sku']] = ['productId' => $product['id'], 'variantId' => null];
            }
            // Map variants
            if (!empty($product['variants']) && is_array($product['variants'])) {
                foreach ($product['variants'] as $variant) {
                    if (!empty($variant['sku']) && !empty($variant['id'])) {
                        $skuMap[$variant['sku']] = [
                            'productId' => $product['id'],
                            'variantId' => $variant['id'],
                        ];
                    }
                }
            }
        }
        WixHelper::log('Import Inventory', 'Built SKU map for '.count($skuMap).' products/variants.', 'info');

        $updated = 0;
        $errors = [];

        // === STEP 2: Import inventory ===
        foreach ($inventoryList as $item) {
            // Try product SKU first
            $didAny = false;
            if (!empty($item['sku']) && isset($skuMap[$item['sku']])) {
                // Main product inventory update
                $map = $skuMap[$item['sku']];
                $body = [
                    'inventoryItem' => [
                        'trackQuantity' => $item['trackQuantity'] ?? false,
                        'quantity' => $item['quantity'] ?? null,
                        'inStock' => $item['inStock'] ?? null,
                    ]
                ];
                // Remove nulls
                $body['inventoryItem'] = array_filter($body['inventoryItem'], fn($v) => !is_null($v));

                $url = "https://www.wixapis.com/stores/v2/inventoryItems/product/" . $map['productId'];
                $response = Http::withHeaders([
                    'Authorization' => $accessToken,
                    'Content-Type'  => 'application/json'
                ])->patch($url, $body);

                if ($response->ok()) {
                    $updated++;
                    WixHelper::log('Import Inventory', "Updated inventory for product SKU {$item['sku']} (productId: {$map['productId']})", 'success');
                    $didAny = true;
                } else {
                    $errMsg = $response->json() ?: $response->body();
                    $errors[] = "Failed product SKU {$item['sku']} (productId: {$map['productId']}): " . json_encode($errMsg);
                    WixHelper::log('Import Inventory', "Failed product SKU {$item['sku']} (productId: {$map['productId']}) - " . json_encode($errMsg), 'error');
                }
            }

            // Now variants
            if (!empty($item['variants']) && is_array($item['variants'])) {
                foreach ($item['variants'] as $variant) {
                    $vSku = $variant['sku'] ?? null;
                    if ($vSku && isset($skuMap[$vSku])) {
                        $map = $skuMap[$vSku];
                        $body = [
                            'inventoryItem' => [
                                'variants' => [[
                                    'variantId' => $map['variantId'],
                                    'quantity' => $variant['quantity'] ?? null,
                                    'inStock' => $variant['inStock'] ?? null,
                                ]]
                            ]
                        ];
                        // Remove nulls in variants
                        $body['inventoryItem']['variants'][0] = array_filter($body['inventoryItem']['variants'][0], fn($v) => !is_null($v));
                        $url = "https://www.wixapis.com/stores/v2/inventoryItems/product/" . $map['productId'];
                        $response = Http::withHeaders([
                            'Authorization' => $accessToken,
                            'Content-Type'  => 'application/json'
                        ])->patch($url, $body);

                        if ($response->ok()) {
                            $updated++;
                            WixHelper::log('Import Inventory', "Updated inventory for variant SKU $vSku (productId: {$map['productId']}, variantId: {$map['variantId']})", 'success');
                            $didAny = true;
                        } else {
                            $errMsg = $response->json() ?: $response->body();
                            $errors[] = "Failed variant SKU $vSku (productId: {$map['productId']}, variantId: {$map['variantId']}): " . json_encode($errMsg);
                            WixHelper::log('Import Inventory', "Failed variant SKU $vSku (productId: {$map['productId']}, variantId: {$map['variantId']}) - " . json_encode($errMsg), 'error');
                        }
                    } else {
                        $msg = "No SKU found for variant (productId: {$item['productId']}, variantSku: {$vSku}). Skipping.";
                        $errors[] = $msg;
                        WixHelper::log('Import Inventory', $msg, 'error');
                    }
                }
            }

            // If neither product nor variants updated, log main error
            if (!$didAny) {
                $msg = "No SKU found for inventory item (productId: {$item['productId']}). Skipping.";
                if (!empty($item['sku'])) $msg .= " (sku: {$item['sku']})";
                $errors[] = $msg;
                WixHelper::log('Import Inventory', $msg, 'error');
            }
        }

        if ($updated > 0) {
            WixHelper::log('Import Inventory', "$updated inventory item(s) updated. Errors: " . (count($errors) ? implode("; ", $errors) : 'None'), 'success');
            return back()->with('success', "$updated inventory item(s) updated.");
        } else {
            WixHelper::log('Import Inventory', "No inventory updated. Errors: " . implode("; ", $errors), 'error');
            return back()->with('error', 'No inventory updated. Errors: ' . implode("; ", $errors));
        }
    }


    // --- Utility: Get ALL products from Wix (with logs) ---
    protected function getAllProducts($accessToken, $store)
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
            WixHelper::log('Import Inventory', "Fetched batch #$callCount, products so far: ".count($products), 'info');
        }
        WixHelper::log('Import Inventory', "Total products fetched for SKU mapping: ".count($products), 'info');
        return ['products' => $products];
    }

    // ---- UTILITIES ----

    protected function queryInventoryItems($accessToken, $query = [])
    {
        $body = ['query' => (object) $query];
        $response = Http::withHeaders([
            'Authorization' => $accessToken,
            'Content-Type'  => 'application/json'
        ])->post('https://www.wixapis.com/stores-reader/v2/inventoryItems/query', $body);

        return $response->json();
    }
}
