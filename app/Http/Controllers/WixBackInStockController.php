<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Auth;
use App\Models\WixStore;
use App\Helpers\WixHelper;

class WixBackInStockController extends Controller
{
    // ======================================================================
    // AUTO MIGRATE
    // ======================================================================
    public function migrateAuto(Request $request)
    {
        $request->validate([
            'from_store' => 'required|string',
            'to_store'   => 'required|string|different:from_store',
        ]);

        $fromId = $request->input('from_store');
        $toId   = $request->input('to_store');

        $from = WixStore::where('instance_id', $fromId)->first();
        $to   = WixStore::where('instance_id', $toId)->first();

        $fromLabel = $from?->store_name ?: $fromId;
        $toLabel   = $to?->store_name   ?: $toId;

        WixHelper::log('Auto BIS Migration', "===== START {$fromLabel} → {$toLabel} =====", 'info');

        $fromToken = WixHelper::getAccessToken($fromId);
        $toToken   = WixHelper::getAccessToken($toId);

        if (!$fromToken || !$toToken) {
            WixHelper::log('Auto BIS Migration', "Missing token(s) from={$fromId} to={$toId}", 'error');
            return back()->with('error', 'Could not obtain Wix access token(s).');
        }

        WixHelper::log('Auto BIS Migration', "Tokens loaded successfully", 'debug');

        // FETCH
        $list = $this->fetchBackInStock($fromToken);

        if (!is_array($list)) {
            WixHelper::log('Auto BIS Migration', "[FETCH FAILED] response was not array", 'error');
            return back()->with('error', 'Failed to fetch Back-In-Stock requests');
        }

        $total = count($list);
        WixHelper::log('Auto BIS Migration', "Fetched {$total} BIS requests", 'info');

        $imported = 0;
        $failed   = 0;

        foreach ($list as $req) {

            WixHelper::log('Auto BIS Migration', "Processing source request: ".json_encode($req), 'debug');

            $payload = $this->buildCreatePayload($req);

            if (!$payload) {
                $failed++;
                WixHelper::log('Auto BIS Migration', "Invalid payload built for request: ".json_encode($req), 'error');
                continue;
            }

            WixHelper::log('Auto BIS Migration', "Built payload: ".json_encode($payload), 'debug');

            $result = $this->createBackInStock($toToken, $payload);

            WixHelper::log('Auto BIS Migration', "Create API Response: ".json_encode($result), 'debug');

            if (isset($result['request']['id'])) {
                $imported++;
                WixHelper::log('Auto BIS Migration', "✔ Created BIS ID: ".$result['request']['id'], 'success');
            } else {
                $failed++;
                WixHelper::log(
                    'Auto BIS Migration',
                    "Create failed. Payload=".json_encode($payload)." Response=".json_encode($result),
                    'error'
                );
            }
        }

        WixHelper::log('Auto BIS Migration', "===== FINISHED imported={$imported} failed={$failed} =====", $failed ? 'warn' : 'success');

        return back()->with('success', "Back-in-Stock migration completed. Imported={$imported}, Failed={$failed}.");
    }

    // ======================================================================
    // EXPORT
    // ======================================================================
    public function export(WixStore $store)
    {
        $storeId = $store->instance_id;

        WixHelper::log('Export BIS', "===== START EXPORT for {$store->store_name} ({$storeId}) =====", 'info');

        $token = WixHelper::getAccessToken($storeId);

        if (!$token) {
            WixHelper::log('Export BIS', "Access token missing for store {$storeId}", 'error');
            return back()->with('error', 'Access denied.');
        }

        $list = $this->fetchBackInStock($token);

        if (!is_array($list)) {
            WixHelper::log('Export BIS', "Fetch failed (returned non-array)", 'error');
            return back()->with('error', 'Wix API error.');
        }

        WixHelper::log('Export BIS', "Fetched ".count($list)." BIS records", 'success');

        return response()->streamDownload(
            function () use ($list, $storeId) {
                echo json_encode([
                    'meta' => [
                        'from_store_id' => $storeId,
                        'generated_at'  => now()->toIso8601String(),
                    ],
                    'back_in_stock_requests' => $list,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            },
            'back_in_stock_requests.json',
            ['Content-Type' => 'application/json']
        );
    }

    // ======================================================================
    // MANUAL IMPORT
    // ======================================================================
    public function import(Request $request, WixStore $store)
    {
        WixHelper::log('Manual BIS Import', "===== START MANUAL IMPORT ({$store->store_name}) =====", 'info');

        $token = WixHelper::getAccessToken($store->instance_id);
        if (!$token) {
            WixHelper::log('Manual BIS Import', "Missing access token", 'error');
            return back()->with('error', 'Token missing.');
        }

        if (!$request->hasFile('back_in_stock_json')) {
            WixHelper::log('Manual BIS Import', "No uploaded file", 'error');
            return back()->with('error', 'No file uploaded.');
        }

        $json = file_get_contents($request->file('back_in_stock_json')->getRealPath());
        WixHelper::log('Manual BIS Import', "Uploaded JSON: ".$json, 'debug');

        $decoded = json_decode($json, true);

        if (!is_array($decoded)) {
            WixHelper::log('Manual BIS Import', "Invalid JSON", 'error');
            return back()->with('error', 'Invalid JSON file.');
        }

        $rows = $decoded['back_in_stock_requests'] ?? $decoded['requests'] ?? null;

        if (!is_array($rows)) {
            WixHelper::log('Manual BIS Import', "Missing required key back_in_stock_requests", 'error');
            return back()->with('error', 'Invalid structure: missing back_in_stock_requests.');
        }

        $imported = 0;
        $failed   = 0;

        foreach ($rows as $req) {

            WixHelper::log('Manual BIS Import', "Processing request: ".json_encode($req), 'debug');

            $payload = $this->buildCreatePayload($req);
            if (!$payload) {
                $failed++;
                WixHelper::log('Manual BIS Import', "Invalid payload produced from row: ".json_encode($req), 'error');
                continue;
            }

            $result = $this->createBackInStock($token, $payload);

            WixHelper::log('Manual BIS Import', "Create API Response: ".json_encode($result), 'debug');

            if (isset($result['request']['id'])) {
                $imported++;
            } else {
                $failed++;
                WixHelper::log('Manual BIS Import', "Create failed: ".json_encode($result), 'error');
            }
        }

        WixHelper::log('Manual BIS Import', "===== FINISHED imported={$imported} failed={$failed} =====", $failed ? 'warn' : 'success');

        return back()->with('success', "{$imported} request(s) imported. Failed: {$failed}");
    }

    // ======================================================================
    // FETCH FROM WIX
    // ======================================================================
    private function fetchBackInStock(string $token)
    {
        $ensureBearer = fn($t) => (stripos($t, 'Bearer') === 0 ? $t : "Bearer {$t}");

        $url  = 'https://www.wixapis.com/back-in-stock-service/v1/back-in-stock-notification-requests';
        $all  = [];
        $next = null;

        WixHelper::log('BIS Fetch', "===== FETCH START =====", 'info');

        do {
            $query = [
                "query" => [
                    "filter" => "{\"status\": \"RECEIVED\"}",
                    "paging" => ["limit" => 200],
                    "sort"   => "[{\"createdDate\": \"asc\"}]"
                ]
            ];

            if ($next) {
                $query['query']['paging']['cursor'] = $next;
            }

            WixHelper::log('BIS Fetch', "Sending query: ".json_encode($query), 'debug');

            $resp = Http::withHeaders([
                'Authorization' => $ensureBearer($token),
                'Content-Type' => 'application/json'
            ])
            ->withBody(json_encode($query), 'application/json')
            ->post($url);

            WixHelper::log(
                'BIS Fetch',
                "API Response status=".$resp->status()." body=".$resp->body(),
                $resp->ok() ? 'debug' : 'error'
            );

            if (!$resp->ok()) {
                return null;
            }

            $json  = $resp->json();
            $batch = $json['results'] ?? $json['backInStockNotificationRequests'] ?? [];

            if (is_array($batch)) {
                $all = array_merge($all, $batch);
            }

            $next = $json['pagingMetadata']['cursors']['next'] ?? null;

        } while ($next);

        WixHelper::log('BIS Fetch', "===== FETCH COMPLETE (".count($all)." total) =====", 'success');

        return $all;
    }

    // ======================================================================
    // BUILD PAYLOAD
    // ======================================================================
    private function buildCreatePayload(array $src)
    {
        WixHelper::log('BIS Payload Builder', "Source data: ".json_encode($src), 'debug');

        $email = $src['email'] ?? $src['request']['email'] ?? null;
        if (!$email) {
            WixHelper::log('BIS Payload Builder', "Missing email", 'error');
            return null;
        }

        $cat = $src['catalogReference'] ?? $src['request']['catalogReference'] ?? null;
        if (!$cat) {
            WixHelper::log('BIS Payload Builder', "Missing catalogReference", 'error');
            return null;
        }

        $name  = $src['itemDetails']['name']  ?? $src['name']  ?? null;
        $price = $src['itemDetails']['price'] ?? null;

        if (!$name || !$price) {
            WixHelper::log('BIS Payload Builder', "Missing itemDetails.name or itemDetails.price", 'error');
            return null;
        }

        $image = $src['itemDetails']['image'] ?? [];
        $itemUrl = $src['itemUrl'] ?? $src['request']['itemUrl'] ?? "";

        $payload = [
            "request" => [
                "email" => $email,
                "catalogReference" => [
                    "catalogItemId" => $cat['catalogItemId'] ?? null,
                    "appId"         => $cat['appId'] ?? null,
                    "options"       => $cat['options'] ?? [],
                ],
                "itemUrl" => $itemUrl,
                "itemDetails" => [
                    "name"  => $name,
                    "price" => $price,
                    "image" => $image
                ]
            ]
        ];

        WixHelper::log('BIS Payload Builder', "Built payload: ".json_encode($payload), 'debug');

        return $payload;
    }

    // ======================================================================
    // CREATE REQUEST IN WIX
    // ======================================================================
    private function createBackInStock(string $token, array $payload)
    {
        WixHelper::log('BIS Create', "Sending create payload: ".json_encode($payload), 'debug');

        $headers = [
            'Authorization' => (stripos($token, 'Bearer ') === 0 ? $token : 'Bearer '.$token),
            'Content-Type'  => 'application/json'
        ];

        $resp = Http::withHeaders($headers)
            ->withBody(json_encode($payload), 'application/json')
            ->post("https://www.wixapis.com/back-in-stock-service/v1/back-in-stock-notification-request");

        WixHelper::log(
            'BIS Create',
            "Response status=".$resp->status()." body=".$resp->body(),
            $resp->ok() ? 'success' : 'error'
        );

        return $resp->json();
    }
}


// curl -v -X GET "https://www.wixapis.com/back-in-stock-service/v1/back-in-stock-notification-requests" \
//   -H "Content-Type: application/json" \
//   -H "Authorization: <AUTH>" \
//   --data-binary '{"query":{}}'
