<?php

namespace App\Http\Controllers;

use App\Helpers\WixHelper;
use App\Models\WixMediaMigration;
use App\Models\WixStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;

class WixMediaController extends Controller
{
    // ========================================================= Automatic Migrator =========================================================
    public function migrateAuto(Request $request)
    {
        $request->validate([
            'from_store'          => 'required|string',
            'to_store'            => 'required|string|different:from_store',
            'max_files_per_folder'=> 'nullable|integer|min:1',
        ]);

        $userId      = Auth::id() ?: 1;
        $fromStoreId = (string) $request->input('from_store');
        $toStoreId   = (string) $request->input('to_store');
        $limitPer    = (int) ($request->input('max_files_per_folder', 0));

        $fromStore = WixStore::where('instance_id', $fromStoreId)->first();
        $toStore   = WixStore::where('instance_id', $toStoreId)->first();
        $fromLabel = $fromStore?->store_name ?: $fromStoreId;
        $toLabel   = $toStore?->store_name   ?: $toStoreId;

        WixHelper::log('Auto Media Migration', "Start: {$fromLabel} → {$toLabel}.", 'info');

        // Tokens
        $fromToken = WixHelper::getAccessToken($fromStoreId);
        $toToken   = WixHelper::getAccessToken($toStoreId);
        if (!$fromToken || !$toToken) {
            WixHelper::log('Auto Media Migration', 'Missing access token(s).', 'error');
            return back()->with('error', 'Could not get Wix access token(s).');
        }

        // 1) Fetch source folders (+ root) and files
        $sourceFolders = $this->getWixMediaFolders($fromToken);
        $sourceFolders[] = [
            'id'            => 'media-root',
            'displayName'   => 'Root',
            'parentFolderId'=> null,
            'createdDate'   => null,
            'updatedDate'   => null,
            'state'         => 'OK',
            'namespace'     => 'NO_NAMESPACE',
        ];

        // Sort folders by createdDate ASC (nulls last)
        usort($sourceFolders, function ($a, $b) {
            $da = $a['createdDate'] ?? null; $db = $b['createdDate'] ?? null;
            if ($da === $db) return 0;
            if ($da === null) return 1;
            if ($db === null) return -1;
            return strtotime($da) <=> strtotime($db);
        });

        // Preload TARGET folders → name → id
        $targetFolders      = $this->getWixMediaFolders($toToken);
        $targetFolderByName = [];
        foreach ($targetFolders as $tf) {
            $n = strtolower((string)($tf['displayName'] ?? ''));
            if ($n !== '') $targetFolderByName[$n] = $tf['id'] ?? null;
        }

        // Helper: ensure/get target folder id by name
        $ensureTargetFolderId = function (string $name) use ($toToken, &$targetFolderByName) {
            $key = strtolower($name);
            if ($name === 'Root')  return 'media-root';
            if (isset($targetFolderByName[$key]) && $targetFolderByName[$key]) {
                return $targetFolderByName[$key];
            }
            $created = $this->createMediaFolderInWix($toToken, $name);
            $newId   = $created['folder']['id'] ?? null;
            if ($newId) {
                $targetFolderByName[$key] = $newId;
                WixHelper::log('Auto Media Migration', "Created folder '{$name}' → {$newId}.", 'info');
                return $newId;
            }
            WixHelper::log('Auto Media Migration', "Failed to create folder '{$name}': " . json_encode($created) . '.', 'warn');
            return null;
        };

        $grandImported = 0;
        $grandFailed   = 0;

        foreach ($sourceFolders as $sf) {
            $srcFolderId   = $sf['id'] ?? 'media-root';
            $srcFolderName = $sf['displayName'] ?? 'Unnamed Folder';

            // Fetch files for this source folder
            $files = $this->getWixMediaFilesByFolder($fromToken, $srcFolderId);

            // Sort files by createdDate ASC (nulls last)
            usort($files, function ($a, $b) {
                $da = $a['createdDate'] ?? null; $db = $b['createdDate'] ?? null;
                if ($da === $db) return 0;
                if ($da === null) return 1;
                if ($db === null) return -1;
                return strtotime($da) <=> strtotime($db);
            });

            if ($limitPer > 0) $files = array_slice($files, 0, $limitPer);

            // Stage/append migration row (pending)
            WixMediaMigration::updateOrCreate(
                [
                    'user_id'       => $userId,
                    'from_store_id' => $fromStoreId,
                    'folder_id'     => $srcFolderId,
                ],
                [
                    'to_store_id'     => $toStoreId,
                    'folder_name'     => $srcFolderName,
                    'total_files'     => count($files),
                    'imported_files'  => 0,
                    'status'          => 'pending',
                    'error_message'   => null,
                ]
            );

            // Resolve/ensure target folder
            $targetFolderId = ($srcFolderId === 'media-root')
                ? 'media-root'
                : $ensureTargetFolderId($srcFolderName);

            if (!$targetFolderId) {
                // Mark folder failed and continue
                WixMediaMigration::updateOrCreate(
                    [
                        'user_id'       => $userId,
                        'from_store_id' => $fromStoreId,
                        'folder_id'     => $srcFolderId,
                    ],
                    [
                        'to_store_id'     => $toStoreId,
                        'folder_name'     => $srcFolderName,
                        'total_files'     => count($files),
                        'imported_files'  => 0,
                        'status'          => 'failed',
                        'error_message'   => 'Could not ensure target folder.',
                    ]
                );
                $grandFailed += count($files);
                continue;
            }

            // Build bulk import payloads
            $requests = [];
            $failedNames = [];
            foreach ($files as $f) {
                $url         = $f['url'] ?? null;
                $displayName = $f['displayName'] ?? 'Imported_File';
                $mimeType    = $f['mimeType'] ?? null;

                if (!$url) { $failedNames[] = $displayName; continue; }

                $one = [
                    'url'            => $url,
                    'displayName'    => $displayName,
                    'parentFolderId' => $targetFolderId,
                    'private'        => false,
                ];
                if ($mimeType) $one['mimeType'] = $mimeType;
                $requests[] = $one;
            }

            $imported = 0;

            if (!empty($requests)) {
                $chunks = array_chunk($requests, 100);
                foreach ($chunks as $ci => $chunk) {
                    try {
                        $bulk = $this->importMediaFilesBulkToWix($toToken, $chunk);
                        $items = $bulk['results'] ?? [];

                        if (!is_array($items) || empty($items)) {
                            // whole chunk ambiguous → mark names as failed
                            foreach ($chunk as $c) $failedNames[] = $c['displayName'] ?? 'Unknown_File';
                            WixHelper::log(
                                'Auto Media Migration',
                                "Bulk returned unexpected structure for '{$srcFolderName}': " . json_encode($bulk) . '.',
                                'warn'
                            );
                        } else {
                            foreach ($items as $k => $res) {
                                $success = $res['itemMetadata']['success'] ?? false;
                                $fid     = $res['item']['id'] ?? null;
                                if ($success && $fid) {
                                    $imported++;
                                } else {
                                    $failedNames[] = $chunk[$k]['displayName'] ?? "File_{$k}";
                                }
                            }
                        }

                        if (($imported % 50) === 0) {
                            WixHelper::log(
                                'Auto Media Migration',
                                "Folder '{$srcFolderName}': imported {$imported}/" . count($files) . ' so far (chunk ' . ($ci + 1) . '/' . count($chunks) . ').',
                                'debug'
                            );
                        }
                    } catch (\Throwable $e) {
                        foreach ($chunk as $c) $failedNames[] = $c['displayName'] ?? 'Unknown_File';
                        WixHelper::log(
                            'Auto Media Migration',
                            "Bulk import exception in '{$srcFolderName}': " . $e->getMessage() . '.',
                            'error'
                        );
                    }
                }
            }

            $status = ($imported === count($files) && empty($failedNames)) ? 'success' : 'failed';

            WixMediaMigration::updateOrCreate(
                [
                    'user_id'       => $userId,
                    'from_store_id' => $fromStoreId,
                    'folder_id'     => $srcFolderId,
                ],
                [
                    'to_store_id'     => $toStoreId,
                    'folder_name'     => $srcFolderName,
                    'total_files'     => count($files),
                    'imported_files'  => $imported,
                    'status'          => $status,
                    'error_message'   => $failedNames ? json_encode($failedNames) : null,
                ]
            );

            $grandImported += $imported;
            $grandFailed   += count($failedNames);

            WixHelper::log(
                'Auto Media Migration',
                "Folder done: '{$srcFolderName}' | Imported: {$imported}/" . count($files) . " | Status: {$status}" .
                (count($failedNames) ? ' | Failed: ' . count($failedNames) : '') . '.',
                $failedNames ? 'warn' : 'info'
            );
        }

        WixHelper::log(
            'Auto Media Migration',
            "Completed {$fromLabel} → {$toLabel}. Files imported: {$grandImported}, failed: {$grandFailed}.",
            $grandFailed ? 'warn' : 'success'
        );

        return back()->with($grandFailed ? 'warning' : 'success',
            "Auto media migration completed. Imported: {$grandImported}, Failed: {$grandFailed}.");
    }


    // ========================================================= Manual Migrator =========================================================
    // =========================================================
    // Export Media with Folders
    // =========================================================
    public function exportFolderWithFiles(WixStore $store, Request $request)
    {
        $fromStoreId = $store->instance_id;
        $userId = Auth::id() ?: 1;

        $accessToken = WixHelper::getAccessToken($fromStoreId);
        if (!$accessToken) {
            WixHelper::log('Export Media', 'Failed to get access token.', 'error');
            return response()->json(['error' => 'You are not authorized to access.'], 401);
        }

        // Fetch all folders (with helper)
        $folders = $this->getWixMediaFolders($accessToken);

        // Add root manually
        $folders[] = [
            'id' => 'media-root',
            'displayName' => 'Root',
            'parentFolderId' => null,
            'createdDate' => null,
            'updatedDate' => null,
            'state' => 'OK',
            'namespace' => 'NO_NAMESPACE'
        ];

        $filesByFolder = [];

        foreach ($folders as $folder) {
            $folderId = $folder['id'];
            $folderName = $folder['displayName'] ?? 'Unnamed';

            try {
                // Fetch files by folder (with helper)
                $files = $this->getWixMediaFilesByFolder($accessToken, $folderId);
                $filesByFolder[$folderId] = $files;

                WixMediaMigration::updateOrCreate([
                    'user_id'       => $userId,
                    'from_store_id' => $fromStoreId,
                    'to_store_id'   => null,
                    'folder_id'     => $folderId,
                ], [
                    'folder_name'     => $folderName,
                    'total_files'     => count($files),
                    'imported_files'  => 0,
                    'status'          => 'pending',
                    'error_message'   => null
                ]);
            } catch (\Throwable $e) {
                WixMediaMigration::updateOrCreate([
                    'user_id'       => $userId,
                    'from_store_id' => $fromStoreId,
                    'to_store_id'   => null,
                    'folder_id'     => $folderId,
                ], [
                    'folder_name'     => $folderName,
                    'total_files'     => 0,
                    'imported_files'  => 0,
                    'status'          => 'failed',
                    'error_message'   => $e->getMessage()
                ]);
                $filesByFolder[$folderId] = [];
            }
        }

        $data = [
            'from_store_id' => $fromStoreId,
            'folders'       => $folders,
            'filesByFolder' => $filesByFolder
        ];

        WixHelper::log('Export Media', 'Exported ' . count($folders) . ' folders with files.', 'success');

        return response()->streamDownload(function () use ($data) {
            echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }, 'media_folder_export.json', [
            'Content-Type' => 'application/json',
        ]);
    }

    // =========================================================
    // Import Media with Folders (now using BULK import)
    // =========================================================
    public function importFolderWithFiles(Request $request, WixStore $store)
    {
        $userId = Auth::id() ?: 1;
        $toStoreId = $store->instance_id;
        $accessToken = WixHelper::getAccessToken($toStoreId);
        if (!$accessToken) {
            WixHelper::log('Import Media', "Unauthorized: missing access token for store {$toStoreId}.", 'error');
            return back()->with('error', 'Unauthorized.');
        }

        if (!$request->hasFile('media_json')) {
            WixHelper::log('Import Media', 'No file uploaded (media_json).', 'error');
            return back()->with('error', 'No file uploaded.');
        }

        $json = file_get_contents($request->file('media_json')->getRealPath());
        $data = json_decode($json, true);

        if (!isset($data['folders'], $data['filesByFolder']) || !is_array($data['folders']) || !is_array($data['filesByFolder'])) {
            WixHelper::log('Import Media', 'Invalid JSON: missing folders/filesByFolder keys.', 'error');
            return back()->with('error', 'Invalid JSON structure. Required keys: folders and filesByFolder.');
        }

        // IMPORTANT: use the same source store id that was recorded at export time
        $sourceStoreId = $data['from_store_id'] ?? 'unknown';

        // Summary counters
        $totalFolders = count($data['folders']);
        $totalFilesPlanned = 0;
        foreach ($data['folders'] as $f) {
            $fid = $f['id'] ?? 'media-root';
            $totalFilesPlanned += count($data['filesByFolder'][$fid] ?? []);
        }

        WixHelper::log(
            'Import Media',
            "Starting import to store {$toStoreId}. Source store: {$sourceStoreId}. Folders: {$totalFolders}, Files: {$totalFilesPlanned}.",
            'info'
        );

        // Sort folders by createdDate ascending (nulls last)
        usort($data['folders'], function ($a, $b) {
            $dateA = $a['createdDate'] ?? null;
            $dateB = $b['createdDate'] ?? null;
            if ($dateA === $dateB) return 0;
            if ($dateA === null) return 1;
            if ($dateB === null) return -1;
            return strtotime($dateA) <=> strtotime($dateB);
        });

        $grandImported = 0;
        $grandFailed = 0;

        foreach ($data['folders'] as $folder) {
            $folderId         = $folder['id'] ?? 'media-root';
            $folderName       = $folder['displayName'] ?? 'Unnamed Folder';
            $originalFolderId = $folderId;
            $files            = $data['filesByFolder'][$originalFolderId] ?? [];

            // Sort files by createdDate ONLY (oldest first, nulls last)
            usort($files, function ($a, $b) {
                $dateA = $a['createdDate'] ?? null;
                $dateB = $b['createdDate'] ?? null;
                if ($dateA === $dateB) return 0;
                if ($dateA === null) return 1;   // nulls last
                if ($dateB === null) return -1;  // nulls last
                return strtotime($dateA) <=> strtotime($dateB);
            });

            $targetFolderId = 'media-root';
            $imported = 0;
            $failed   = [];

            WixHelper::log(
                'Import Media',
                "Folder begin: '{$folderName}' ({$folderId}) | Files: " . count($files) . '.',
                'debug'
            );

            // Skip folder creation for media-root
            if ($folderId !== 'media-root') {
                $folderResult = $this->createMediaFolderInWix($accessToken, $folderName);

                if (!isset($folderResult['folder']['id'])) {
                    $err = 'Failed to create folder: ' . json_encode($folderResult) . '.';
                    WixHelper::log('Import Media', "Folder create failed for '{$folderName}': {$err}", 'error');

                    // KEY uses $sourceStoreId so it updates the pending export row instead of creating new
                    WixMediaMigration::updateOrCreate([
                        'user_id'       => $userId,
                        'from_store_id' => $sourceStoreId,
                        'folder_id'     => $originalFolderId,
                    ], [
                        'to_store_id'     => $toStoreId,
                        'folder_name'     => $folderName,
                        'total_files'     => count($files),
                        'imported_files'  => 0,
                        'status'          => 'failed',
                        'error_message'   => $err,
                    ]);
                    $grandFailed += count($files);
                    continue;
                }

                $targetFolderId = $folderResult['folder']['id'];
                WixHelper::log(
                    'Import Media',
                    "Folder ensured/created: '{$folderName}' → targetFolderId={$targetFolderId}.",
                    'info'
                );
            }

            // -------- BULK IMPORT (up to 100 items per request) --------
            if (count($files) > 0) {
                $requests = [];
                foreach ($files as $file) {
                    $url         = $file['url'] ?? null;
                    $displayName = $file['displayName'] ?? 'Imported_File';
                    $mimeType    = $file['mimeType'] ?? null;

                    if (!$url) {
                        $failed[] = $displayName;
                        continue;
                    }

                    $req = [
                        'url'            => $url,
                        'displayName'    => $displayName,
                        'parentFolderId' => $targetFolderId,
                        'private'        => false,
                    ];
                    if ($mimeType) $req['mimeType'] = $mimeType;

                    $requests[] = $req;
                }

                $chunks = array_chunk($requests, 100);
                foreach ($chunks as $i => $chunk) {
                    try {
                        $bulkResult = $this->importMediaFilesBulkToWix($accessToken, $chunk);

                        // New response shape handling (your logs)
                        $items = $bulkResult['results'] ?? [];
                        if (!is_array($items) || empty($items)) {
                            foreach ($chunk as $c) {
                                $failed[] = $c['displayName'] ?? 'Unknown_File';
                            }
                            WixHelper::log(
                                'Import Media',
                                "Bulk import returned unexpected structure for folder '{$folderName}': " . json_encode($bulkResult) . '.',
                                'warn'
                            );
                        } else {
                            foreach ($items as $k => $res) {
                                $originalDisplayName = $chunk[$k]['displayName'] ?? ('File_' . $k);
                                $fileId  = $res['item']['id'] ?? null;
                                $success = $res['itemMetadata']['success'] ?? false;

                                if ($success && $fileId) {
                                    $imported++;
                                } else {
                                    $failed[] = $originalDisplayName;
                                }
                            }
                        }

                        if (($imported % 50) === 0) {
                            WixHelper::log(
                                'Import Media',
                                "Folder '{$folderName}': imported {$imported}/" . count($files) . ' so far (bulk chunk ' . ($i + 1) . '/' . count($chunks) . ').',
                                'debug'
                            );
                        }
                    } catch (\Throwable $e) {
                        foreach ($chunk as $c) {
                            $failed[] = $c['displayName'] ?? 'Unknown_File';
                        }
                        WixHelper::log(
                            'Import Media',
                            "Bulk import exception in '{$folderName}': " . $e->getMessage() . '.',
                            'error'
                        );
                    }
                }
            }
            // -------- END BULK IMPORT --------

            $status = $imported === count($files) ? 'success' : 'failed';

            // KEY uses $sourceStoreId to hit the existing row from export
            WixMediaMigration::updateOrCreate([
                'user_id'       => $userId,
                'from_store_id' => $sourceStoreId,
                'folder_id'     => $originalFolderId,
            ], [
                'to_store_id'     => $toStoreId,
                'folder_name'     => $folderName,
                'total_files'     => count($files),
                'imported_files'  => $imported,
                'status'          => $status,
                'error_message'   => count($failed) ? json_encode($failed) : null
            ]);

            $grandImported += $imported;
            $grandFailed   += count($failed);

            WixHelper::log(
                'Import Media',
                "Folder end: '{$folderName}' | Imported: {$imported}/" . count($files) . " | Status: {$status}" .
                (count($failed) ? ' | Failed: ' . count($failed) : '') . '.',
                count($failed) ? 'warn' : 'info'
            );
        }

        WixHelper::log(
            'Import Media',
            "Import completed for store {$toStoreId}. Imported files: {$grandImported}, Failed: {$grandFailed}.",
            $grandFailed ? 'warn' : 'success'
        );

        return back()->with('success', 'Media import completed.');
    }



    // =========================================================
    // Utilities
    // =========================================================
    public function getWixMediaFolders($accessToken)
    {
        $response = Http::withHeaders([
            'Authorization' => $accessToken,
        ])->get("https://www.wixapis.com/site-media/v1/folders");

        WixHelper::log('Export Media', 'Folders API raw response: ' . $response->body() . '.', 'debug');

        return $response->json('folders') ?? [];
    }

    public function getWixMediaFilesByFolder($accessToken, $folderId)
    {
        $response = Http::withHeaders([
            'Authorization' => $accessToken,
            'Content-Type'  => 'application/json',
        ])->get("https://www.wixapis.com/site-media/v1/files", [
            'parentFolderId' => $folderId,
            'paging.limit'   => 100,
        ]);

        WixHelper::log('Export Media', "Files API raw response for folder {$folderId}: " . $response->body() . '.', 'debug');

        return $response->json('files') ?? [];
    }

    /**
     * Create a folder in Wix Media Manager via API.
     */
    private function createMediaFolderInWix($accessToken, $folderName)
    {
        $body = [
            'displayName'    => $folderName,
            'parentFolderId' => 'media-root'
        ];
        $response = Http::withHeaders([
            'Authorization' => $accessToken,
            'Content-Type'  => 'application/json',
        ])->post('https://www.wixapis.com/site-media/v1/folders', $body);

        return $response->json();
    }

    /**
     * NEW: Bulk import media files via API (up to 100 per request).
     * Endpoint: /site-media/v1/bulk/files/import-v2
     */
    private function importMediaFilesBulkToWix(string $accessToken, array $importFileRequests): array
    {
        $payload = [
            'importFileRequests' => $importFileRequests,
            // 'returnEntity' => true, // default true; uncomment if you want to be explicit
        ];

        $response = Http::withHeaders([
            'Authorization' => $accessToken,
            'Content-Type'  => 'application/json',
        ])->post('https://www.wixapis.com/site-media/v1/bulk/files/import-v2', $payload);

        WixHelper::log('Import Media', 'Bulk import response: ' . $response->body() . '.', $response->successful() ? 'debug' : 'warn');

        if ($response->failed()) {
            // Throwing here lets caller mark the whole chunk as failed and log properly
            throw new \RuntimeException('Bulk import request failed: ' . $response->body() . '.');
        }

        return $response->json() ?? [];
    }
}
