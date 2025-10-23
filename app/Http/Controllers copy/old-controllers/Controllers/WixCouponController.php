<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use App\Models\WixStore;
use App\Helpers\WixHelper;

class WixCouponController extends Controller
{
    // EXPORT all coupons
    public function export(WixStore $store)
    {
        WixHelper::log('Export Coupons', "Export started for store: $store->store_name", 'info');

        $accessToken = WixHelper::getAccessToken($store->instance_id);
        if (!$accessToken) {
            WixHelper::log('Export Coupons', "Failed: Could not get access token.", 'error');
            return response()->json(['error' => 'Could not get Wix access token.'], 401);
        }

        // Query Wix API for coupons
        $coupons = $this->queryCoupons($accessToken);
        $couponArr = [];
        if (isset($coupons['coupons']) && is_array($coupons['coupons'])) {
            $couponArr = $coupons['coupons'];
        }

        $count = count($couponArr);
        WixHelper::log('Export Coupons', "Exported $count coupons.", 'success');

        // Download as JSON
        return response()->streamDownload(function() use ($couponArr) {
            echo json_encode($couponArr, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }, 'coupons.json', [
            'Content-Type' => 'application/json'
        ]);
    }

    // IMPORT coupons 
    public function import(Request $request, WixStore $store)
    {
        WixHelper::log('Import Coupons', "Import started for store: $store->store_name", 'info');

        $accessToken = WixHelper::getAccessToken($store->instance_id);
        if (!$accessToken) {
            WixHelper::log('Import Coupons', "Failed: Could not get access token.", 'error');
            return back()->with('error', 'Could not get Wix access token.');
        }

        if (!$request->hasFile('coupons_json')) {
            WixHelper::log('Import Coupons', "No file uploaded.", 'error');
            return back()->with('error', 'No file uploaded.');
        }

        $file = $request->file('coupons_json');
        $json = file_get_contents($file->getRealPath());
        $coupons = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($coupons)) {
            WixHelper::log('Import Coupons', "Uploaded file is not valid JSON.", 'error');
            return back()->with('error', 'Uploaded file is not valid JSON.');
        }

        $imported = 0;
        $errors = [];

        foreach ($coupons as $coupon) {
            // Remove system fields if present
            unset(
                $coupon['id'], $coupon['dateCreated'], $coupon['appId'],
                $coupon['expired'], $coupon['numberOfUsages'], $coupon['displayData']
            );

            // Only pass 'specification'
            $spec = $coupon['specification'] ?? null;
            if (!$spec || !is_array($spec)) {
                $errors[] = "Missing specification in coupon: " . json_encode($coupon);
                WixHelper::log('Import Coupons', "Missing specification in coupon: " . json_encode($coupon), 'error');
                continue;
            }

            if (isset($spec['scope']['group']['entityId']) && empty($spec['scope']['group']['entityId'])) {
                unset($spec['scope']['group']['entityId']);
            }

            if (
                empty($spec['code']) ||
                empty($spec['name']) ||
                empty($spec['startTime'])
            ) {
                $errors[] = "Missing name/code/startTime for coupon: " . json_encode($spec);
                WixHelper::log('Import Coupons', "Missing name/code/startTime for coupon: " . json_encode($spec), 'error');
                continue;
            }

            // Prepare request
            $body = [ 'specification' => $spec ];
            $response = Http::withHeaders([
                'Authorization' => $accessToken,
                'Content-Type'  => 'application/json'
            ])->post('https://www.wixapis.com/stores/v2/coupons', $body);

            $result = $response->json();

            if ($response->ok() && isset($result['id'])) {
                $imported++;
                WixHelper::log('Import Coupons', "Imported coupon: " . $spec['code'], 'success');
            } else {
                $err = $result ?: $response->body();
                $errors[] = "Failed to import coupon {$spec['code']}: " . json_encode($err);
                WixHelper::log('Import Coupons', "Failed to import coupon {$spec['code']}: " . json_encode($err), 'error');
            }
        }

        if ($imported > 0) {
            WixHelper::log('Import Coupons', "Import finished: $imported coupon(s) imported." . (count($errors) ? " Some errors: " . implode("; ", $errors) : ""), 'success');
            return back()->with('success', "$imported coupon(s) imported.");
        } else {
            WixHelper::log('Import Coupons', "No coupons imported. Errors: " . implode("; ", $errors), 'error');
            return back()->with('error', 'No coupons imported.');
        }
    }

    // -------- Utilities --------

    public function queryCoupons($accessToken, $query = [])
    {
        // Always send a query object (Wix API prefers this)
        if (empty($query)) {
            $query = new \stdClass();
        }
        $body = ['query' => $query];

        $response = Http::withHeaders([
            'Authorization' => $accessToken,
            'Content-Type'  => 'application/json'
        ])->post('https://www.wixapis.com/stores/v2/coupons/query', $body);

        // Log response for debugging if needed
        $json = $response->json();
        $count = count($json['coupons'] ?? []);
        WixHelper::log('Export Coupons', "Queried coupons API. Status: {$response->status()}, Returned: $count", 'info');

        // Return decoded JSON or an empty array
        return $json ?: [];
    }
}
