<?php

namespace App\Http\Controllers;

use App\Helpers\WixContactHelper;
use App\Helpers\WixCouponHelper;
use App\Helpers\WixDiscountRuleHelper;
use App\Helpers\WixHelper;
use App\Helpers\WixMediaHelper;
use App\Helpers\WixMemberHelper;
use App\Helpers\WixOrderHelper;
use App\Models\WixCollectionMigration;
use App\Models\WixContactMigration;
use App\Models\WixCouponMigration;
use App\Models\WixDiscountRuleMigration;
use App\Models\WixMediaMigration;
use App\Models\WixOrderMigration;
use App\Models\WixProductMigration;
use Illuminate\Http\Request;
use App\Models\WixStore;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;

class WixAutomaticMigrationController extends Controller
{
    public function migrate(Request $request)
    {
        $fromStoreId = $request->input('from_store');
        $toStoreId   = $request->input('to_store');

        $options = [
            'collections' => $request->has('migrate_collections'),
            'products'    => $request->has('migrate_products'),

            'contacts'       => $request->has('migrate_contacts'),
            'orders'         => $request->has('migrate_orders'),

            'coupons'     => $request->has('migrate_coupons'),
            'discount_rules' => $request->has('migrate_discounts'),
            'media'          => $request->has('migrate_media'),
        ];

        if ($fromStoreId === $toStoreId) {
            return back()->with('error', 'Please select two different stores for migration.');
        }

        $fromStore = WixStore::where('instance_id', $fromStoreId)->first();
        $toStore   = WixStore::where('instance_id', $toStoreId)->first();

        if (!$fromStore || !$toStore) {
            return back()->with('error', 'One or both selected stores not found.');
        }

        $migrationSummary = [];
        $allErrors = [];
        $collectionMap = []; // source_slug => dest_id

        // Migrate collections (categories)
        if ($options['collections']) {
            [$count, $errors, $collectionMap] = $this->migrateCollections($fromStore, $toStore);
            $migrationSummary[] = "Collections: $count migrated";
            $allErrors = array_merge($allErrors, $errors);
        }

        // Migrate products and update their collectionIds
        if ($options['products']) {
            [$count, $errors] = $this->migrateProducts($fromStore, $toStore, $collectionMap);
            $migrationSummary[] = "Products: $count migrated";
            $allErrors = array_merge($allErrors, $errors);
        }

        // Migrate contacts
        if ($options['contacts']) {
            [$count, $errors] = $this->migrateContacts($fromStore, $toStore);
            $migrationSummary[] = "Contacts: $count migrated";
            $allErrors = array_merge($allErrors, $errors);
        }

        // Migrate orders
        if ($options['orders']) {
            [$count, $errors] = $this->migrateOrders($fromStore, $toStore);
            $migrationSummary[] = "Orders: $count migrated";
            $allErrors = array_merge($allErrors, $errors);
        }


        // Migrate coupons
        if ($options['coupons']) {
            [$count, $errors] = $this->migrateCoupons($fromStore, $toStore);
            $migrationSummary[] = "Coupons: $count migrated";
            $allErrors = array_merge($allErrors, $errors);
        }

        // Migrate discount rules
        if ($options['discount_rules']) {
            [$count, $errors] = $this->migrateDiscountRules($fromStore, $toStore);
            $migrationSummary[] = "Discount Rules: $count migrated";
            $allErrors = array_merge($allErrors, $errors);
        }

        // Migrate media
        if ($options['media']) {
            [[$foldersImported, $filesImported], $errors] = $this->migrateMedia($fromStore, $toStore);
            $migrationSummary[] = "Media: {$foldersImported} folders, {$filesImported} files migrated";
            $allErrors = array_merge($allErrors, $errors);
        }

        $resultMsg = "Migration completed.";
        if ($migrationSummary) $resultMsg .= "<br>" . implode('<br>', $migrationSummary);

        if ($allErrors) {
            $resultMsg .= "<br><b>Migration completed with some errors.</b><br>"
                . "Please check the migration log for detailed error information.<br>"
                . implode('<br>', $allErrors);
            return back()->with('success', $resultMsg);
        } else {
            return back()->with('success', $resultMsg);
        }
    }

    // ---------------------------------
    // COLLECTIONS (categories)
    // Returns: [$count, $errors, $collectionMap]
    // ---------------------------------
    protected function migrateCollections($fromStore, $toStore)
    {
        $errors = [];
        $fromToken = WixHelper::getAccessToken($fromStore->instance_id);
        $toToken   = WixHelper::getAccessToken($toStore->instance_id);
        $userId    = Auth::id() ?? 1;
        $count = 0;
        $collectionMap = [];

        if (!$fromToken || !$toToken) {
            $msg = "Could not get access token for one or both stores.";
            WixHelper::log('Migrate Collections', $msg, 'error');
            return [0, [$msg], []];
        }

        $categoryCtrl = new WixCategoryController();

        // Export all collections from source
        $collectionsData = $categoryCtrl->getCollectionsFromWix($fromToken);
        $collections     = $collectionsData['collections'] ?? [];
        $fromStoreId     = $fromStore->instance_id;
        $toStoreId       = $toStore->instance_id;

        foreach ($collections as $collection) {
            $sourceId   = $collection['id'] ?? null;
            $sourceSlug = $collection['slug'] ?? null;
            $sourceName = $collection['name'] ?? null;
            if (!$sourceId) continue;

            // Log/track as pending if not already there
            $migration = WixCollectionMigration::firstOrCreate([
                'user_id' => $userId,
                'from_store_id' => $fromStoreId,
                'source_collection_id' => $sourceId,
            ], [
                'to_store_id' => $toStoreId,
                'source_collection_slug' => $sourceSlug,
                'source_collection_name' => $sourceName,
                'status' => 'pending',
            ]);

            // Skip if already imported
            if ($migration->status === 'success') {
                if ($sourceSlug) $collectionMap[$sourceSlug] = $migration->destination_collection_id;
                continue;
            }

            // Remove system fields for import
            $importCollection = $collection;
            unset($importCollection['id'], $importCollection['slug'], $importCollection['numberOfProducts']);

            $result = $categoryCtrl->createCollectionInWix($toToken, $importCollection);

            if (isset($result['collection']['id'])) {
                $count++;
                $newId = $result['collection']['id'];
                if ($sourceSlug) $collectionMap[$sourceSlug] = $newId;
                $migration->update([
                    'to_store_id' => $toStoreId,
                    'destination_collection_id' => $newId,
                    'status' => 'success',
                    'error_message' => null,
                ]);
                WixHelper::log('Migrate Collections', "Imported collection '{$sourceName}' as new ID: {$newId}", 'success');
            } else {
                $errorMsg = json_encode(['sent' => $importCollection, 'response' => $result]);
                $migration->update([
                    'to_store_id' => $toStoreId,
                    'status' => 'failed',
                    'error_message' => $errorMsg,
                ]);
                $errors[] = $errorMsg;
                WixHelper::log('Migrate Collections', "Failed to import '{$sourceName}' " . $errorMsg, 'error');
            }
        }

        return [$count, $errors, $collectionMap];
    }


    // ---------------------------------
    // PRODUCTS
    // Accepts $collectionMap to update collectionIds before import
    // ---------------------------------
    protected function migrateProducts($fromStore, $toStore, $collectionMap = [])
    {
        $errors = [];
        $fromToken = WixHelper::getAccessToken($fromStore->instance_id);
        $toToken   = WixHelper::getAccessToken($toStore->instance_id);
        $userId    = Auth::id() ?? 1;
        $fromStoreId = $fromStore->instance_id;
        $toStoreId = $toStore->instance_id;
        $imported = 0;

        if (!$fromToken || !$toToken) {
            $msg = "Could not get access token for one or both stores.";
            WixHelper::log('Migrate Products', $msg, 'error');
            return [0, [$msg]];
        }

        $productCtrl = new WixProductController();

        // Export products
        $productsExport = $productCtrl->getAllProducts($fromToken, $fromStore);
        $products = $productsExport['products'] ?? [];

        // Export all inventory data
        $inventoryItems = $productCtrl->queryInventoryItems($fromToken)['inventoryItems'] ?? [];
        $skuInventoryMap = [];
        foreach ($inventoryItems as $inv) {
            if (!empty($inv['sku'])) {
                $skuInventoryMap[$inv['sku']] = $inv;
            }
        }

        // Attach inventory to products, update collectionIds
        foreach ($products as &$product) {
            $sku = $product['sku'] ?? null;
            if ($sku && isset($skuInventoryMap[$sku])) {
                $product['inventory'] = $skuInventoryMap[$sku];
            }
            // --- Update collectionIds to target store ---
            if (!empty($product['collectionSlugs']) && is_array($product['collectionSlugs'])) {
                $newCollectionIds = [];
                foreach ($product['collectionSlugs'] as $slug) {
                    if (isset($collectionMap[$slug])) {
                        $newCollectionIds[] = $collectionMap[$slug];
                    }
                }
                if ($newCollectionIds) {
                    $product['collectionIds'] = $newCollectionIds;
                }
            }
        }
        unset($product);

        // Import each product
        foreach ($products as $product) {
            $sourceId   = $product['id'] ?? null;
            $sourceSku  = $product['sku'] ?? null;
            $sourceName = $product['name'] ?? null;

            if (!$sourceId) continue;

            // Upsert DB migration row (pending, unless already success)
            $migration = WixProductMigration::firstOrCreate([
                'user_id'           => $userId,
                'from_store_id'     => $fromStoreId,
                'source_product_id' => $sourceId,
            ], [
                'to_store_id'           => $toStoreId,
                'source_product_sku'    => $sourceSku,
                'source_product_name'   => $sourceName,
                'status'                => 'pending',
            ]);

            if ($migration->status === 'success') continue;

            // Migrate using your array import (with collection slug map)
            $response = $productCtrl->importProductArray($toToken, $product, $collectionMap);

            if (($response['success'] ?? false) && !empty($response['id'])) {
                $imported++;
                $migration->update([
                    'to_store_id'               => $toStoreId,
                    'destination_product_id'    => $response['id'],
                    'status'                    => 'success',
                    'error_message'             => null,
                ]);
                WixHelper::log('Migrate Products', "Imported product '{$sourceName}' as new ID: {$response['id']}", 'success');
            } else {
                $errorMsg = $response['msg'] ?? json_encode($response);
                $migration->update([
                    'to_store_id'            => $toStoreId,
                    'status'                 => 'failed',
                    'error_message'          => $errorMsg,
                ]);
                $errors[] = "Product '{$sourceName}' import failed: " . $errorMsg;
                WixHelper::log('Migrate Products', "Failed to import product '{$sourceName}' - $errorMsg", 'error');
            }
        }

        return [$imported, $errors];
    }

    // ---------------------------------
    // CONTACTS (+ Members, Attachments)
    // ---------------------------------
    protected function migrateContacts($fromStore, $toStore)
    {
        $errors       = [];
        $imported     = 0;

        $userId       = Auth::id() ?: 1;
        $fromToken    = WixHelper::getAccessToken($fromStore->instance_id);
        $toToken      = WixHelper::getAccessToken($toStore->instance_id);
        $fromStoreId  = $fromStore->instance_id;
        $toStoreId    = $toStore->instance_id;

        if (!$fromToken || !$toToken) {
            $msg = "Could not get access token for one or both stores.";
            WixHelper::log('Migrate Contacts', $msg, 'error');
            return [0, [$msg]];
        }

        // 1) Export contacts from source
        $export   = WixContactHelper::listAllContacts($fromToken);
        $contacts = $export['contacts'] ?? [];

        WixHelper::log(
            'Migrate Contacts',
            "Starting contact migration: source={$fromStoreId} → target={$toStoreId}; total=" . count($contacts),
            'info'
        );

        // Sort contacts by createdDate ASC to keep order consistent
        usort($contacts, function ($a, $b) {
            $da = $a['createdDate'] ?? null;
            $db = $b['createdDate'] ?? null;
            if ($da === $db) return 0;
            if ($da === null) return 1;
            if ($db === null) return -1;
            return strtotime($da) <=> strtotime($db);
        });

        // 2) Load extended-field definitions (CONTACTS) from both stores
        $srcExtDefs = WixContactHelper::listContactExtendedFields($fromToken); // key => ['displayName','dataType']
        $dstExtDefs = WixContactHelper::listContactExtendedFields($toToken);   // key => ['displayName','dataType']

        // Cache for extended field key mapping: sourceKey => targetKey
        $extKeyMap = [];

        // 3) Member ID map (old -> new) for graph restoration (badges/following)
        $oldMemberIdToNewMemberId = [];

        // 4) Pass 1 — Contacts (create/merge) + basic member creation + attachments
        $processed = 0;
        foreach ($contacts as $contact) {
            $sourceContactId = $contact['id'] ?? null;

            // ----- Sanitize contact payload -----
            unset(
                $contact['revision'], $contact['source'], $contact['createdDate'], $contact['updatedDate'],
                $contact['memberInfo'], $contact['primaryEmail'], $contact['primaryInfo'], $contact['picture']
            );

            $info = $contact['info'] ?? $contact;

            // Keep only allowed keys
            $allowedInfo = [
                'name','emails','phones','addresses','company',
                'jobTitle','birthdate','locale','labelKeys',
                'extendedFields','locations'
            ];

            $filtered = [];
            foreach ($allowedInfo as $k) {
                if (isset($info[$k])) $filtered[$k] = $info[$k];
            }

            // Normalize nested arrays
            foreach (['emails','phones','addresses'] as $k) {
                if (!empty($filtered[$k]['items'])) {
                    $filtered[$k] = ['items' => array_values($filtered[$k]['items'])];
                } else {
                    unset($filtered[$k]);
                }
            }

            // ----- Labels -----
            $labelKeysTarget = [];
            if (!empty($info['labelKeys']) && is_array($info['labelKeys'])) {
                foreach ($info['labelKeys'] as $srcLabelKey) {
                    // Fetch label metadata by key from source
                    $labelMetaResp = Http::withHeaders([
                        'Authorization' => $fromToken,
                        'Content-Type'  => 'application/json'
                    ])->get('https://www.wixapis.com/contacts/v4/labels/'.urlencode($srcLabelKey));

                    $displayName = $labelMetaResp->ok() ? ($labelMetaResp->json('displayName') ?? null) : null;
                    if ($displayName) {
                        $ensured = WixContactHelper::ensureLabel($toToken, $displayName);
                        if (!empty($ensured['key'])) {
                            $labelKeysTarget[] = $ensured['key'];
                        }
                    }
                }
            }
            if ($labelKeysTarget) {
                $filtered['labelKeys'] = $labelKeysTarget;
            } else {
                unset($filtered['labelKeys']);
            }

            // ----- Extended fields: only custom.* items -----
            if (!empty($info['extendedFields']['items']) && is_array($info['extendedFields']['items'])) {
                $dstItems = [];
                foreach ($info['extendedFields']['items'] as $srcKey => $value) {
                    if (strpos($srcKey, 'custom.') !== 0) continue; // skip non-custom
                    if (!isset($extKeyMap[$srcKey])) {
                        if (isset($dstExtDefs[$srcKey])) {
                            $extKeyMap[$srcKey] = $srcKey;
                        } else {
                            $displayName = $srcExtDefs[$srcKey]['displayName'] ?? $srcKey;
                            $dataType    = $srcExtDefs[$srcKey]['dataType'] ?? 'TEXT';
                            $newKey      = WixContactHelper::createContactExtendedField($toToken, $displayName, $dataType ?: 'TEXT');
                            if ($newKey) {
                                $extKeyMap[$srcKey] = $newKey;
                                $dstExtDefs[$newKey] = ['displayName' => $displayName, 'dataType' => $dataType];
                            } else {
                                continue;
                            }
                        }
                    }
                    $dstItems[$extKeyMap[$srcKey]] = $value;
                }
                if ($dstItems) {
                    $filtered['extendedFields'] = ['items' => $dstItems];
                } else {
                    unset($filtered['extendedFields']);
                }
            } else {
                unset($filtered['extendedFields']);
            }

            // Remove empties
            $filtered = WixContactHelper::cleanEmpty($filtered);

            // Basic validation
            $email = $filtered['emails']['items'][0]['email'] ?? null;
            $name  = $filtered['name']['first'] ?? ($filtered['name']['formatted'] ?? null);

            // Upsert PENDING before attempting (target-aware key)
            WixContactMigration::updateOrCreate([
                'user_id'       => $userId,
                'from_store_id' => $fromStoreId,
                'to_store_id'   => $toStoreId,
                'contact_email' => $email,
            ], [
                'contact_name'            => $name,
                'destination_contact_id'  => null,
                'status'                  => 'pending',
                'error_message'           => null,
            ]);

            if (!$name && !$email && empty($filtered['phones']['items'])) {
                $msg = "Contact missing required fields (name/email/phone)";
                $errors[] = $msg;
                WixContactMigration::updateOrCreate([
                    'user_id'       => $userId,
                    'from_store_id' => $fromStoreId,
                    'to_store_id'   => $toStoreId,
                    'contact_email' => $email,
                ], [
                    'contact_name'            => $name,
                    'destination_contact_id'  => null,
                    'status'                  => 'failed',
                    'error_message'           => $msg,
                ]);
                continue;
            }

            // Check migration dedupe (target-aware)
            $existingMig = WixContactMigration::where([
                'user_id'       => $userId,
                'from_store_id' => $fromStoreId,
                'to_store_id'   => $toStoreId,
                'contact_email' => $email,
            ])->first();
            if ($existingMig && $existingMig->status === 'success') {
                continue;
            }

            // Dedupe in target by email
            $existingTarget = $email ? WixContactHelper::findContactByEmail($toToken, $email) : null;
            $destContactId  = $existingTarget['id'] ?? null;

            if (!$destContactId) {
                $create = WixContactHelper::createContact($toToken, $filtered, true);
                $destContactId = $create['contact']['id'] ?? null;

                if ($destContactId) {
                    $imported++;
                    WixHelper::log('Migrate Contacts', "Created contact: " . ($name ?? $email ?? 'Unknown'), 'success');
                } else {
                    $err = json_encode(['sent' => $filtered, 'response' => $create]);
                    $errors[] = $err;
                    WixContactMigration::updateOrCreate([
                        'user_id'       => $userId,
                        'from_store_id' => $fromStoreId,
                        'to_store_id'   => $toStoreId,
                        'contact_email' => $email,
                    ], [
                        'contact_name'            => $name,
                        'destination_contact_id'  => null,
                        'status'                  => 'failed',
                        'error_message'           => $err,
                    ]);
                    continue;
                }
            }

            // Save migration row (success; target-aware)
            WixContactMigration::updateOrCreate([
                'user_id'       => $userId,
                'from_store_id' => $fromStoreId,
                'to_store_id'   => $toStoreId,
                'contact_email' => $email,
            ], [
                'contact_name'            => $name,
                'destination_contact_id'  => $destContactId,
                'status'                  => 'success',
                'error_message'           => null,
            ]);

            // ----- Attachments -----
            try {
                if ($sourceContactId) {
                    $atts = WixContactHelper::listContactAttachments($fromToken, $sourceContactId);
                    foreach ($atts as $att) {
                        $fileName = $att['fileName'] ?? null;
                        $mimeType = $att['mimeType'] ?? null;
                        $attId    = $att['id'] ?? null;
                        if (!$fileName || !$mimeType || !$attId) continue;

                        $file = WixContactHelper::downloadContactAttachment($fromToken, $sourceContactId, $attId);
                        if (!$file || empty($file['content'])) continue;

                        $uploadUrl = WixContactHelper::generateAttachmentUploadUrl($toToken, $destContactId, $fileName, $mimeType);
                        $url = $uploadUrl['uploadUrl'] ?? null;
                        if ($url) {
                            try {
                                $client = new \GuzzleHttp\Client();
                                $respUp = $client->put($url, [
                                    'body'    => $file['content'],
                                    'headers' => ['Content-Type' => $mimeType]
                                ]);
                                if (!in_array($respUp->getStatusCode(), [200, 201])) {
                                    $errors[] = "Attachment upload failed ($fileName) for contact $destContactId";
                                }
                            } catch (\Throwable $e) {
                                $errors[] = "Attachment upload exception ($fileName): " . $e->getMessage();
                            }
                        }
                    }
                }
            } catch (\Throwable $e) {
                $errors[] = "Attachments step failed for contact $destContactId: " . $e->getMessage();
            }

            // ----- Light Member create + "About" (if source had a member linked to this contact) -----
            if ($sourceContactId) {
                $srcMember = WixMemberHelper::findMemberByContactId($fromToken, $sourceContactId);
                if ($srcMember) {
                    $oldMemberId = $srcMember['id'] ?? null;
                    $loginEmail  = $srcMember['loginEmail'] ?? ($email ?? null);
                    $nickname    = $srcMember['profile']['nickname'] ?? ($name ?? null);

                    $dstMember = $loginEmail ? WixMemberHelper::findMemberByEmail($toToken, $loginEmail) : null;
                    $newMemberId = $dstMember['id'] ?? null;

                    if (!$newMemberId) {
                        $created = WixMemberHelper::createMember($toToken, [
                            'loginEmail' => $loginEmail,
                            'profile'    => ['nickname' => $nickname],
                        ]);
                        $newMemberId = $created['member']['id'] ?? null;
                    }

                    if ($oldMemberId && $newMemberId) {
                        $oldMemberIdToNewMemberId[$oldMemberId] = $newMemberId;

                        // Upsert "About"
                        $about = WixMemberHelper::getMemberAbout($fromToken, $oldMemberId);
                        if ($about && !empty($about['content'])) {
                            WixMemberHelper::upsertMemberAbout($toToken, $newMemberId, $about);
                        }
                    }
                }
            }

            $processed++;
            if ($processed % 50 === 0) {
                WixHelper::log('Migrate Contacts', "Progress: {$processed}/".count($contacts)." processed; imported={$imported}", 'debug');
            }
        }

        // 5) Pass 2 — Members: restore badges & following graph (only for members that were mapped)
        foreach ($oldMemberIdToNewMemberId as $oldId => $newId) {
            // Badges
            try {
                $badges = WixMemberHelper::getMemberBadges($fromToken, $oldId);
                foreach ($badges as $badge) {
                    $badgeKey = $badge['badgeKey'] ?? null;
                    if ($badgeKey) {
                        WixMemberHelper::assignBadge($toToken, $newId, $badgeKey);
                    }
                }
            } catch (\Throwable $e) {
                $errors[] = "Badges copy failed for member $newId: " . $e->getMessage();
            }

            // Following (make newId follow the mapped targets)
            try {
                $following = WixMemberHelper::getFollowersOrFollowing($fromToken, $oldId, 'following');
                foreach ($following as $f) {
                    $followedOldId = $f['id'] ?? null;
                    if ($followedOldId && isset($oldMemberIdToNewMemberId[$followedOldId])) {
                        $targetNewId = $oldMemberIdToNewMemberId[$followedOldId];
                        WixMemberHelper::followMember($toToken, $newId, $targetNewId);
                    }
                }
            } catch (\Throwable $e) {
                $errors[] = "Following copy failed for member $newId: " . $e->getMessage();
            }
        }

        WixHelper::log(
            'Migrate Contacts',
            "Completed contact migration: imported={$imported}, errors=" . count($errors),
            count($errors) ? 'warn' : 'success'
        );

        return [$imported, $errors];
    }




    // ---------------------------------
    // Orders
    // ---------------------------------
    protected function migrateOrders($fromStore, $toStore)
    {
        $errors       = [];
        $imported     = 0;

        $userId       = Auth::id() ?: 1;
        $fromToken    = WixHelper::getAccessToken($fromStore->instance_id);
        $toToken      = WixHelper::getAccessToken($toStore->instance_id);
        $fromStoreId  = $fromStore->instance_id;
        $toStoreId    = $toStore->instance_id;

        if (!$fromToken || !$toToken) {
            $msg = "Could not get access token for one or both stores.";
            WixHelper::log('Migrate Orders', $msg, 'error');
            return [0, [$msg]];
        }

        // 1) Collect order IDs in source
        $idsRes = WixOrderHelper::listAllOrderIds($fromToken);
        if (!empty($idsRes['error'])) {
            WixHelper::log('Migrate Orders', "Order ID fetch failed: " . ($idsRes['error'] ?? 'unknown'), 'error');
            return [0, [$idsRes['error'] ?? 'Order ID fetch failed']];
        }
        $orderIds = $idsRes['ids'] ?? [];

        WixHelper::log('Migrate Orders', "Found ".count($orderIds)." order(s) to migrate {$fromStoreId} → {$toStoreId}.", 'info');

        $processed = 0;

        // 2) For each order ID, fetch full data and import
        foreach ($orderIds as $orderId) {
            $full = WixOrderHelper::getFullOrder($fromToken, $orderId);
            if (!$full || empty($full['order'])) {
                $errors[] = "Failed to fetch full order payload for source id {$orderId}";
                continue;
            }

            $order            = $full['order'];
            $sourceOrderId    = $order['id']     ?? null;
            $orderNumber      = $order['number'] ?? null;
            $fulfillmentsList = $full['fulfillments'] ?? []; // array of fulfillments
            $paymentsList     = $full['payments']     ?? []; // array of payments

            // Dedupe by migration log (skip if already success)
            $existingMig = WixOrderMigration::where([
                'user_id'         => $userId,
                'from_store_id'   => $fromStoreId,
                'to_store_id'     => $toStoreId,
                'source_order_id' => $sourceOrderId,
            ])->first();
            if ($existingMig && $existingMig->status === 'success') {
                continue;
            }

            // Upsert 'pending' row BEFORE attempt (target-aware)
            WixOrderMigration::updateOrCreate(
                [
                    'user_id'         => $userId,
                    'from_store_id'   => $fromStoreId,
                    'to_store_id'     => $toStoreId,
                    'source_order_id' => $sourceOrderId,
                ],
                [
                    'order_number'          => $orderNumber,
                    'destination_order_id'  => null,
                    'status'                => 'pending',
                    'error_message'         => null,
                ]
            );

            // ---- Prepare payload (strip system fields) ----
            unset(
                $order['id'], $order['number'], $order['createdDate'], $order['updatedDate'],
                $order['siteLanguage'], $order['isInternalOrderCreate'],
                $order['seenByAHuman'], $order['customFields'],
                $order['transactions'], $order['fulfillments']
            );

            if (!empty($order['lineItems'])) {
                foreach ($order['lineItems'] as &$lineItem) {
                    unset(
                        $lineItem['id'],
                        $lineItem['rootCatalogItemId'],
                        $lineItem['priceUndetermined'],
                        $lineItem['fixedQuantity'],
                        $lineItem['modifierGroups']
                    );
                }
                unset($lineItem);
            }

            if (isset($order['activities'])) {
                foreach ($order['activities'] as &$activity) unset($activity['id']);
                unset($activity);
            }

            // ---- Create order in target ----
            $create = WixOrderHelper::createOrder($toToken, $order);

            if (($create['ok'] ?? false) && !empty($create['id'])) {
                $destOrderId = $create['id'];
                $imported++;

                // Fulfillments
                foreach ($fulfillmentsList as $fulfillment) {
                    $fres = WixOrderHelper::createFulfillment($toToken, $destOrderId, $fulfillment);
                    if (!($fres['ok'] ?? false)) {
                        $errors[] = "Fulfillment failed for Order {$orderNumber} ({$destOrderId}): " . ($fres['error'] ?? 'unknown');
                    }
                }

                // Payments
                if (!empty($paymentsList)) {
                    $pres = WixOrderHelper::addPayments($toToken, $destOrderId, $paymentsList);
                    if (!($pres['ok'] ?? false)) {
                        $errors[] = "Payment import failed for Order {$orderNumber} ({$destOrderId}): " . ($pres['error'] ?? 'unknown');
                    }
                }

                // SUCCESS
                WixOrderMigration::updateOrCreate(
                    [
                        'user_id'         => $userId,
                        'from_store_id'   => $fromStoreId,
                        'to_store_id'     => $toStoreId,
                        'source_order_id' => $sourceOrderId,
                    ],
                    [
                        'order_number'          => $orderNumber,
                        'destination_order_id'  => $destOrderId,
                        'status'                => 'success',
                        'error_message'         => null,
                    ]
                );

                WixHelper::log('Migrate Orders', "Imported order {$orderNumber} → {$destOrderId}", 'success');
            } else {
                // FAILED
                $errMsg = "Create order failed for {$orderNumber}: status=" . ($create['status'] ?? 'n/a') . " error=" . ($create['error'] ?? 'n/a');

                WixOrderMigration::updateOrCreate(
                    [
                        'user_id'         => $userId,
                        'from_store_id'   => $fromStoreId,
                        'to_store_id'     => $toStoreId,
                        'source_order_id' => $sourceOrderId,
                    ],
                    [
                        'order_number'          => $orderNumber,
                        'destination_order_id'  => null,
                        'status'                => 'failed',
                        'error_message'         => $errMsg,
                    ]
                );

                $errors[] = $errMsg;
                WixHelper::log('Migrate Orders', $errMsg, 'error');
            }

            $processed++;
            if ($processed % 25 === 0) {
                WixHelper::log('Migrate Orders', "Progress: {$processed}/".count($orderIds)." processed; imported={$imported}", 'debug');
            }
        }

        return [$imported, $errors];
    }



    // ---------------------------------
    // COUPONS 
    // ---------------------------------
    protected function migrateCoupons($fromStore, $toStore) 
    {
        $errors      = [];
        $imported    = 0;

        $fromToken   = WixHelper::getAccessToken($fromStore->instance_id);
        $toToken     = WixHelper::getAccessToken($toStore->instance_id);
        $userId      = Auth::id() ?: 1;
        $fromStoreId = $fromStore->instance_id;
        $toStoreId   = $toStore->instance_id;

        if (!$fromToken || !$toToken) {
            $msg = "Could not get access token for one or both stores.";
            WixHelper::log('Migrate Coupons', $msg, 'error');
            return [0, [$msg]];
        }

        WixHelper::log('Migrate Coupons', "Start: from={$fromStoreId} → to={$toStoreId}", 'info');

        // Build ID maps from your migration logs (source -> destination)
        $collectionIdMap = WixCollectionMigration::where([
            'user_id'       => $userId,
            'from_store_id' => $fromStoreId,
            'to_store_id'   => $toStoreId,
            'status'        => 'success',
        ])->pluck('destination_collection_id', 'source_collection_id')->toArray();

        $productIdMap = WixProductMigration::where([
            'user_id'       => $userId,
            'from_store_id' => $fromStoreId,
            'to_store_id'   => $toStoreId,
            'status'        => 'success',
        ])->pluck('destination_product_id', 'source_product_id')->toArray();

        // 1) Export coupons from source
        $export  = WixCouponHelper::listAllCoupons($fromToken);
        $coupons = $export['coupons'] ?? [];
        WixHelper::log('Migrate Coupons', "Fetched coupons from source: " . count($coupons), 'info');

        foreach ($coupons as $coupon) {
            $spec = $coupon['specification'] ?? null;
            $code = $spec['code'] ?? null;
            $name = $spec['name'] ?? null;

            if (!$code || !$spec || !is_array($spec)) {
                $msg = "Invalid coupon payload: missing code/specification.";
                $errors[] = $msg;
                WixHelper::log('Migrate Coupons', $msg, 'error');
                continue;
            }

            // Skip if already SUCCESS for this from→to target
            $existingMig = WixCouponMigration::where([
                'user_id'            => $userId,
                'from_store_id'      => $fromStoreId,
                'to_store_id'        => $toStoreId,
                'source_coupon_code' => $code,
            ])->first();
            if ($existingMig && $existingMig->status === 'success') {
                WixHelper::log('Migrate Coupons', "Skip: {$code} already migrated (dest id={$existingMig->destination_coupon_id})", 'debug');
                continue;
            }

            // Upsert 'pending' before we attempt (keys include to_store_id per your unique index)
            WixCouponMigration::updateOrCreate([
                'user_id'            => $userId,
                'from_store_id'      => $fromStoreId,
                'to_store_id'        => $toStoreId,
                'source_coupon_code' => $code,
            ], [
                'source_coupon_name'     => $name,
                'destination_coupon_id'  => null,
                'status'                 => 'pending',
                'error_message'          => null,
            ]);

            // 2) Normalize spec (ensure required fields)
            $spec = WixCouponHelper::normalizeSpec($spec);

            // 3) Map scope entity IDs (collections/products). If unmappable, mark failed.
            $mapped = WixCouponHelper::mapScopeEntities($spec, $collectionIdMap, $productIdMap);
            if (!$mapped['ok']) {
                $msg = "Skipped coupon {$code}: {$mapped['reason']}";
                $errors[] = $msg;

                WixCouponMigration::updateOrCreate([
                    'user_id'            => $userId,
                    'from_store_id'      => $fromStoreId,
                    'to_store_id'        => $toStoreId,
                    'source_coupon_code' => $code,
                ], [
                    'source_coupon_name'     => $name,
                    'destination_coupon_id'  => null,
                    'status'                 => 'failed',
                    'error_message'          => $msg,
                ]);

                WixHelper::log('Migrate Coupons', $msg, 'warn');
                continue;
            }
            $spec = $mapped['spec'];

            // 4) Deduplicate by code in target
            $existingTarget = WixCouponHelper::findByCode($toToken, $code);
            if ($existingTarget && !empty($existingTarget['id'])) {
                $destId = $existingTarget['id'];

                WixCouponMigration::updateOrCreate([
                    'user_id'            => $userId,
                    'from_store_id'      => $fromStoreId,
                    'to_store_id'        => $toStoreId,
                    'source_coupon_code' => $code,
                ], [
                    'source_coupon_name'     => $name,
                    'destination_coupon_id'  => $destId,
                    'status'                 => 'success',
                    'error_message'          => null,
                ]);

                WixHelper::log('Migrate Coupons', "Coupon {$code} exists in target (id: {$destId}); marked success.", 'info');
                continue;
            }

            // 5) Create in target
            $create = WixCouponHelper::createCoupon($toToken, $spec);

            if (($create['ok'] ?? false) && !empty($create['id'])) {
                $imported++;

                WixCouponMigration::updateOrCreate([
                    'user_id'            => $userId,
                    'from_store_id'      => $fromStoreId,
                    'to_store_id'        => $toStoreId,
                    'source_coupon_code' => $code,
                ], [
                    'source_coupon_name'     => $name,
                    'destination_coupon_id'  => $create['id'],
                    'status'                 => 'success',
                    'error_message'          => null,
                ]);

                WixHelper::log('Migrate Coupons', "Imported coupon {$code} (id: {$create['id']})", 'success');
            } else {
                // If 400/409, double-check if it now exists (race/dup)
                if (!empty($create['status']) && in_array($create['status'], [400, 409])) {
                    $dup = WixCouponHelper::findByCode($toToken, $code);
                    if ($dup && !empty($dup['id'])) {
                        WixCouponMigration::updateOrCreate([
                            'user_id'            => $userId,
                            'from_store_id'      => $fromStoreId,
                            'to_store_id'        => $toStoreId,
                            'source_coupon_code' => $code,
                        ], [
                            'source_coupon_name'     => $name,
                            'destination_coupon_id'  => $dup['id'],
                            'status'                 => 'success',
                            'error_message'          => null,
                        ]);

                        WixHelper::log('Migrate Coupons', "Coupon {$code} existed; marked success (id: {$dup['id']}).", 'info');
                        continue;
                    }
                }

                $msg = "Failed to import coupon {$code}: status=" . ($create['status'] ?? 'null') . " error=" . ($create['error'] ?? 'n/a');
                $errors[] = $msg;

                WixCouponMigration::updateOrCreate([
                    'user_id'            => $userId,
                    'from_store_id'      => $fromStoreId,
                    'to_store_id'        => $toStoreId,
                    'source_coupon_code' => $code,
                ], [
                    'source_coupon_name'     => $name,
                    'destination_coupon_id'  => null,
                    'status'                 => 'failed',
                    'error_message'          => $msg,
                ]);

                WixHelper::log('Migrate Coupons', $msg, 'error');
            }
        }

        WixHelper::log('Migrate Coupons', "Done: to={$toStoreId} | imported={$imported} | errors=" . count($errors), count($errors) ? 'warn' : 'success');

        return [$imported, $errors];
    }

    // ---------------------------------
    // DISCOUNT RULES
    // ---------------------------------
    protected function migrateDiscountRules($fromStore, $toStore)
    {
        $errors      = [];
        $imported    = 0;

        $fromToken   = WixHelper::getAccessToken($fromStore->instance_id);
        $toToken     = WixHelper::getAccessToken($toStore->instance_id);
        $userId      = Auth::id() ?: 1;
        $fromStoreId = $fromStore->instance_id;
        $toStoreId   = $toStore->instance_id;

        if (!$fromToken || !$toToken) {
            $msg = "Could not get access token for one or both stores.";
            WixHelper::log('Migrate Discount Rules', $msg, 'error');
            return [0, [$msg]];
        }

        WixHelper::log('Migrate Discount Rules', "Start: from={$fromStoreId} → to={$toStoreId}", 'info');

        // 1) Export all discount rules from source (with paging)
        $export = WixDiscountRuleHelper::listAllRules($fromToken);
        $rules  = $export['discountRules'] ?? [];
        WixHelper::log('Migrate Discount Rules', "Fetched rules from source: " . count($rules), 'info');

        $processed = 0;
        $total = count($rules);

        // 2) Import each rule into target
        foreach ($rules as $rule) {
            $processed++;

            $sourceId = $rule['id']   ?? null;
            $ruleName = $rule['name'] ?? null;
            if (!$sourceId) {
                $errors[] = "Rule missing id: " . json_encode($rule);
                WixHelper::log('Migrate Discount Rules', "Rule missing id.", 'error');
                continue;
            }

            // Target-aware skip: only skip if already success for THIS destination
            $migration = WixDiscountRuleMigration::where([
                'user_id'        => $userId,
                'from_store_id'  => $fromStoreId,
                'to_store_id'    => $toStoreId,
                'source_rule_id' => $sourceId,
            ])->first();

            if ($migration && $migration->status === 'success') {
                WixHelper::log('Migrate Discount Rules', "Skip already-imported for target {$toStoreId}: '{$ruleName}' ({$sourceId})", 'debug');
                continue;
            }

            // Upsert pending before import (include to_store_id in key to match overwrite-per-target)
            WixDiscountRuleMigration::updateOrCreate([
                'user_id'        => $userId,
                'from_store_id'  => $fromStoreId,
                'to_store_id'    => $toStoreId,
                'source_rule_id' => $sourceId,
            ], [
                'source_rule_name'     => $ruleName,
                'destination_rule_id'  => null,
                'status'               => 'pending',
                'error_message'        => null,
            ]);

            // Remove system fields
            $toCreate = $rule;
            unset($toCreate['id']);

            // Create in target
            $res = WixDiscountRuleHelper::createRule($toToken, $toCreate);

            if (($res['ok'] ?? false) === true && !empty($res['id'])) {
                $imported++;

                WixDiscountRuleMigration::updateOrCreate([
                    'user_id'        => $userId,
                    'from_store_id'  => $fromStoreId,
                    'to_store_id'    => $toStoreId,
                    'source_rule_id' => $sourceId,
                ], [
                    'source_rule_name'     => $ruleName,
                    'destination_rule_id'  => $res['id'],
                    'status'               => 'success',
                    'error_message'        => null,
                ]);

                WixHelper::log('Migrate Discount Rules', "Imported rule '{$ruleName}' (new ID: {$res['id']})", 'success');
            } else {
                $errorMsg = json_encode(['sent' => $toCreate, 'response' => $res]);
                $errors[] = $errorMsg;

                WixDiscountRuleMigration::updateOrCreate([
                    'user_id'        => $userId,
                    'from_store_id'  => $fromStoreId,
                    'to_store_id'    => $toStoreId,
                    'source_rule_id' => $sourceId,
                ], [
                    'source_rule_name'     => $ruleName,
                    'destination_rule_id'  => null,
                    'status'               => 'failed',
                    'error_message'        => $errorMsg,
                ]);

                WixHelper::log('Migrate Discount Rules', "Failed to import '{$ruleName}' {$errorMsg}", 'error');
            }

            // Optional progress log every 50 rules (parity with media)
            if ($processed % 50 === 0) {
                WixHelper::log('Migrate Discount Rules', "Progress: {$processed}/{$total}", 'debug');
            }
        }

        WixHelper::log('Migrate Discount Rules', "Done: to={$toStoreId} | imported={$imported} | errors=" . count($errors), count($errors) ? 'warn' : 'success');

        return [$imported, $errors];
    }


    // ---------------------------------
    // MEDIA
    // ---------------------------------
    protected function migrateMedia($fromStore, $toStore)
    {
        $userId      = Auth::id() ?: 1;
        $fromToken   = WixHelper::getAccessToken($fromStore->instance_id);
        $toToken     = WixHelper::getAccessToken($toStore->instance_id);
        $fromStoreId = $fromStore->instance_id;
        $toStoreId   = $toStore->instance_id;

        $importedFolders = 0;
        $importedFiles   = 0;
        $errors          = [];

        if (!$fromToken || !$toToken) {
            $msg = "Could not get access token for one or both stores. fromStore={$fromStoreId}, toStore={$toStoreId}";
            WixHelper::log('Migrate Media', $msg, 'error');
            return [[0, 0], [$msg]];
        }

        WixHelper::log('Migrate Media', "Start: from={$fromStoreId} → to={$toStoreId}", 'info');

        // 1) Get all folders from source
        $folders = WixMediaHelper::listAllFolders($fromToken);
        if ($folders === null) {
            $msg = "Failed to fetch folders from source store {$fromStoreId}.";
            WixHelper::log('Migrate Media', $msg, 'error');
            return [[0, 0], [$msg]];
        }
        WixHelper::log('Migrate Media', "Fetched folders: count=" . count($folders), 'debug');

        // Include synthetic root for uniform handling
        $folders[] = [
            'id'             => 'media-root',
            'displayName'    => 'Root',
            'parentFolderId' => null,
            'createdDate'    => null,
            'updatedDate'    => null,
            'state'          => 'OK',
            'namespace'      => 'NO_NAMESPACE'
        ];

        // 2) Build file map for each folder + mark 'pending'
        $filesByFolder = [];
        foreach ($folders as $folder) {
            $folderId   = $folder['id'];
            $folderName = $folder['displayName'] ?? 'Unnamed';

            try {
                $files = WixMediaHelper::listAllFilesByFolder($fromToken, $folderId);
                $filesByFolder[$folderId] = $files;

                WixHelper::log('Migrate Media', "Folder listed: '{$folderName}' ({$folderId}) files=" . count($files), 'debug');

                WixMediaMigration::updateOrCreate([
                    'user_id'        => $userId,
                    'from_store_id'  => $fromStoreId,
                    'to_store_id'    => null,
                    'folder_id'      => $folderId,
                ], [
                    'folder_name'     => $folderName,
                    'total_files'     => count($files),
                    'imported_files'  => 0,
                    'status'          => 'pending',
                    'error_message'   => null
                ]);
            } catch (\Throwable $e) {
                $err = "Files list failed for folder {$folderName} ({$folderId}): " . $e->getMessage();
                $errors[] = $err;
                WixHelper::log('Migrate Media', $err, 'error');

                WixMediaMigration::updateOrCreate([
                    'user_id'        => $userId,
                    'from_store_id'  => $fromStoreId,
                    'to_store_id'    => null,
                    'folder_id'      => $folderId,
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

        // 3) Import into target — folder by folder
        foreach ($folders as $folder) {
            $folderId       = $folder['id'];
            $folderName     = $folder['displayName'] ?? 'Unnamed Folder';
            $files          = $filesByFolder[$folderId] ?? [];
            $originalFolder = $folderId;
            $targetFolderId = 'media-root';
            $imported       = 0;
            $failed         = [];

            WixHelper::log('Migrate Media', "Import begin: '{$folderName}' files=" . count($files), 'debug');

            // Create/ensure folder (skip root)
            if ($folderId !== 'media-root') {
                $folderCreate = WixMediaHelper::ensureFolder($toToken, $folderName, 'media-root');
                if (!($folderCreate['ok'] ?? false)) {
                    $msg = "Failed to create/ensure folder '{$folderName}': " . ($folderCreate['error'] ?? 'unknown');
                    $errors[] = $msg;
                    WixHelper::log('Migrate Media', $msg, 'error');

                    WixMediaMigration::updateOrCreate([
                        'user_id'        => $userId,
                        'from_store_id'  => $fromStoreId,
                        'folder_id'      => $originalFolder,
                    ], [
                        'to_store_id'     => $toStoreId,
                        'folder_name'     => $folderName,
                        'total_files'     => count($files),
                        'imported_files'  => 0,
                        'status'          => 'failed',
                        'error_message'   => $msg
                    ]);
                    continue;
                }
                $targetFolderId = $folderCreate['id'];
                $importedFolders++;
                WixHelper::log('Migrate Media', "Folder ensured in target: '{$folderName}' → {$targetFolderId}", 'info');
            }

            // Import files (batch for politeness with API)
            $batchSize = 50;
            for ($i = 0; $i < count($files); $i += $batchSize) {
                $batch = array_slice($files, $i, $batchSize);

                foreach ($batch as $file) {
                    $url         = $file['url'] ?? null;
                    $displayName = $file['displayName'] ?? 'Imported_File';
                    $mimeType    = $file['mimeType'] ?? null;

                    if (!$url) {
                        $failed[] = $displayName;
                        continue;
                    }

                    $res = WixMediaHelper::importFile($toToken, $url, $displayName, $targetFolderId, $mimeType);
                    if (($res['ok'] ?? false) === true) {
                        $imported++;
                        $importedFiles++;
                    } else {
                        $failed[] = $displayName;
                    }
                }

                WixHelper::log('Migrate Media', "Folder '{$folderName}': progress {$imported}/" . count($files), 'debug');
            }

            $status = $imported === count($files) ? 'success' : 'failed';

            WixMediaMigration::updateOrCreate([
                'user_id'        => $userId,
                'from_store_id'  => $fromStoreId,
                'folder_id'      => $originalFolder,
            ], [
                'to_store_id'     => $toStoreId,
                'folder_name'     => $folderName,
                'total_files'     => count($files),
                'imported_files'  => $imported,
                'status'          => $status,
                'error_message'   => count($failed) ? json_encode($failed) : null
            ]);

            WixHelper::log(
                'Migrate Media',
                "Import end: '{$folderName}' imported {$imported}/" . count($files) . " status={$status}" . (count($failed) ? " failed=" . count($failed) : ''),
                count($failed) ? 'warn' : 'info'
            );
        }

        WixHelper::log(
            'Migrate Media',
            "Completed: from={$fromStoreId} → to={$toStoreId} | folders={$importedFolders}, files={$importedFiles}, errors=" . count($errors),
            $errors ? 'warn' : 'success'
        );

        return [[$importedFolders, $importedFiles], $errors];
    }



}
