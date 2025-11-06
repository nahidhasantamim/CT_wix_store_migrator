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

    public function dashboard(Request $request)
    {
        $user = Auth::user();
        
        // Prefer session value; if missing/empty, fall back to query string (?instance=...)
        $instanceToken = session('current_instance_id') ?: $request->query('instance');
        $instanceId = null;

        if ($instanceToken) {
            $parts = explode('.', $instanceToken);
            $payload = json_decode(base64_decode($parts[1] ?? ''), true);
            $instanceId = $payload['instanceId'] ?? null;

            if (empty($instanceId)) {
                WixHelper::log('Dashboard', 'Attempted to connect store but missing instanceId.', 'error');
                return back()->with('error', 'Missing instanceId. Cannot connect store.');
            }

            $dummyStoreName = 'Store_' . substr($instanceId, 0, 7);

            $store = WixStore::where('user_id', $user->id)
                ->where('instance_id', $instanceId)
                ->first();

            if ($store) {
                if (empty($store->store_name)) {
                    $store->store_name = $dummyStoreName;
                    $store->instance_token = $instanceToken;
                    $store->save();
                } else {
                    if ($store->instance_token !== $instanceToken) {
                        $store->instance_token = $instanceToken;
                        $store->save();
                    }
                }
            } else {
                WixStore::create([
                    'user_id'        => $user->id,
                    'instance_id'    => $instanceId,
                    'instance_token' => $instanceToken,
                    'store_name'     => $dummyStoreName,
                ]);
            }

            WixHelper::log('Dashboard', "Connected store with instanceId: $instanceId", 'success');
        }

        $stores = WixStore::where('user_id', $user->id)->get();

        $last_accessed_store = null;
        if ($instanceId) {
            $last_accessed_store = WixStore::where('user_id', $user->id)
                ->where('instance_id', $instanceId)
                ->first();
        }

        return view('dashboard', compact('stores', 'instanceId', 'last_accessed_store'));
    }

    public function newDashboard(Request $request)
    {
        $user = Auth::user();
        
        $instanceToken = session('current_instance_id', null);
        // $instanceToken = $request->query('instance');
        $instanceId = null;

        if ($instanceToken) {
            $parts = explode('.', $instanceToken);
            $payload = json_decode(base64_decode($parts[1] ?? ''), true);
            $instanceId = $payload['instanceId'] ?? null;

            if (empty($instanceId)) {
                WixHelper::log('Dashboard', 'Attempted to connect store but missing instanceId.', 'error');
                return back()->with('error', 'Missing instanceId. Cannot connect store.');
            }

            $dummyStoreName = 'Store_' . substr($instanceId, 0, 7);

            $store = WixStore::where('user_id', $user->id)
                ->where('instance_id', $instanceId)
                ->first();

            if ($store) {
                if (empty($store->store_name)) {
                    $store->store_name = $dummyStoreName;
                    $store->instance_token = $instanceToken;
                    $store->save();
                } else {
                    if ($store->instance_token !== $instanceToken) {
                        $store->instance_token = $instanceToken;
                        $store->save();
                    }
                }
            } else {
                WixStore::create([
                    'user_id'        => $user->id,
                    'instance_id'    => $instanceId,
                    'instance_token' => $instanceToken,
                    'store_name'     => $dummyStoreName,
                ]);
            }

            WixHelper::log('Dashboard', "Connected store with instanceId: $instanceId", 'success');
        }

        $stores = WixStore::where('user_id', $user->id)->get();

        $last_accessed_store = null;
        if ($instanceId) {
            $last_accessed_store = WixStore::where('user_id', $user->id)
                ->where('instance_id', $instanceId)
                ->first();
        }

        return view('new', compact('stores', 'instanceId', 'last_accessed_store'));
    }
    

    // Show logs
    public function logs(Request $request)
    {
        $logs = WixLog::where('user_id', Auth::id())
            ->orderBy('id', 'desc')
            ->paginate(25); // loads 25 logs per page

        return view('logs', compact('logs'));
    }


}
