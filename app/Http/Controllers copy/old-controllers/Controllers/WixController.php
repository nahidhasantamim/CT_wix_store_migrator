<?php

namespace App\Http\Controllers;

use App\Helpers\WixHelper;
use App\Models\WixLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use App\Models\WixStore;

class WixController extends Controller
{
    // Show dashboard, store instance_id from query or session
    // public function dashboard(Request $request)
    // {
    //     $user = Auth::user();
    //     $instanceToken = $request->query('instance');
    //     $instanceId = null;

    //     if ($instanceToken) {
    //         $parts = explode('.', $instanceToken);
    //         $payload = json_decode(base64_decode($parts[1] ?? ''), true);
    //         $instanceId = $payload['instanceId'] ?? null;

    //         if (empty($instanceId)) {
    //             WixHelper::log('Dashboard', 'Attempted to connect store but missing instanceId.', 'error');
    //             return back()->with('error', 'Missing instanceId. Cannot connect store.');
    //         }

    //         $dummyStoreName = 'Store_' . substr($instanceId, 0, 7);

    //         $store = WixStore::where('user_id', $user->id)
    //             ->where('instance_id', $instanceId)
    //             ->first();

    //         if ($store) {
    //             if (empty($store->store_name)) {
    //                 $store->store_name = $dummyStoreName;
    //                 $store->instance_token = $instanceToken;
    //                 $store->save();
    //             } else {
    //                 if ($store->instance_token !== $instanceToken) {
    //                     $store->instance_token = $instanceToken;
    //                     $store->save();
    //                 }
    //             }
    //         } else {
    //             WixStore::create([
    //                 'user_id'        => $user->id,
    //                 'instance_id'    => $instanceId,
    //                 'instance_token' => $instanceToken,
    //                 'store_name'     => $dummyStoreName,
    //             ]);
    //         }

    //         WixHelper::log('Dashboard', "Connected store with instanceId: $instanceId", 'success');
    //     }

    //     $stores = WixStore::where('user_id', $user->id)->get();

    //     $last_accessed_store = null;
    //     if ($instanceId) {
    //         $last_accessed_store = WixStore::where('user_id', $user->id)
    //             ->where('instance_id', $instanceId)
    //             ->first();
    //     }

    //     return view('dashboard', compact('stores', 'instanceId', 'last_accessed_store'));
    // }

    public function dashboard(Request $request)
    {
        $user = Auth::user();
        // Get the currently active store instance_id from session
        $instanceId = session('current_instance_id', null);

        // If a new instance_id is detected in the session and it's not already in the database, add it
        if ($instanceId) {
            $store = WixStore::where('user_id', $user->id)
                ->where('instance_id', $instanceId)
                ->first();

            if (!$store) {
                $dummyStoreName = 'Store_' . substr($instanceId, 0, 7);
                WixStore::create([
                    'user_id'        => $user->id,
                    'instance_id'    => $instanceId,
                    'instance_token' => null,
                    'store_name'     => $dummyStoreName,
                ]);
                WixHelper::log('Dashboard', "Connected new store with instanceId: $instanceId", 'success');
            }
        }

        // Get all stores for this user
        $stores = WixStore::where('user_id', $user->id)->get();

        // Optionally, get the last accessed store from the session
        $last_accessed_store = null;
        if ($instanceId) {
            $last_accessed_store = WixStore::where('user_id', $user->id)
                ->where('instance_id', $instanceId)
                ->first();
        }

        return view('dashboard', compact('stores', 'instanceId', 'last_accessed_store'));
    }


    

    // Show logs
    public function logs(Request $request)
    {
        $logs = WixLog::where('user_id', Auth::user()->id)->orderBy('id', 'desc')->get();
        return view('logs', compact('logs'));
    }

}
