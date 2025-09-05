<?php

namespace App\Http\Controllers;

use App\Models\WixCustomizationMigration;
use App\Models\WixStore;
use App\Helpers\WixHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;

class WixCustomizationController extends Controller
{
    // =========================================================
    // Export Customizations
    // =========================================================
    public function export(WixStore $store)
    {
        $userId = Auth::id() ?: 1;
        $fromStoreId = $store->instance_id;

        WixHelper::log('Export Customizations', "Export started for store: {$store->store_name}", 'info');

        $accessToken = WixHelper::getAccessToken($fromStoreId);
        if (!$accessToken) {
            WixHelper::log('Export Customizations', "Failed to get access token.", 'error');
            return response()->json(['error' => 'You are not authorized to access.'], 401);
        }

        // Check catalog version
        $catalogVersion = WixHelper::getCatalogVersion($accessToken);
        if (strtoupper($catalogVersion) !== 'V3') {
            WixHelper::log('Export Customizations', "Catalog version is not V3", 'error');
            return response()->json(['error' => 'Not a Wix V3 Store'], 400);
        }

        // Use extracted API logic
        $resp = $this->getCustomizationsFromWix($accessToken);

        // API error handling (pattern matching others)
        if (!isset($resp['customizations']) || !is_array($resp['customizations'])) {
            WixHelper::log('Export Customizations', "API error: " . json_encode($resp), 'error');
            return response()->json(['error' => 'Failed to fetch customizations from Wix.'], 500);
        }

        $customizations = $resp['customizations'];

        foreach ($customizations as $customization) {
            WixCustomizationMigration::updateOrCreate(
                [
                    'user_id'                 => $userId,
                    'from_store_id'           => $fromStoreId,
                    'to_store_id'             => null,
                    'source_customization_id' => $customization['id'],
                ],
                [
                    'source_customization_name'    => $customization['name'] ?? null,
                    'destination_customization_id' => null,
                    'status'                      => 'pending',
                    'error_message'               => null,
                ]
            );
        }

        WixHelper::log('Export Customizations', "Exported and saved " . count($customizations) . " customizations.", 'success');

        return response()->streamDownload(function () use ($customizations, $fromStoreId) {
            echo json_encode([
                'from_store_id'    => $fromStoreId,
                'customizations'   => $customizations
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }, 'customizations.json', [
            'Content-Type' => 'application/json',
        ]);
    }

    // =========================================================
    // Import Customizations
    // =========================================================
    // public function import(Request $request, WixStore $store)
    // {
    //     $userId = Auth::id() ?: 1;
    //     $toStoreId = $store->instance_id;

    //     WixHelper::log('Import Customizations', "Import started for store: {$store->store_name}", 'info');

    //     $accessToken = WixHelper::getAccessToken($toStoreId);
    //     if (!$accessToken) {
    //         WixHelper::log('Import Customizations', "Failed to get access token.", 'error');
    //         return back()->with('error', 'You are not authoraized to access.');
    //     }

    //     // Check catalog version
    //     $catalogVersion = WixHelper::getCatalogVersion($accessToken);
    //     if ($catalogVersion !== 'V3') {
    //         WixHelper::log('Import Customizations', "Catalog version is not V3", 'error');
    //         return back()->with('error', 'Not a Wix V3 Store');
    //     }

    //     if (!$request->hasFile('customizations_json')) {
    //         WixHelper::log('Import Customizations', "No file uploaded.", 'error');
    //         return back()->with('error', 'No file uploaded.');
    //     }

    //     $file = $request->file('customizations_json');
    //     $json = file_get_contents($file->getRealPath());
    //     $decoded = json_decode($json, true);

    //     if (!isset($decoded['from_store_id'], $decoded['customizations']) || !is_array($decoded['customizations'])) {
    //         WixHelper::log('Import Customizations', "Invalid JSON format.", 'error');
    //         return back()->with('error', 'Invalid JSON. Required keys: from_store_id and customizations.');
    //     }

    //     $fromStoreId = $decoded['from_store_id'];
    //     $customizations = $decoded['customizations'];

    //     $imported = 0;
    //     $errors = [];

    //     foreach ($customizations as $customization) {
    //         $sourceId = $customization['id'] ?? null;
    //         if (!$sourceId) continue;

    //         // Check existing migration record
    //         $migration = WixCustomizationMigration::where([
    //             'user_id' => $userId,
    //             'from_store_id' => $fromStoreId,
    //             'source_customization_id' => $sourceId,
    //         ])->first();

    //         // Skip if already imported
    //         if ($migration && $migration->status === 'success') continue;

    //         $customizationName = $customization['name'] ?? null;

    //         // Only keep allowed fields for creating new customization
    //         $postCustomization = [
    //             'name'                   => $customization['name'] ?? '',
    //             'customizationType'      => $customization['customizationType'] ?? '',
    //             'customizationRenderType'=> $customization['customizationRenderType'] ?? '',
    //         ];

    //         // Only pass choicesSettings or freeTextInput as required by renderType
    //         if (isset($customization['choicesSettings']) && in_array($customization['customizationRenderType'], ['SWATCH_CHOICES','TEXT_CHOICES'])) {
    //             $postCustomization['choicesSettings'] = $customization['choicesSettings'];
    //         }
    //         if (isset($customization['freeTextInput']) && $customization['customizationRenderType'] === 'FREE_TEXT') {
    //             $postCustomization['freeTextInput'] = $customization['freeTextInput'];
    //         }

    //         $response = Http::withHeaders([
    //             'Authorization' => $accessToken,
    //             'Content-Type' => 'application/json'
    //         ])->post('https://www.wixapis.com/stores/v3/customizations', [
    //             'customization' => $postCustomization
    //         ]);

    //         if ($response->ok() && isset($response['customization']['id'])) {
    //             $createdId = $response['customization']['id'];

    //             WixCustomizationMigration::updateOrCreate([
    //                 'user_id' => $userId,
    //                 'from_store_id' => $fromStoreId,
    //                 'source_customization_id' => $sourceId,
    //             ], [
    //                 'to_store_id' => $toStoreId,
    //                 'source_customization_name' => $customizationName,
    //                 'destination_customization_id' => $createdId,
    //                 'status' => 'success',
    //                 'error_message' => null,
    //             ]);

    //             WixHelper::log('Import Customizations', "Imported customization '{$customizationName}' (new ID: {$createdId})", 'success');
    //             $imported++;
    //         } else {
    //             $errorMsg = json_encode(['sent' => $postCustomization, 'response' => $response->json()]);
    //             $errors[] = $errorMsg;

    //             WixCustomizationMigration::updateOrCreate([
    //                 'user_id' => $userId,
    //                 'from_store_id' => $fromStoreId,
    //                 'source_customization_id' => $sourceId,
    //             ], [
    //                 'to_store_id' => $toStoreId,
    //                 'source_customization_name' => $customizationName,
    //                 'destination_customization_id' => null,
    //                 'status' => 'failed',
    //                 'error_message' => $errorMsg,
    //             ]);

    //             WixHelper::log('Import Customizations', "Failed to import '{$customizationName}' " . json_encode($response->json()), 'error');
    //         }
    //     }

    //     if ($imported > 0) {
    //         WixHelper::log('Import Customizations', "Import finished: $imported customization(s) imported." . (count($errors) ? " Some errors: " . implode("; ", $errors) : ""), 'success');
    //         return back()->with('success', "$imported customization(s) imported." . (count($errors) ? " Some errors occurred." : ""));
    //     } else {
    //         WixHelper::log('Import Customizations', "No customizations imported. Errors: " . implode("; ", $errors), 'error');
    //         return back()->with('error', 'No customizations imported.' . (count($errors) ? " Errors: " . implode("; ", $errors) : ''));
    //     }
    // }

    public function import(Request $request, WixStore $store)
    {
        $userId    = Auth::id() ?: 1;
        $toStoreId = $store->instance_id;

        WixHelper::log('Import Customizations', "Import started for store: {$store->store_name}", 'info');

        $accessToken = WixHelper::getAccessToken($toStoreId);
        if (!$accessToken) {
            WixHelper::log('Import Customizations', "Failed to get access token.", 'error');
            return back()->with('error', 'You are not authoraized to access.');
        }

        // Check catalog version
        $catalogVersion = WixHelper::getCatalogVersion($accessToken);
        if ($catalogVersion !== 'V3') {
            WixHelper::log('Import Customizations', "Catalog version is not V3", 'error');
            return back()->with('error', 'Not a Wix V3 Store');
        }

        if (!$request->hasFile('customizations_json')) {
            WixHelper::log('Import Customizations', "No file uploaded.", 'error');
            return back()->with('error', 'No file uploaded.');
        }

        $file = $request->file('customizations_json');
        $json = file_get_contents($file->getRealPath());
        $decoded = json_decode($json, true);

        if (!isset($decoded['from_store_id'], $decoded['customizations']) || !is_array($decoded['customizations'])) {
            WixHelper::log('Import Customizations', "Invalid JSON format.", 'error');
            return back()->with('error', 'Invalid JSON. Required keys: from_store_id and customizations.');
        }

        $fromStoreId    = $decoded['from_store_id'];
        $customizations = $decoded['customizations'];
        $imported       = 0;
        $errors         = [];

        foreach ($customizations as $customization) {
            $sourceId = $customization['id'] ?? null;
            if (!$sourceId) continue;

            // Check existing migration record
            $migration = WixCustomizationMigration::where([
                'user_id' => $userId,
                'from_store_id' => $fromStoreId,
                'source_customization_id' => $sourceId,
            ])->first();

            // Skip if already imported
            if ($migration && $migration->status === 'success') continue;

            $customizationName = $customization['name'] ?? '';

            // Prepare fields for creation
            $postCustomization = [
                'name'                   => $customization['name'] ?? '',
                'customizationType'      => $customization['customizationType'] ?? '',
                'customizationRenderType'=> $customization['customizationRenderType'] ?? '',
            ];

            // Only pass choicesSettings or freeTextInput as required by renderType
            if (isset($customization['choicesSettings']) && in_array($customization['customizationRenderType'], ['SWATCH_CHOICES','TEXT_CHOICES'])) {
                $postCustomization['choicesSettings'] = $customization['choicesSettings'];
            }
            if (isset($customization['freeTextInput']) && $customization['customizationRenderType'] === 'FREE_TEXT') {
                $postCustomization['freeTextInput'] = $customization['freeTextInput'];
            }

            $result = $this->createCustomizationInWix($accessToken, $postCustomization);

            if (isset($result['customization']['id'])) {
                $createdId = $result['customization']['id'];

                WixCustomizationMigration::updateOrCreate([
                    'user_id' => $userId,
                    'from_store_id' => $fromStoreId,
                    'source_customization_id' => $sourceId,
                ], [
                    'to_store_id' => $toStoreId,
                    'source_customization_name' => $customizationName,
                    'destination_customization_id' => $createdId,
                    'status' => 'success',
                    'error_message' => null,
                ]);

                WixHelper::log('Import Customizations', "Imported customization '{$customizationName}' (new ID: {$createdId})", 'success');
                $imported++;
            } else {
                $errorMsg = json_encode(['sent' => $postCustomization, 'response' => $result]);
                $errors[] = $errorMsg;

                WixCustomizationMigration::updateOrCreate([
                    'user_id' => $userId,
                    'from_store_id' => $fromStoreId,
                    'source_customization_id' => $sourceId,
                ], [
                    'to_store_id' => $toStoreId,
                    'source_customization_name' => $customizationName,
                    'destination_customization_id' => null,
                    'status' => 'failed',
                    'error_message' => $errorMsg,
                ]);

                WixHelper::log('Import Customizations', "Failed to import '{$customizationName}': $errorMsg", 'error');
            }
        }

        if ($imported > 0) {
            WixHelper::log('Import Customizations', "Import finished: $imported customization(s) imported." . (count($errors) ? " Some errors: " . implode("; ", $errors) : ""), count($errors) ? 'warning' : 'success');
            return back()->with('success', "$imported customization(s) imported." . (count($errors) ? " Some errors: " . implode("; ", $errors) : ""));
        } else {
            WixHelper::log('Import Customizations', "No customizations imported. Errors: " . implode("; ", $errors), 'error');
            return back()->with('error', 'No customizations imported.' . (count($errors) ? " Errors: " . implode("; ", $errors) : ''));
        }
    }


    // =========================================================
    // Utilities
    // =========================================================
    public function getCustomizationsFromWix($accessToken)
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
        ])->post('https://www.wixapis.com/stores/v3/customizations/query', $query);

        WixHelper::log('Export Customizations', 'Wix API raw response: ' . $response->body(), 'debug');

        return $response->json();
    }

    private function createCustomizationInWix($accessToken, $postCustomization)
    {
        $body = ['customization' => $postCustomization];
        $response = Http::withHeaders([
            'Authorization' => $accessToken,
            'Content-Type' => 'application/json'
        ])->post('https://www.wixapis.com/stores/v3/customizations', $body);

        return $response->json();
    }

}
