<?php

namespace App\Http\Controllers;

use App\Helpers\WixHelper;
use App\Models\WixStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class WixStoreController extends Controller
{
    
    public function update(Request $request, WixStore $store)
    {
        $request->validate([
            'store_name' => 'required|string|max:255',
            'store_logo' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg,webp|max:2048',
        ]);

        $store->store_name = $request->store_name;

        if ($request->hasFile('store_logo')) {
            $logoPath = $request->file('store_logo')->store('store_logos', 'public');
            $store->store_logo = $logoPath;
        }

        $store->save();

        WixHelper::log('Store Update', "Store info updated: {$store->id}", 'success');

        return back()->with('success', 'Store information updated successfully!');
    }


    public function destroy(WixStore $store)
    {
        $userId   = Auth::id() ?: 'system';
        $meta     = [
            'action'       => 'store_delete',
            'by_user'      => $userId,
            'store_id'     => $store->id,
            'store_name'   => $store->store_name,
            'instance_id'  => $store->instance_id,
        ];

        // Start log
        WixHelper::log('Stores', $meta + ['stage' => 'request'], 'warn');

        try {
            // If you later re-enable cascade deletes, keep the log above.
            // $store->logs()->delete();

            $store->delete();

            // Success log
            WixHelper::log('Stores', $meta + ['stage' => 'success'], 'delete');

            return back()->with('success', 'Store deleted successfully.');
        } catch (\Throwable $e) {
            // Failure log
            WixHelper::log('Stores', $meta + [
                'stage' => 'failed',
                'error' => $e->getMessage(),
            ], 'error');

            return back()->with('error', 'Failed to delete store: ' . $e->getMessage());
        }
    }


}
