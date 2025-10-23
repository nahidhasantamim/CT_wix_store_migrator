<?php

namespace App\Http\Controllers;

use App\Models\WixRibbonMigration;
use App\Models\WixStore;
use App\Helpers\WixHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;

class WixRibbonController extends Controller
{
    // =========================================================
    // Export Ribbons
    // =========================================================
    public function export(WixStore $store)
    {
        $userId = Auth::id() ?: 1;
        $fromStoreId = $store->instance_id;

        WixHelper::log('Export Ribbons', "Export started for store: {$store->store_name}", 'info');

        $accessToken = WixHelper::getAccessToken($fromStoreId);
        if (!$accessToken) {
            WixHelper::log('Export Ribbons', "Failed to get access token.", 'error');
            return response()->json(['error' => 'You are not authorized to access.'], 401);
        }

        // Check catalog version
        $catalogVersion = WixHelper::getCatalogVersion($accessToken);
        if (strtoupper($catalogVersion) !== 'V3') {
            WixHelper::log('Export Ribbons', "Catalog version is not V3", 'error');
            return response()->json(['error' => 'Not a Wix V3 Store'], 400);
        }

        $resp = $this->getRibbonsFromWix($accessToken);

        // API error handling
        if (!isset($resp['ribbons']) || !is_array($resp['ribbons'])) {
            WixHelper::log('Export Ribbons', "API error: " . json_encode($resp), 'error');
            return response()->json(['error' => 'Failed to fetch ribbons from Wix.'], 500);
        }

        $ribbons = $resp['ribbons'];

        foreach ($ribbons as $ribbon) {
            WixRibbonMigration::updateOrCreate(
                [
                    'user_id'           => $userId,
                    'from_store_id'     => $fromStoreId,
                    'to_store_id'       => null,
                    'source_ribbon_id'  => $ribbon['id'],
                ],
                [
                    'source_ribbon_name'      => $ribbon['name'] ?? null,
                    'destination_ribbon_id'   => null,
                    'status'                  => 'pending',
                    'error_message'           => null,
                ]
            );
        }

        WixHelper::log('Export Ribbons', "Exported and saved " . count($ribbons) . " ribbons.", 'success');

        return response()->streamDownload(function () use ($ribbons, $fromStoreId) {
            echo json_encode([
                'from_store_id' => $fromStoreId,
                'ribbons'       => $ribbons
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }, 'ribbons.json', [
            'Content-Type' => 'application/json',
        ]);
    }


    // =========================================================
    // Import Ribbons
    // =========================================================
    // public function import(Request $request, WixStore $store)
    // {
    //     $userId = Auth::id() ?: 1;
    //     $toStoreId = $store->instance_id;

    //     WixHelper::log('Import Ribbons', "Import started for store: {$store->store_name}", 'info');

    //     $accessToken = WixHelper::getAccessToken($toStoreId);
    //     if (!$accessToken) {
    //         WixHelper::log('Import Ribbons', "Failed to get access token.", 'error');
    //         return back()->with('error', 'You are not authoraized to access.');
    //     }

    //     // Check catalog version
    //     $catalogVersion = WixHelper::getCatalogVersion($accessToken);
    //     if ($catalogVersion !== 'V3') {
    //         WixHelper::log('Import Ribbons', "Catalog version is not V3", 'error');
    //         return back()->with('error', 'Not a Wix V3 Store');
    //     }

    //     if (!$request->hasFile('ribbons_json')) {
    //         WixHelper::log('Import Ribbons', "No file uploaded.", 'error');
    //         return back()->with('error', 'No file uploaded.');
    //     }

    //     $file = $request->file('ribbons_json');
    //     $json = file_get_contents($file->getRealPath());
    //     $decoded = json_decode($json, true);

    //     if (!isset($decoded['from_store_id'], $decoded['ribbons']) || !is_array($decoded['ribbons'])) {
    //         WixHelper::log('Import Ribbons', "Invalid JSON format.", 'error');
    //         return back()->with('error', 'Invalid JSON. Required keys: from_store_id and ribbons.');
    //     }

    //     $fromStoreId = $decoded['from_store_id'];
    //     $ribbons = $decoded['ribbons'];

    //     $imported = 0;
    //     $errors = [];

    //     foreach ($ribbons as $ribbon) {
    //         $sourceId = $ribbon['id'] ?? null;
    //         if (!$sourceId) continue;

    //         // Check existing migration record
    //         $migration = WixRibbonMigration::where([
    //             'user_id' => $userId,
    //             'from_store_id' => $fromStoreId,
    //             'source_ribbon_id' => $sourceId,
    //         ])->first();

    //         // Skip if already imported
    //         if ($migration && $migration->status === 'success') continue;

    //         $ribbonName = $ribbon['name'] ?? null;

    //         unset($ribbon['id']); // Remove system field
    //         unset($ribbon['revision']);
    //         unset($ribbon['createdDate']);
    //         unset($ribbon['updatedDate']);
    //         // You can unset more fields if any, as per response structure.

    //         $response = Http::withHeaders([
    //             'Authorization' => $accessToken,
    //             'Content-Type' => 'application/json'
    //         ])->post('https://www.wixapis.com/stores/v3/ribbons', [
    //             'ribbon' => $ribbon
    //         ]);

    //         if ($response->ok() && isset($response['ribbon']['id'])) {
    //             $createdId = $response['ribbon']['id'];

    //             WixRibbonMigration::updateOrCreate([
    //                 'user_id' => $userId,
    //                 'from_store_id' => $fromStoreId,
    //                 'source_ribbon_id' => $sourceId,
    //             ], [
    //                 'to_store_id' => $toStoreId,
    //                 'source_ribbon_name' => $ribbonName,
    //                 'destination_ribbon_id' => $createdId,
    //                 'status' => 'success',
    //                 'error_message' => null,
    //             ]);

    //             WixHelper::log('Import Ribbons', "Imported ribbon '{$ribbonName}' (new ID: {$createdId})", 'success');
    //             $imported++;
    //         } else {
    //             $errorMsg = json_encode(['sent' => $ribbon, 'response' => $response->json()]);
    //             $errors[] = $errorMsg;

    //             WixRibbonMigration::updateOrCreate([
    //                 'user_id' => $userId,
    //                 'from_store_id' => $fromStoreId,
    //                 'source_ribbon_id' => $sourceId,
    //             ], [
    //                 'to_store_id' => $toStoreId,
    //                 'source_ribbon_name' => $ribbonName,
    //                 'destination_ribbon_id' => null,
    //                 'status' => 'failed',
    //                 'error_message' => $errorMsg,
    //             ]);

    //             WixHelper::log('Import Ribbons', "Failed to import '{$ribbonName}' " . json_encode($response->json()), 'error');
    //         }
    //     }

    //     if ($imported > 0) {
    //         WixHelper::log('Import Ribbons', "Import finished: $imported ribbon(s) imported." . (count($errors) ? " Some errors: " . implode("; ", $errors) : ""), 'success');
    //         return back()->with('success', "$imported ribbon(s) imported." . (count($errors) ? " Some errors occurred." : ""));
    //     } else {
    //         WixHelper::log('Import Ribbons', "No ribbons imported. Errors: " . implode("; ", $errors), 'error');
    //         return back()->with('error', 'No ribbons imported.' . (count($errors) ? " Errors: " . implode("; ", $errors) : ''));
    //     }
    // }

    public function import(Request $request, WixStore $store)
    {
        $userId    = Auth::id() ?: 1;
        $toStoreId = $store->instance_id;

        WixHelper::log('Import Ribbons', "Import started for store: {$store->store_name}", 'info');

        $accessToken = WixHelper::getAccessToken($toStoreId);
        if (!$accessToken) {
            WixHelper::log('Import Ribbons', "Failed to get access token.", 'error');
            return back()->with('error', 'You are not authoraized to access.');
        }

        // Check catalog version
        $catalogVersion = WixHelper::getCatalogVersion($accessToken);
        if ($catalogVersion !== 'V3') {
            WixHelper::log('Import Ribbons', "Catalog version is not V3", 'error');
            return back()->with('error', 'Not a Wix V3 Store');
        }

        if (!$request->hasFile('ribbons_json')) {
            WixHelper::log('Import Ribbons', "No file uploaded.", 'error');
            return back()->with('error', 'No file uploaded.');
        }

        $file    = $request->file('ribbons_json');
        $json    = file_get_contents($file->getRealPath());
        $decoded = json_decode($json, true);

        if (!isset($decoded['from_store_id'], $decoded['ribbons']) || !is_array($decoded['ribbons'])) {
            WixHelper::log('Import Ribbons', "Invalid JSON format.", 'error');
            return back()->with('error', 'Invalid JSON. Required keys: from_store_id and ribbons.');
        }

        $fromStoreId = $decoded['from_store_id'];
        $ribbons     = $decoded['ribbons'];

        $imported = 0;
        $errors   = [];

        foreach ($ribbons as $ribbon) {
            $sourceId = $ribbon['id'] ?? null;
            if (!$sourceId) continue;

            // Check existing migration record
            $migration = WixRibbonMigration::where([
                'user_id' => $userId,
                'from_store_id' => $fromStoreId,
                'source_ribbon_id' => $sourceId,
            ])->first();

            // Skip if already imported
            if ($migration && $migration->status === 'success') continue;

            $ribbonName = $ribbon['name'] ?? null;

            // Clean system fields before sending
            unset($ribbon['id'], $ribbon['revision'], $ribbon['createdDate'], $ribbon['updatedDate']);

            $result = $this->createRibbonInWix($accessToken, $ribbon);

            if (isset($result['ribbon']['id'])) {
                $createdId = $result['ribbon']['id'];

                WixRibbonMigration::updateOrCreate([
                    'user_id' => $userId,
                    'from_store_id' => $fromStoreId,
                    'source_ribbon_id' => $sourceId,
                ], [
                    'to_store_id' => $toStoreId,
                    'source_ribbon_name' => $ribbonName,
                    'destination_ribbon_id' => $createdId,
                    'status' => 'success',
                    'error_message' => null,
                ]);

                WixHelper::log('Import Ribbons', "Imported ribbon '{$ribbonName}' (new ID: {$createdId})", 'success');
                $imported++;
            } else {
                $errorMsg = json_encode(['sent' => $ribbon, 'response' => $result]);
                $errors[] = $errorMsg;

                WixRibbonMigration::updateOrCreate([
                    'user_id' => $userId,
                    'from_store_id' => $fromStoreId,
                    'source_ribbon_id' => $sourceId,
                ], [
                    'to_store_id' => $toStoreId,
                    'source_ribbon_name' => $ribbonName,
                    'destination_ribbon_id' => null,
                    'status' => 'failed',
                    'error_message' => $errorMsg,
                ]);

                WixHelper::log('Import Ribbons', "Failed to import '{$ribbonName}' " . $errorMsg, 'error');
            }
        }

        if ($imported > 0) {
            WixHelper::log('Import Ribbons', "Import finished: $imported ribbon(s) imported." . (count($errors) ? " Some errors: " . implode("; ", $errors) : ""), 'success');
            return back()->with('success', "$imported ribbon(s) imported." . (count($errors) ? " Some errors occurred." : ""));
        } else {
            WixHelper::log('Import Ribbons', "No ribbons imported. Errors: " . implode("; ", $errors), 'error');
            return back()->with('error', 'No ribbons imported.' . (count($errors) ? " Errors: " . implode("; ", $errors) : ''));
        }
    }

    // =========================================================
    // Utilities
    // =========================================================
    public function getRibbonsFromWix($accessToken)
    {
        $query = [
            'query' => [
                'sort' => [['fieldName' => 'createdDate', 'order' => 'DESC']],
                'cursorPaging' => ['limit' => 100],
            ],
            'fields' => ['ASSIGNED_PRODUCTS_COUNT'],
        ];

        $response = Http::withHeaders([
            'Authorization' => $accessToken,
            'Content-Type'  => 'application/json'
        ])->post('https://www.wixapis.com/stores/v3/ribbons/query', $query);

        WixHelper::log('Export Ribbons', 'Wix API raw response: ' . $response->body(), 'debug');

        return $response->json();
    }

    private function createRibbonInWix($accessToken, $ribbon)
    {
        $response = Http::withHeaders([
            'Authorization' => $accessToken,
            'Content-Type'  => 'application/json'
        ])->post('https://www.wixapis.com/stores/v3/ribbons', [
            'ribbon' => $ribbon
        ]);

        return $response->json();
    }

}
