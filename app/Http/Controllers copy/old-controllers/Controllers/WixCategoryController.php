<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use App\Models\WixStore;
use App\Helpers\WixHelper;

class WixCategoryController extends Controller
{
    // Export collections
    public function export(WixStore $store)
    {
        WixHelper::log('Export Collections', "Export started for store: $store->store_name", 'info');

        $accessToken = WixHelper::getAccessToken($store->instance_id);
        if (!$accessToken) {
            WixHelper::log('Export Collections', "Failed: Could not get access token for instanceId: $store->instance_id", 'error');
            return response()->json(['error' => 'Could not get Wix access token.'], 401);
        }

        $collections = $this->getCollectionsFromWix($accessToken);

        // Log API response status and count
        $count = count($collections['collections'] ?? []);
        WixHelper::log('Export Collections', "Queried API, Status: " . ($collections['status'] ?? 'n/a') . ", Collections found: $count", 'info');
        WixHelper::log('Export Collections', "Exported $count collections for store: $store->store_name", 'success');

        return response()->streamDownload(function() use ($collections) {
            echo json_encode($collections['collections'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }, 'collections.json', [
            'Content-Type' => 'application/json'
        ]);
    }

    // Import collections
    public function import(Request $request, WixStore $store)
    {
        WixHelper::log('Import Collections', "Import started for store: $store->store_name", 'info');

        $accessToken = WixHelper::getAccessToken($store->instance_id);
        if (!$accessToken) {
            WixHelper::log('Import Collections', "Failed: Could not get access token for instanceId: $store->instance_id", 'error');
            return back()->with('error', 'Could not get Wix access token.');
        }

        if (!$request->hasFile('categories_json')) {
            WixHelper::log('Import Collections', "No file uploaded for store: $store->store_name", 'error');
            return back()->with('error', 'No file uploaded.');
        }

        $file = $request->file('categories_json');
        $json = file_get_contents($file->getRealPath());
        $collections = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($collections)) {
            WixHelper::log('Import Collections', "Uploaded file is not valid JSON.", 'error');
            return back()->with('error', 'Uploaded file is not valid JSON.');
        }

        $imported = 0;
        $errors = [];

        foreach ($collections as $collection) {
            // Clean up system fields
            unset($collection['id'], $collection['slug'], $collection['numberOfProducts']);

            $result = $this->createCollectionInWix($accessToken, $collection);

            // If error or missing id, log error
            if (isset($result['collection']['id'])) {
                $imported++;
                WixHelper::log(
                    'Import Collections',
                    "Imported collection '{$collection['name']}' (new ID: {$result['collection']['id']})",
                    'success'
                );
            } else {
                $errors[] = json_encode([
                    'sent'     => $collection,
                    'response' => $result,
                ]);
                WixHelper::log(
                    'Import Collections',
                    "Failed to import '{$collection['name']}' " . json_encode($result),
                    'error'
                );
            }
        }

        if ($imported > 0) {
            WixHelper::log(
                'Import Collections',
                "Import finished: $imported collection(s) imported." . (count($errors) ? " Some errors: " . implode("; ", $errors) : ""),
                'success'
            );
            return back()->with('success', "$imported collection(s) imported.");
        } else {
            WixHelper::log(
                'Import Collections',
                "No collections imported. Errors: " . implode("; ", $errors),
                'error'
            );
            return back()->with('error', 'No collections imported.');
        }
    }

    // -------- Utilities --------

    public function getCollectionsFromWix($accessToken)
    {
        $body = [
            'query' => new \stdClass()
        ];

        $response = Http::withHeaders([
            'Authorization' => $accessToken,
            'Content-Type'  => 'application/json'
        ])->post('https://www.wixapis.com/stores-reader/v1/collections/query', $body);

        // Optional: log raw API response for deep debugging
        WixHelper::log('Export Collections', 'Wix API raw response: ' . $response->body(), 'debug');

        return $response->json();
    }

    public function createCollectionInWix($accessToken, $collection)
    {
        $body = ['collection' => $collection];
        $response = Http::withHeaders([
            'Authorization' => $accessToken,
            'Content-Type'  => 'application/json'
        ])->post('https://www.wixapis.com/stores/v1/collections', $body);

        return $response->json();
    }
}
