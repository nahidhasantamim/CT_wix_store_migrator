<?php

namespace App\Http\Controllers;

use App\Models\WixBrandMigration;
use App\Models\WixStore;
use App\Helpers\WixHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;

class WixBrandController extends Controller
{
    // =========================================================
    // Export Brands
    // =========================================================
    public function export(WixStore $store)
    {
        $userId = Auth::id() ?: 1;
        $fromStoreId = $store->instance_id;

        WixHelper::log('Export Brands', "Export started for store: {$store->store_name}", 'info');

        $accessToken = WixHelper::getAccessToken($fromStoreId);

        if (!$accessToken) {
            WixHelper::log('Export Brands', "Failed: Could not get access token for instanceId: $fromStoreId", 'error');
            return response()->json(['error' => 'You are not authorized to access.'], 401);
        }

        // Check Wix catalog version
        $catalogVersion = WixHelper::getCatalogVersion($accessToken);
        if ($catalogVersion !== 'v3') {
            WixHelper::log('Export Brands', "Catalog version not supported: {$catalogVersion}", 'error');
            return response()->json(['error' => "This store is not compatible with Brands API v3. Found: {$catalogVersion}"], 400);
        }

        $body = $this->getBrandsFromWix($accessToken);

        if (!isset($body['brands'])) {
            WixHelper::log('Export Brands', "API error: " . json_encode($body), 'error');
            return response()->json(['error' => 'Failed to fetch brands from Wix.'], 500);
        }

        $brands = $body['brands'] ?? [];
        foreach ($brands as $brand) {
            WixBrandMigration::firstOrCreate(
                [
                    'user_id'        => $userId,
                    'from_store_id'  => $fromStoreId,
                    'to_store_id'    => null,
                    'source_brand_id'=> $brand['id'],
                ],
                [
                    'source_brand_name'      => $brand['name'] ?? null,
                    'destination_brand_id'   => null,
                    'status'                 => 'pending',
                    'error_message'          => null,
                ]
            );
        }

        WixHelper::log('Export Brands', "Exported and saved " . count($brands) . " brands.", 'success');

        return response()->streamDownload(function () use ($brands, $fromStoreId) {
            echo json_encode([
                'from_store_id' => $fromStoreId,
                'brands'        => $brands
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }, 'brands.json', [
            'Content-Type' => 'application/json',
        ]);
    }


    // =========================================================
    // Import Brands
    // =========================================================
    public function import(Request $request, WixStore $store)
    {
        $userId = Auth::id() ?: 1;
        $toStoreId = $store->instance_id;

        WixHelper::log('Import Brands', "Import started for store: {$store->store_name}", 'info');

        $accessToken = WixHelper::getAccessToken($toStoreId);
        if (!$accessToken) {
            WixHelper::log('Import Brands', "Failed to get access token.", 'error');
            return back()->with('error', 'Could not get Wix access token.');
        }

        // Check Wix catalog version
        $catalogVersion = WixHelper::getCatalogVersion($accessToken);
        if ($catalogVersion !== 'v3') {
            WixHelper::log('Import Brands', "Catalog version not supported: {$catalogVersion}", 'error');
            return back()->with('error', "This store is not compatible with Brands API v3. Found: {$catalogVersion}");
        }

        if (!$request->hasFile('brands_json')) {
            WixHelper::log('Import Brands', "No file uploaded for store: {$store->store_name}", 'error');
            return back()->with('error', 'No file uploaded.');
        }

        $file = $request->file('brands_json');
        $json = file_get_contents($file->getRealPath());
        $decoded = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE || !isset($decoded['from_store_id'], $decoded['brands']) || !is_array($decoded['brands'])) {
            WixHelper::log('Import Brands', "Invalid JSON structure in uploaded file.", 'error');
            return back()->with('error', 'Invalid JSON structure. Required keys: from_store_id and brands.');
        }

        $fromStoreId = $decoded['from_store_id'];
        $brands = $decoded['brands'];

        $imported = 0;
        $errors = [];

        foreach ($brands as $brand) {
            $sourceId = $brand['id'] ?? null;
            if (!$sourceId) continue;

            // Check existing migration record
            $migration = WixBrandMigration::where([
                'user_id' => $userId,
                'from_store_id' => $fromStoreId,
                'source_brand_id' => $sourceId,
            ])->first();

            // Skip if already imported successfully
            if ($migration && $migration->status === 'success') continue;

            $brandName = $brand['name'] ?? null;
            unset($brand['id']); // Remove system field

            $result = $this->createBrandInWix($accessToken, ['name' => $brandName]);

            if (isset($result['brand']['id'])) {
                $createdId = $result['brand']['id'];

                WixBrandMigration::updateOrCreate([
                    'user_id' => $userId,
                    'from_store_id' => $fromStoreId,
                    'source_brand_id' => $sourceId,
                ], [
                    'to_store_id' => $toStoreId,
                    'source_brand_name' => $brandName,
                    'destination_brand_id' => $createdId,
                    'status' => 'success',
                    'error_message' => null,
                ]);

                WixHelper::log('Import Brands', "Imported brand '{$brandName}' (new ID: {$createdId})", 'success');
                $imported++;
            } else {
                $errorMsg = json_encode(['sent' => $brand, 'response' => $result]);
                $errors[] = $errorMsg;

                WixBrandMigration::updateOrCreate([
                    'user_id' => $userId,
                    'from_store_id' => $fromStoreId,
                    'source_brand_id' => $sourceId,
                ], [
                    'to_store_id' => $toStoreId,
                    'source_brand_name' => $brandName,
                    'destination_brand_id' => null,
                    'status' => 'failed',
                    'error_message' => $errorMsg,
                ]);

                WixHelper::log('Import Brands', "Failed to import '{$brandName}': " . $errorMsg, 'error');
            }
        }

        if ($imported > 0) {
            WixHelper::log('Import Brands', "Import finished: $imported brand(s) imported." . (count($errors) ? " Some errors: " . implode("; ", $errors) : ""), 'success');
            return back()->with('success', "$imported brand(s) imported." . (count($errors) ? " Some errors occurred." : ""));
        } else {
            WixHelper::log('Import Brands', "No brands imported. Errors: " . implode("; ", $errors), 'error');
            return back()->with('error', 'No brands imported.' . (count($errors) ? " Errors: " . implode("; ", $errors) : ''));
        }
    }


    // =========================================================
    // Utilities
    // =========================================================
    public function getBrandsFromWix($accessToken)
    {
        $query = [
            'query' => [
                'sort' => [['fieldName' => 'createdDate', 'order' => 'DESC']],
                'cursorPaging' => ['limit' => 100]
            ],
            'fields' => ['ASSIGNED_PRODUCTS_COUNT']
        ];

        $response = Http::withHeaders([
            'Authorization' => $accessToken,
            'Content-Type'  => 'application/json'
        ])->post('https://www.wixapis.com/stores/v3/brands/query', $query);

        // Optional: log raw API response for deep debugging
        WixHelper::log('Export Brands', 'Wix API raw response: ' . $response->body(), 'debug');

        return $response->json();
    }

    public function createBrandInWix($accessToken, $brand)
    {
        $body = ['brand' => $brand];
        $response = Http::withHeaders([
            'Authorization' => $accessToken,
            'Content-Type'  => 'application/json'
        ])->post('https://www.wixapis.com/stores/v3/brands', $body);

        return $response->json();
    }

}
