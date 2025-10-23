<?php

namespace App\Http\Controllers;

use App\Helpers\WixHelper;
use App\Models\WixStore;
use Illuminate\Http\Request;

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
        // Delete all associated logs
        $store->logs()->delete();

        // Delete the store
        $store->delete();

        return back()->with('success', 'Store and its logs deleted successfully.');
    }


}
