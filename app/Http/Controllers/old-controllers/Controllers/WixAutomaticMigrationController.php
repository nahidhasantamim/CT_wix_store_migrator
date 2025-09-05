<?php

namespace App\Http\Controllers;

use App\Helpers\WixHelper;
use Illuminate\Http\Request;
use App\Models\WixStore;
use Illuminate\Support\Facades\Http;

class WixAutomaticMigrationController extends Controller
{
    public function migrate(Request $request)
    {
        // Parse input
        $fromStoreId = $request->input('from_store');
        $toStoreId = $request->input('to_store');

        // Migration options
        $options = [
            'collections' => $request->has('migrate_collections'),
            'products'    => $request->has('migrate_products'),
            'coupons'     => $request->has('migrate_coupons'),
            'contacts'    => $request->has('migrate_contacts'),
            'orders'      => $request->has('migrate_orders'),
        ];

        // 1. Validation: Must be different stores
        if ($fromStoreId === $toStoreId) {
            return back()->with('error', 'Please select two different stores for migration.');
        }

        // 2. Find store models
        $fromStore = WixStore::where('instance_id', $fromStoreId)->first();
        $toStore = WixStore::where('instance_id', $toStoreId)->first();

        if (!$fromStore || !$toStore) {
            return back()->with('error', 'One or both selected stores not found.');
        }

        $migrationSummary = [];
        $allErrors = [];

        // 3. Migrate each resource
        foreach ($options as $type => $selected) {
            if (!$selected) continue;
            try {
                $fn = "migrate" . ucfirst($type);
                if (!method_exists($this, $fn)) {
                    $msg = "Migration handler missing for $type";
                    WixHelper::log("Migration", $msg, 'error');
                    $allErrors[] = $msg;
                    continue;
                }
                [$count, $errors] = $this->$fn($fromStore, $toStore);
                $migrationSummary[] = ucfirst($type) . ": $count migrated";
                $allErrors = array_merge($allErrors, $errors);
            } catch (\Throwable $e) {
                $msg = "Fatal error during $type migration: " . $e->getMessage();
                WixHelper::log("Migration", $msg, 'error');
                $allErrors[] = $msg;
            }
        }

        $resultMsg = "Migration completed.";
        if ($migrationSummary) {
            $resultMsg .= "<br>" . implode('<br>', $migrationSummary);
        }
        
        if ($allErrors) {
            $resultMsg .= "<br><b>Migration completed with some errors.</b><br>"
                . "Please check the migration log for detailed error information.<br>"
                . implode('<br>', $allErrors);
            return back()->with('success', $resultMsg); // 'success' so it shows green, but includes warning
        } else {
            return back()->with('success', $resultMsg);
        }

    }

    // ---------------------------------
    // COLLECTIONS
    protected function migrateCollections($fromStore, $toStore)
    {
        $errors = [];
        $fromToken = WixHelper::getAccessToken($fromStore->instance_id);
        $toToken = WixHelper::getAccessToken($toStore->instance_id);
        $count = 0;
        if (!$fromToken || !$toToken) {
            $msg = "Could not get access token for one or both stores.";
            WixHelper::log('Migrate Collections', $msg, 'error');
            return [0, [$msg]];
        }

        // Export
        $collectionsData = (new WixCategoryController)->getCollectionsFromWix($fromToken);
        $collections = $collectionsData['collections'] ?? [];
        // Import
        foreach ($collections as $collection) {
            unset($collection['id'], $collection['slug'], $collection['numberOfProducts']);
            $res = (new WixCategoryController)->createCollectionInWix($toToken, $collection);
            if (isset($res['collection']['id'])) {
                $count++;
            } else {
                $msg = "Failed to import collection '{$collection['name']}'";
                WixHelper::log('Migrate Collections', $msg, 'error');
                $errors[] = $msg . ' ' . json_encode($res);
            }
        }
        return [$count, $errors];
    }

    // ---------------------------------
    // PRODUCTS (INVENTORY + COLLECTION RELATION)
    protected function migrateProducts($fromStore, $toStore)
    {
        $errors = [];
        $fromToken = WixHelper::getAccessToken($fromStore->instance_id);
        $toToken = WixHelper::getAccessToken($toStore->instance_id);
        $count = 0;

        if (!$fromToken || !$toToken) {
            $msg = "Could not get access token for one or both stores.";
            WixHelper::log('Migrate Products', $msg, 'error');
            return [0, [$msg]];
        }

        // Export products array (in-memory, not file)
        $productsExport = (new WixProductController)->getAllProducts($fromToken, $fromStore);
        $products = $productsExport['products'] ?? [];

        // Export all inventory data
        $inventoryItems = (new WixProductController)->queryInventoryItems($fromToken)['inventoryItems'] ?? [];
        $skuInventoryMap = [];
        foreach ($inventoryItems as $inv) {
            if (!empty($inv['sku'])) {
                $skuInventoryMap[$inv['sku']] = $inv;
            }
        }
        // Attach inventory data to products for import
        foreach ($products as &$product) {
            $sku = $product['sku'] ?? null;
            if ($sku && isset($skuInventoryMap[$sku])) {
                $product['inventory'] = $skuInventoryMap[$sku];
            }
        }
        unset($product);

        // Now import one by one (using your controller logic, you might want to DRY/refactor this)
        $imported = 0;
        foreach ($products as $product) {
            try {
                // You might want to adjust this: call your import logic or recreate what your product import controller does in-memory
                $response = (new WixProductController)->importProductArray($toToken, $product);
                if ($response['success'] ?? false) {
                    $imported++;
                } else {
                    $msg = "Product import failed: " . ($response['msg'] ?? 'Unknown error');
                    $errors[] = $msg;
                    WixHelper::log('Migrate Products', $msg, 'error');
                }
            } catch (\Throwable $e) {
                $msg = "Error importing product: " . $e->getMessage();
                $errors[] = $msg;
                WixHelper::log('Migrate Products', $msg, 'error');
            }
        }

        return [$imported, $errors];
    }

    // ---------------------------------
    // COUPONS
    protected function migrateCoupons($fromStore, $toStore)
    {
        $errors = [];
        $fromToken = WixHelper::getAccessToken($fromStore->instance_id);
        $toToken = WixHelper::getAccessToken($toStore->instance_id);
        $count = 0;
        if (!$fromToken || !$toToken) {
            $msg = "Could not get access token for one or both stores.";
            WixHelper::log('Migrate Coupons', $msg, 'error');
            return [0, [$msg]];
        }
        $fromData = (new WixCouponController)->queryCoupons($fromToken);
        $coupons = $fromData['coupons'] ?? [];
        foreach ($coupons as $coupon) {
            unset($coupon['id'], $coupon['dateCreated'], $coupon['appId'],
                  $coupon['expired'], $coupon['numberOfUsages'], $coupon['displayData']);
            $spec = $coupon['specification'] ?? null;
            if (!$spec || !is_array($spec)) continue;
            $body = ['specification' => $spec];
            $res = Http::withHeaders([
                'Authorization' => $toToken,
                'Content-Type' => 'application/json'
            ])->post('https://www.wixapis.com/stores/v2/coupons', $body);
            $json = $res->json();
            if ($res->ok() && isset($json['id'])) {
                $count++;
            } else {
                $errMsg = "Coupon import failed for code: {$spec['code']}";
                $errors[] = $errMsg . ' ' . json_encode($json);
                WixHelper::log('Migrate Coupons', $errMsg, 'error');
            }
        }
        return [$count, $errors];
    }

    // ---------------------------------
    // CONTACTS
    protected function migrateContacts($fromStore, $toStore)
    {
        $errors = [];
        $fromToken = WixHelper::getAccessToken($fromStore->instance_id);
        $toToken = WixHelper::getAccessToken($toStore->instance_id);
        $count = 0;
        if (!$fromToken || !$toToken) {
            $msg = "Could not get access token for one or both stores.";
            WixHelper::log('Migrate Contacts', $msg, 'error');
            return [0, [$msg]];
        }

        // Export all contacts from fromStore (in batches)
        $contacts = [];
        $limit = 1000;
        $offset = 0;

        do {
            $query = [
                'paging.limit' => $limit,
                'paging.offset' => $offset,
                'fieldsets' => 'FULL'
            ];

            $response = Http::withHeaders([
                'Authorization' => $fromToken,
                'Content-Type'  => 'application/json'
            ])->get('https://www.wixapis.com/contacts/v4/contacts', $query);

            $data = $response->json();
            if (!empty($data['contacts'])) {
                $contacts = array_merge($contacts, $data['contacts']);
            }
            $countBatch = $data['pagingMetadata']['count'] ?? 0;
            $total = $data['pagingMetadata']['total'] ?? 0;
            $offset += $countBatch;
        } while ($countBatch > 0 && $offset < $total);

        // Import to toStore
        $imported = 0;
        foreach ($contacts as $contact) {
            unset(
                $contact['id'],
                $contact['revision'],
                $contact['source'],
                $contact['createdDate'],
                $contact['updatedDate'],
                $contact['memberInfo'],
                $contact['primaryEmail'],
                $contact['primaryInfo'],
                $contact['picture']
            );
            $info = $contact['info'] ?? $contact;
            $body = ['info' => $info];
            $resp = Http::withHeaders([
                'Authorization' => $toToken,
                'Content-Type' => 'application/json'
            ])->post('https://www.wixapis.com/contacts/v4/contacts', $body);
            if ($resp->status() === 201 && isset($resp->json()['contact']['id'])) {
                $imported++;
            } else {
                $errMsg = "Contact import failed for: " . json_encode($info);
                $errors[] = $errMsg;
                WixHelper::log('Migrate Contacts', $errMsg, 'error');
            }
        }
        return [$imported, $errors];
    }

    // ---------------------------------
    // ORDERS
    protected function migrateOrders($fromStore, $toStore)
    {
        $errors = [];
        $fromToken = WixHelper::getAccessToken($fromStore->instance_id);
        $toToken = WixHelper::getAccessToken($toStore->instance_id);
        $count = 0;
        if (!$fromToken || !$toToken) {
            $msg = "Could not get access token for one or both stores.";
            WixHelper::log('Migrate Orders', $msg, 'error');
            return [0, [$msg]];
        }
        // Query all order IDs
        $orderIds = [];
        $query = [
            "sort" => '[{"dateCreated":"asc"}]',
            "paging" => [
                "limit" => 100,
                "offset" => 0
            ]
        ];
        do {
            $body = ['query' => $query];
            $response = Http::withHeaders([
                'Authorization' => $fromToken,
                'Content-Type'  => 'application/json'
            ])->post('https://www.wixapis.com/stores/v2/orders/query', $body);
            $data = $response->json();
            $ordersPage = $data['orders'] ?? [];
            foreach ($ordersPage as $orderSummary) {
                if (!empty($orderSummary['id'])) {
                    $orderIds[] = $orderSummary['id'];
                }
            }
            $countBatch = count($ordersPage);
            $query['paging']['offset'] += $countBatch;
        } while ($countBatch > 0);

        // Fetch full details for each order, import
        $imported = 0;
        foreach ($orderIds as $orderId) {
            $orderResp = Http::withHeaders([
                'Authorization' => $fromToken,
                'Content-Type'  => 'application/json'
            ])->get("https://www.wixapis.com/ecom/v1/orders/{$orderId}");
            $order = $orderResp->json('order');
            if (!$order) continue;
            // Remove system fields
            unset(
                $order['id'], $order['number'], $order['createdDate'], $order['updatedDate'],
                $order['siteLanguage'], $order['isInternalOrderCreate'], $order['seenByAHuman'], $order['customFields']
            );
            // Clean lineItems
            if (!empty($order['lineItems'])) {
                foreach ($order['lineItems'] as &$lineItem) {
                    unset($lineItem['id'], $lineItem['rootCatalogItemId'], $lineItem['priceUndetermined'], $lineItem['fixedQuantity'], $lineItem['modifierGroups']);
                }
            }
            // Clean activities
            if (isset($order['activities'])) {
                foreach ($order['activities'] as &$activity) {
                    unset($activity['id']);
                }
            }
            $body = ['order' => $order];
            $resp = Http::withHeaders([
                'Authorization' => $toToken,
                'Content-Type'  => 'application/json'
            ])->post('https://www.wixapis.com/ecom/v1/orders', $body);
            if ($resp->status() === 200 && isset($resp->json()['order']['id'])) {
                $imported++;
            } else {
                $errMsg = "Order import failed: " . json_encode($resp->json());
                $errors[] = $errMsg;
                WixHelper::log('Migrate Orders', $errMsg, 'error');
            }
        }
        return [$imported, $errors];
    }
}