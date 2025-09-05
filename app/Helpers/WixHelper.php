<?php

namespace App\Helpers;

use App\Models\WixLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;

class WixHelper
{
    public static function getAccessToken($instanceId)
    {
        $clientId = config('services.wix.client_id');
        $clientSecret = config('services.wix.client_secret');

        $response = Http::post('https://www.wixapis.com/oauth2/token', [
            'grant_type' => 'client_credentials',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'instance_id' => $instanceId,
        ]);
        $data = $response->json();

        return $data['access_token'] ?? null;
    }

    public static function log($action, $details, $status = 'info')
    {
        WixLog::create([
            'user_id' => Auth::id(),
            'action'  => $action,
            'details' => is_array($details) ? json_encode($details, JSON_PRETTY_PRINT) : $details,
            'status'  => $status,
        ]);
    }


    public static function getCatalogVersion($accessToken)
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => $accessToken,
                'Content-Type'  => 'application/json'
            ])->get('https://www.wixapis.com/stores/v3/provision/version');
            if ($response->ok()) {
                $data = $response->json();
                return $data['catalogVersion'] ?? null;
            }
            return null;
        } catch (\Throwable $e) {
            self::log('Get Catalog Version', $e->getMessage(), 'error');
            return null;
        }
    }


}
