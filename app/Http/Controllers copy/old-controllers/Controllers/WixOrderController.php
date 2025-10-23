<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use App\Models\WixStore;
use App\Helpers\WixHelper;

class WixOrderController extends Controller
{
    // EXPORT ORDERS
    public function export(WixStore $store, Request $request)
    {
        WixHelper::log('Export Orders', "Export started for store: $store->store_name", 'info');
    
        $accessToken = WixHelper::getAccessToken($store->instance_id);
        if (!$accessToken) {
            WixHelper::log('Export Orders', "Failed: Could not get access token.", 'error');
            return response()->json(['error' => 'Could not get Wix access token.'], 401);
        }
    
        $query = [
            "sort" => '[{"dateCreated":"asc"}]',
            "paging" => [
                "limit" => 100, // Can be increased as needed, Wix default max is usually 100
                "offset" => 0
            ]
        ];
    
        $orderIds = [];
        do {
            $body = ['query' => $query];
            $response = Http::withHeaders([
                'Authorization' => $accessToken,
                'Content-Type'  => 'application/json'
            ])->post('https://www.wixapis.com/stores/v2/orders/query', $body);
    
            $data = $response->json();
            $ordersPage = $data['orders'] ?? [];
            foreach ($ordersPage as $orderSummary) {
                if (!empty($orderSummary['id'])) {
                    $orderIds[] = $orderSummary['id'];
                }
            }
            $count = count($ordersPage);
            $query['paging']['offset'] += $count;
        } while ($count > 0);
    
        // Fetch full details for each order using the /ecom/v1/orders/{id} endpoint
        $fullOrders = [];
        foreach ($orderIds as $orderId) {
            $orderResp = Http::withHeaders([
                'Authorization' => $accessToken,
                'Content-Type'  => 'application/json'
            ])->get("https://www.wixapis.com/ecom/v1/orders/{$orderId}");
            $order = $orderResp->json('order');
            if ($order) {
                $fullOrders[] = $order;
            }
        }
    
        $count = count($fullOrders);
        WixHelper::log('Export Orders', "Exported $count full orders.", 'success');
    
        return response()->streamDownload(function() use ($fullOrders) {
            echo json_encode($fullOrders, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }, 'orders.json', [
            'Content-Type' => 'application/json'
        ]);
    }

    // Order mapping
    // protected function mapOrderForWixImport($order)
    // {
    //     // Helper: Format Amounts
    //     $fmt = function($amount) {
    //         $amt = is_numeric($amount) ? number_format($amount, 2, '.', '') : (string)$amount;
    //         return ['amount' => (string)$amt, 'formattedAmount' => $amt];
    //     };

    //     // Buyer Info
    //     $buyerInfo = [
    //         'contactId' => $order['buyerInfo']['contactId'] ?? $order['buyerInfo']['id'] ?? '',
    //         'email' => $order['buyerInfo']['email'] ?? '',
    //         'firstName' => $order['buyerInfo']['firstName'] ?? '',
    //         'lastName' => $order['buyerInfo']['lastName'] ?? '',
    //         'phone' => $order['buyerInfo']['phone'] ?? '',
    //         // Additional fields
    //         'type' => $order['buyerInfo']['type'] ?? 'CONTACT',
    //         'identityType' => $order['buyerInfo']['identityType'] ?? 'CONTACT',
    //     ];
    //     if (!empty($order['buyerInfo']['memberId'])) {
    //         $buyerInfo['memberId'] = $order['buyerInfo']['memberId'];
    //     }

    //     // Price Summary
    //     $totals = $order['totals'] ?? [];
    //     $priceSummary = [
    //         'subtotal' => $fmt($totals['subtotal'] ?? 0),
    //         'shipping' => $fmt($totals['shipping'] ?? 0),
    //         'tax' => $fmt($totals['tax'] ?? 0),
    //         'discount' => $fmt($totals['discount'] ?? 0),
    //         'total' => $fmt($totals['total'] ?? 0),
    //     ];

    //     // Billing Info
    //     $phone = $order['billingInfo']['address']['phone'] ?? '';
    //     $phone = preg_match('/^\+\d{10,15}$/', $phone) ? $phone : '+880000000000';
    //     $billingInfo = [
    //         'address' => [
    //             'country' => $order['billingInfo']['address']['country'] ?? 'BD',
    //             'city' => $order['billingInfo']['address']['city'] ?? '',
    //             'postalCode' => $order['billingInfo']['address']['postalCode'] ?? '',
    //             'addressLine' => $order['billingInfo']['address']['addressLine'] ?? '',
    //             'countryFullname' => $order['billingInfo']['address']['countryFullname'] ?? '',
    //             'subdivision' => $order['billingInfo']['address']['subdivision'] ?? '',
    //             'subdivisionFullname' => $order['billingInfo']['address']['subdivisionFullname'] ?? '',
    //         ],
    //         'contactDetails' => [
    //             'firstName' => $order['billingInfo']['address']['fullName']['firstName'] ?? '',
    //             'lastName'  => $order['billingInfo']['address']['fullName']['lastName'] ?? '',
    //             'phone'     => $phone,
    //             'company'   => $order['billingInfo']['address']['company'] ?? '',
    //         ]
    //     ];

    //     // Line Items (with media, product, tax, etc)
    //     $lineItems = [];
    //     foreach ($order['lineItems'] ?? [] as $item) {
    //         $appId = $item['catalogReference']['appId'] ?? '1380b703-ce81-ff05-f115-39571d94dfcd';
    //         $taxDetails = $item['taxDetails'] ?? [
    //             'taxRate' => '0',
    //             'totalTax' => [
    //                 'amount' => '0.00',
    //                 'formattedAmount' => '0.00'
    //             ]
    //         ];

    //         // --- Media ---
    //         $media = null;
    //         if (!empty($item['mediaItem'])) {
    //             $mediaItem = $item['mediaItem'];
    //             $media = [
    //                 'id' => $mediaItem['id'] ?? null,
    //                 'url' => $mediaItem['url'] ?? null,
    //                 'mediaType' => $mediaItem['mediaType'] ?? 'IMAGE',
    //                 'width' => $mediaItem['width'] ?? null,
    //                 'height' => $mediaItem['height'] ?? null,
    //                 // Add more fields if needed
    //             ];
    //         }

    //         $lineItem = [
    //             'quantity' => (int)($item['quantity'] ?? 1),
    //             'productName' => ['original' => $item['name'] ?? $item['productName']['original'] ?? ''],
    //             'catalogReference' => [
    //                 'catalogItemId' => $item['productId'] ?? $item['catalogReference']['catalogItemId'] ?? '',
    //                 'appId' => $appId,
    //             ],
    //             'price' => $fmt($item['price'] ?? $item['price']['amount'] ?? 0),
    //             'totalPriceBeforeTax' => $fmt($item['totalPriceBeforeTax']['amount'] ?? $item['price'] ?? 0),
    //             'totalPriceAfterTax'  => $fmt($item['totalPriceAfterTax']['amount'] ?? $item['price'] ?? 0),
    //             'itemType' => ['preset' => $item['lineItemType'] ?? $item['itemType']['preset'] ?? 'PHYSICAL'],
    //             'paymentOption' => $item['paymentOption'] ?? 'FULL_PAYMENT_ONLINE',
    //             'taxDetails' => $taxDetails,
    //         ];

    //         if ($media) {
    //             $lineItem['image'] = $media;
    //         }

    //         // Digital file (if digital product)
    //         if (($lineItem['itemType']['preset'] ?? null) === 'DIGITAL' && !empty($item['digitalFile'])) {
    //             $lineItem['digitalFile'] = $item['digitalFile'];
    //         }

    //         // Physical properties (SKU, weight, etc)
    //         $physical = [];
    //         if (!empty($item['sku'])) $physical['sku'] = $item['sku'];
    //         if (!empty($item['weight'])) $physical['weight'] = $item['weight'];
    //         if (!empty($item['shippable'])) $physical['shippable'] = $item['shippable'];
    //         if ($physical) $lineItem['physicalProperties'] = $physical;

    //         // Custom fields/options
    //         if (!empty($item['options'])) {
    //             $lineItem['options'] = $item['options'];
    //         }
    //         if (!empty($item['customTextFields'])) {
    //             $lineItem['customTextFields'] = $item['customTextFields'];
    //         }

    //         // Subscription info (if applicable)
    //         if (!empty($item['subscriptionInfo'])) {
    //             $lineItem['subscriptionInfo'] = $item['subscriptionInfo'];
    //         }

    //         // Extended fields (custom fields)
    //         if (!empty($item['extendedFields'])) {
    //             $lineItem['extendedFields'] = $item['extendedFields'];
    //         }

    //         $lineItems[] = $lineItem;
    //     }

    //     // -- SHIPPING (if you have shipping info in your order structure) --
    //     $shippingInfo = [];
    //     if (!empty($order['shippingInfo'])) {
    //         $shippingInfo = $order['shippingInfo'];
    //     }

    //     // --- MAIN ORDER STRUCTURE ---
    //     $result = [
    //         'buyerInfo' => $buyerInfo,
    //         'priceSummary' => $priceSummary,
    //         'billingInfo' => $billingInfo,
    //         'lineItems' => $lineItems,
    //         'currency' => $order['currency'] ?? 'BDT',
    //         'weightUnit' => $order['weightUnit'] ?? 'KG',
    //         'paymentStatus' => $order['paymentStatus'] ?? 'NOT_PAID',
    //         'status' => $order['status'] ?? 'APPROVED',
    //         'channelInfo' => [
    //             'channelType' => $order['channelInfo']['channelType'] ?? 'WEB',
    //         ],
    //         'archived' => $order['archived'] ?? false,
    //         'buyerLanguage' => $order['buyerLanguage'] ?? 'en',
    //         'fulfillmentStatus' => $order['fulfillmentStatus'] ?? 'NOT_FULFILLED',
    //     ];

    //     // Add optional fields if available in input
    //     $optionalFields = [
    //         'purchasedDate', 'createdDate', 'lastUpdated', 'tags', 'privateTags',
    //         'appliedDiscounts', 'shippingInfo', 'customFields', 'extendedFields',
    //         'recipientInfo', 'businessLocation', 'activities', 'fulfillments', 'refunds'
    //     ];
    //     foreach ($optionalFields as $field) {
    //         if (!empty($order[$field])) {
    //             $result[$field] = $order[$field];
    //         }
    //     }

    //     // If you have a siteLanguage in export, you can add it here as well
    //     if (!empty($order['siteLanguage'])) {
    //         $result['siteLanguage'] = $order['siteLanguage'];
    //     }

    //     return $result;
    // }


    // IMPORT ORDERS
    public function import(Request $request, WixStore $store)
    {
        WixHelper::log('Import Orders', "Import started for store: $store->store_name", 'info');
    
        $accessToken = WixHelper::getAccessToken($store->instance_id);
        if (!$accessToken) {
            WixHelper::log('Import Orders', "Failed: Could not get access token.", 'error');
            return back()->with('error', 'Could not get Wix access token.');
        }
    
        if (!$request->hasFile('orders_json')) {
            WixHelper::log('Import Orders', "No file uploaded.", 'error');
            return back()->with('error', 'No file uploaded.');
        }
    
        $file = $request->file('orders_json');
        $json = file_get_contents($file->getRealPath());
        $orders = json_decode($json, true);
    
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($orders)) {
            WixHelper::log('Import Orders', "Uploaded file is not valid JSON.", 'error');
            return back()->with('error', 'Uploaded file is not valid JSON.');
        }
    
        // Sort orders by createdDate ASC (oldest first) to preserve original order
        usort($orders, function ($a, $b) {
            return strtotime($a['createdDate'] ?? $a['purchasedDate'] ?? '') <=> strtotime($b['createdDate'] ?? $b['purchasedDate'] ?? '');
        });
    
        $imported = 0;
        $errors = [];
    
        foreach ($orders as $order) {
            // Remove system/read-only fields
            unset(
                $order['id'],
                $order['number'],
                $order['createdDate'],
                $order['updatedDate'],
                $order['siteLanguage'],
                $order['isInternalOrderCreate'],
                $order['seenByAHuman'],
                $order['customFields'] // Include this only if you want to migrate customFields
            );
    
            // Clean lineItems for any unwanted fields
            if (!empty($order['lineItems'])) {
                foreach ($order['lineItems'] as &$lineItem) {
                    unset($lineItem['id'], $lineItem['rootCatalogItemId'], $lineItem['priceUndetermined'], $lineItem['fixedQuantity'], $lineItem['modifierGroups']);
                }
            }
    
            // Remove any extra system fields from balanceSummary, activities, etc if needed
            if (isset($order['balanceSummary'])) {
                // Optionally remove fields not needed on import
            }
            if (isset($order['activities'])) {
                foreach ($order['activities'] as &$activity) {
                    unset($activity['id']); // Remove ID if present
                }
            }
    
            // Compose the request body according to Wix API
            $body = [
                'order' => $order
            ];
    
            $response = Http::withHeaders([
                'Authorization' => $accessToken,
                'Content-Type'  => 'application/json'
            ])->post('https://www.wixapis.com/ecom/v1/orders', $body);
    
            $result = $response->json();
    
            if ($response->status() === 200 && isset($result['order']['id'])) {
                $imported++;
                WixHelper::log('Import Orders', "Imported order: " . $result['order']['id'], 'success');
            } else {
                $errors[] = json_encode([
                    'sent'     => $body,
                    'status'   => $response->status(),
                    'response' => $result,
                    'raw_body' => $response->body(),
                ]);
                WixHelper::log('Import Orders', "Failed to import: " . json_encode($result), 'error');
            }
        }
    
        if ($imported > 0) {
            WixHelper::log('Import Orders', "Imported $imported order(s). Errors: " . implode("; ", $errors), count($errors) ? 'warning' : 'success');
            return back()->with('success', "$imported order(s) imported." . (count($errors) ? " Some errors: " . implode("; ", $errors) : ""));
        } else {
            WixHelper::log('Import Orders', "No orders imported. Errors: " . implode("; ", $errors), 'error');
            return back()->with('error', 'No orders imported. Errors: ' . implode("; ", $errors));
        }
    }
}

