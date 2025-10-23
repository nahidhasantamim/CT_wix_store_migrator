<?php

namespace App\Http\Controllers;

use App\Models\WixInfoSectionMigration;
use App\Models\WixStore;
use App\Helpers\WixHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;

class WixInfoSectionController extends Controller
{
    // =========================================================
    // Export Info Sections
    // =========================================================
    public function export(WixStore $store)
    {
        $userId = Auth::id() ?: 1;
        $fromStoreId = $store->instance_id;

        WixHelper::log('Export Info Sections', "Export started for store: {$store->store_name}", 'info');

        $accessToken = WixHelper::getAccessToken($fromStoreId);
        if (!$accessToken) {
            WixHelper::log('Export Info Sections', "Failed to get access token.", 'error');
            return response()->json(['error' => 'You are not authorized to access.'], 401);
        }

        // Check catalog version
        $catalogVersion = WixHelper::getCatalogVersion($accessToken);
        if (strtoupper($catalogVersion) !== 'V3') {
            WixHelper::log('Export Info Sections', "Catalog version is not V3", 'error');
            return response()->json(['error' => 'Not a Wix V3 Store'], 400);
        }

        $resp = $this->getInfoSectionsFromWix($accessToken);

        // API error handling
        if (!isset($resp['infoSections']) || !is_array($resp['infoSections'])) {
            WixHelper::log('Export Info Sections', "API error: " . json_encode($resp), 'error');
            return response()->json(['error' => 'Failed to fetch info sections from Wix.'], 500);
        }

        $infoSections = $resp['infoSections'];

        foreach ($infoSections as $section) {
            WixInfoSectionMigration::updateOrCreate(
                [
                    'user_id'               => $userId,
                    'from_store_id'         => $fromStoreId,
                    'to_store_id'           => null,
                    'source_info_section_id'=> $section['id'],
                ],
                [
                    'source_info_section_name'      => $section['uniqueName'] ?? null,
                    'destination_info_section_id'   => null,
                    'status'                       => 'pending',
                    'error_message'                => null,
                ]
            );
        }

        WixHelper::log('Export Info Sections', "Exported and saved " . count($infoSections) . " info sections.", 'success');

        return response()->streamDownload(function () use ($infoSections, $fromStoreId) {
            echo json_encode([
                'from_store_id' => $fromStoreId,
                'infoSections'  => $infoSections
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }, 'info_sections.json', [
            'Content-Type' => 'application/json',
        ]);
    }


    // =========================================================
    // Export Info Sections
    // =========================================================
    // public function import(Request $request, WixStore $store)
    // {
    //     $userId = Auth::id() ?: 1;
    //     $toStoreId = $store->instance_id;

    //     WixHelper::log('Import Info Sections', "Import started for store: {$store->store_name}", 'info');

    //     $accessToken = WixHelper::getAccessToken($toStoreId);
    //     if (!$accessToken) {
    //         WixHelper::log('Import Info Sections', "Failed to get access token.", 'error');
    //         return back()->with('error', 'You are not authoraized to access.');
    //     }

    //     // Check catalog version
    //     $catalogVersion = WixHelper::getCatalogVersion($accessToken);
    //     if ($catalogVersion !== 'V3') {
    //         WixHelper::log('Import Info Sections', "Catalog version is not V3", 'error');
    //         return back()->with('error', 'Not a Wix V3 Store');
    //     }

    //     if (!$request->hasFile('info_sections_json')) {
    //         WixHelper::log('Import Info Sections', "No file uploaded.", 'error');
    //         return back()->with('error', 'No file uploaded.');
    //     }

    //     $file = $request->file('info_sections_json');
    //     $json = file_get_contents($file->getRealPath());
    //     $decoded = json_decode($json, true);

    //     if (!isset($decoded['from_store_id'], $decoded['infoSections']) || !is_array($decoded['infoSections'])) {
    //         WixHelper::log('Import Info Sections', "Invalid JSON format.", 'error');
    //         return back()->with('error', 'Invalid JSON. Required keys: from_store_id and infoSections.');
    //     }

    //     $fromStoreId = $decoded['from_store_id'];
    //     $infoSections = $decoded['infoSections'];

    //     $imported = 0;
    //     $errors = [];

    //     foreach ($infoSections as $section) {
    //         $sourceId = $section['id'] ?? null;
    //         if (!$sourceId) continue;

    //         // Check existing migration record
    //         $migration = WixInfoSectionMigration::where([
    //             'user_id' => $userId,
    //             'from_store_id' => $fromStoreId,
    //             'source_info_section_id' => $sourceId,
    //         ])->first();

    //         // Skip if already imported
    //         if ($migration && $migration->status === 'success') continue;

    //         $sectionName = $section['uniqueName'] ?? null;

    //         // Only required fields
    //         $postSection = [
    //             'uniqueName' => $section['uniqueName'] ?? ('section_' . uniqid()),
    //             'title'      => $section['title'] ?? '',
    //             'description'=> $section['description'] ?? [],
    //         ];

    //         // You can unset system fields if necessary
    //         unset($postSection['id']);
    //         unset($postSection['revision']);
    //         unset($postSection['createdDate']);
    //         unset($postSection['updatedDate']);

    //         $response = Http::withHeaders([
    //             'Authorization' => $accessToken,
    //             'Content-Type' => 'application/json'
    //         ])->post('https://www.wixapis.com/stores/v3/info-sections', [
    //             'infoSection' => $postSection
    //         ]);

    //         if ($response->ok() && isset($response['infoSection']['id'])) {
    //             $createdId = $response['infoSection']['id'];

    //             WixInfoSectionMigration::updateOrCreate([
    //                 'user_id' => $userId,
    //                 'from_store_id' => $fromStoreId,
    //                 'source_info_section_id' => $sourceId,
    //             ], [
    //                 'to_store_id' => $toStoreId,
    //                 'source_info_section_name' => $sectionName,
    //                 'destination_info_section_id' => $createdId,
    //                 'status' => 'success',
    //                 'error_message' => null,
    //             ]);

    //             WixHelper::log('Import Info Sections', "Imported info section '{$sectionName}' (new ID: {$createdId})", 'success');
    //             $imported++;
    //         } else {
    //             $errorMsg = json_encode(['sent' => $postSection, 'response' => $response->json()]);
    //             $errors[] = $errorMsg;

    //             WixInfoSectionMigration::updateOrCreate([
    //                 'user_id' => $userId,
    //                 'from_store_id' => $fromStoreId,
    //                 'source_info_section_id' => $sourceId,
    //             ], [
    //                 'to_store_id' => $toStoreId,
    //                 'source_info_section_name' => $sectionName,
    //                 'destination_info_section_id' => null,
    //                 'status' => 'failed',
    //                 'error_message' => $errorMsg,
    //             ]);

    //             WixHelper::log('Import Info Sections', "Failed to import '{$sectionName}' " . json_encode($response->json()), 'error');
    //         }
    //     }

    //     if ($imported > 0) {
    //         WixHelper::log('Import Info Sections', "Import finished: $imported info section(s) imported." . (count($errors) ? " Some errors: " . implode("; ", $errors) : ""), 'success');
    //         return back()->with('success', "$imported info section(s) imported." . (count($errors) ? " Some errors occurred." : ""));
    //     } else {
    //         WixHelper::log('Import Info Sections', "No info sections imported. Errors: " . implode("; ", $errors), 'error');
    //         return back()->with('error', 'No info sections imported.' . (count($errors) ? " Errors: " . implode("; ", $errors) : ''));
    //     }
    // }

    public function import(Request $request, WixStore $store)
    {
        $userId    = Auth::id() ?: 1;
        $toStoreId = $store->instance_id;

        WixHelper::log('Import Info Sections', "Import started for store: {$store->store_name}", 'info');

        $accessToken = WixHelper::getAccessToken($toStoreId);
        if (!$accessToken) {
            WixHelper::log('Import Info Sections', "Failed to get access token.", 'error');
            return back()->with('error', 'You are not authoraized to access.');
        }

        // Check catalog version
        $catalogVersion = WixHelper::getCatalogVersion($accessToken);
        if ($catalogVersion !== 'V3') {
            WixHelper::log('Import Info Sections', "Catalog version is not V3", 'error');
            return back()->with('error', 'Not a Wix V3 Store');
        }

        if (!$request->hasFile('info_sections_json')) {
            WixHelper::log('Import Info Sections', "No file uploaded.", 'error');
            return back()->with('error', 'No file uploaded.');
        }

        $file    = $request->file('info_sections_json');
        $json    = file_get_contents($file->getRealPath());
        $decoded = json_decode($json, true);

        if (!isset($decoded['from_store_id'], $decoded['infoSections']) || !is_array($decoded['infoSections'])) {
            WixHelper::log('Import Info Sections', "Invalid JSON format.", 'error');
            return back()->with('error', 'Invalid JSON. Required keys: from_store_id and infoSections.');
        }

        $fromStoreId  = $decoded['from_store_id'];
        $infoSections = $decoded['infoSections'];
        $imported     = 0;
        $errors       = [];

        foreach ($infoSections as $section) {
            $sourceId = $section['id'] ?? null;
            if (!$sourceId) continue;

            // Check existing migration record
            $migration = WixInfoSectionMigration::where([
                'user_id' => $userId,
                'from_store_id' => $fromStoreId,
                'source_info_section_id' => $sourceId,
            ])->first();

            // Skip if already imported
            if ($migration && $migration->status === 'success') continue;

            $sectionName = $section['uniqueName'] ?? null;

            // Build fields for API (ensure only valid/expected keys)
            $postSection = [
                'uniqueName'  => $section['uniqueName'] ?? ('section_' . uniqid()),
                'title'       => $section['title'] ?? '',
                'description' => $section['description'] ?? [],
            ];

            $result = $this->createInfoSectionInWix($accessToken, $postSection);

            if (isset($result['infoSection']['id'])) {
                $createdId = $result['infoSection']['id'];

                WixInfoSectionMigration::updateOrCreate([
                    'user_id' => $userId,
                    'from_store_id' => $fromStoreId,
                    'source_info_section_id' => $sourceId,
                ], [
                    'to_store_id' => $toStoreId,
                    'source_info_section_name' => $sectionName,
                    'destination_info_section_id' => $createdId,
                    'status' => 'success',
                    'error_message' => null,
                ]);

                WixHelper::log('Import Info Sections', "Imported info section '{$sectionName}' (new ID: {$createdId})", 'success');
                $imported++;
            } else {
                $errorMsg = json_encode(['sent' => $postSection, 'response' => $result]);
                $errors[] = $errorMsg;

                WixInfoSectionMigration::updateOrCreate([
                    'user_id' => $userId,
                    'from_store_id' => $fromStoreId,
                    'source_info_section_id' => $sourceId,
                ], [
                    'to_store_id' => $toStoreId,
                    'source_info_section_name' => $sectionName,
                    'destination_info_section_id' => null,
                    'status' => 'failed',
                    'error_message' => $errorMsg,
                ]);

                WixHelper::log('Import Info Sections', "Failed to import '{$sectionName}': $errorMsg", 'error');
            }
        }

        if ($imported > 0) {
            WixHelper::log('Import Info Sections', "Import finished: $imported info section(s) imported." . (count($errors) ? " Some errors: " . implode("; ", $errors) : ""), count($errors) ? 'warning' : 'success');
            return back()->with('success', "$imported info section(s) imported." . (count($errors) ? " Some errors: " . implode("; ", $errors) : ""));
        } else {
            WixHelper::log('Import Info Sections', "No info sections imported. Errors: " . implode("; ", $errors), 'error');
            return back()->with('error', 'No info sections imported.' . (count($errors) ? " Errors: " . implode("; ", $errors) : ''));
        }
    }


    // =========================================================
    // Utilities
    // =========================================================
    public function getInfoSectionsFromWix($accessToken)
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
        ])->post('https://www.wixapis.com/stores/v3/info-sections/query', $query);

        WixHelper::log('Export Info Sections', 'Wix API raw response: ' . $response->body(), 'debug');

        return $response->json();
    }

    private function createInfoSectionInWix($accessToken, $postSection)
    {
        $body = ['infoSection' => $postSection];
        $response = Http::withHeaders([
            'Authorization' => $accessToken,
            'Content-Type'  => 'application/json'
        ])->post('https://www.wixapis.com/stores/v3/info-sections', $body);

        return $response->json();
    }

}
