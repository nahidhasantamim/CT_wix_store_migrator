<?php

namespace App\Http\Controllers;

use App\Models\WixStore;
use App\Helpers\WixHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class WixContactController extends Controller
{
    // Export all contacts as JSON
    public function export(WixStore $store)
    {
        WixHelper::log('Export Contacts', "Export started for store: $store->store_name", 'info');

        $accessToken = WixHelper::getAccessToken($store->instance_id);
        if (!$accessToken) {
            WixHelper::log('Export Contacts', "Failed: Could not get access token.", 'error');
            return response()->json(['error' => 'Could not get Wix access token.'], 401);
        }

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
                'Authorization' => $accessToken,
                'Content-Type'  => 'application/json'
            ])->get('https://www.wixapis.com/contacts/v4/contacts', $query);

            WixHelper::log('Export Contacts', 'Query status: '.$response->status().', offset: '.$offset, 'info');

            $data = $response->json();
            if (!empty($data['contacts'])) {
                $contacts = array_merge($contacts, $data['contacts']);
            }
            $count = $data['pagingMetadata']['count'] ?? 0;
            $total = $data['pagingMetadata']['total'] ?? 0;
            $offset += $count;
        } while ($count > 0 && $offset < $total);

        WixHelper::log('Export Contacts', "Exported ".count($contacts)." contacts.", 'success');

        return response()->streamDownload(function() use ($contacts) {
            echo json_encode($contacts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }, 'contacts.json', [
            'Content-Type' => 'application/json'
        ]);
    }

    // Import contacts from JSON
    public function import(Request $request, WixStore $store)
    {
        WixHelper::log('Import Contacts', "Import started for store: $store->store_name", 'info');

        $accessToken = WixHelper::getAccessToken($store->instance_id);
        if (!$accessToken) {
            WixHelper::log('Import Contacts', "Failed: Could not get access token.", 'error');
            return back()->with('error', 'Could not get Wix access token.');
        }

        if (!$request->hasFile('contacts_json')) {
            WixHelper::log('Import Contacts', "No file uploaded.", 'error');
            return back()->with('error', 'No file uploaded.');
        }

        $file = $request->file('contacts_json');
        $json = file_get_contents($file->getRealPath());
        $contacts = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($contacts)) {
            WixHelper::log('Import Contacts', "Uploaded file is not valid JSON.", 'error');
            return back()->with('error', 'Uploaded file is not valid JSON.');
        }

        // Helper for deep cleaning empty fields
        $cleanEmpty = function($array) use (&$cleanEmpty) {
            foreach ($array as $k => $v) {
                if (is_array($v)) {
                    $array[$k] = $cleanEmpty($v);
                    if ($array[$k] === [] || $array[$k] === null) unset($array[$k]);
                } elseif ($v === [] || $v === null) {
                    unset($array[$k]);
                }
            }
            return $array;
        };

        $imported = 0;
        $errors = [];

        foreach ($contacts as $contact) {
            // Remove top-level system keys
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

            $allowedInfoKeys = [
                'name', 'emails', 'phones', 'addresses', 'company', 'jobTitle',
                'birthdate', 'locale', 'labelKeys', 'extendedFields', 'locations'
            ];
            $filteredInfo = [];
            foreach ($allowedInfoKeys as $key) {
                if (isset($info[$key])) {
                    $filteredInfo[$key] = $info[$key];
                }
            }

            // Remove id fields from nested items
            if (!empty($filteredInfo['emails']['items'])) {
                foreach ($filteredInfo['emails']['items'] as &$email) unset($email['id']);
            }
            if (!empty($filteredInfo['phones']['items'])) {
                foreach ($filteredInfo['phones']['items'] as &$phone) unset($phone['id']);
            }
            if (!empty($filteredInfo['addresses']['items'])) {
                foreach ($filteredInfo['addresses']['items'] as &$address) unset($address['id']);
            }

            // Only custom.* allowed in extendedFields
            if (!empty($filteredInfo['extendedFields']['items'])) {
                $filteredInfo['extendedFields']['items'] = array_filter(
                    $filteredInfo['extendedFields']['items'],
                    function ($key) { return strpos($key, 'custom.') === 0; },
                    ARRAY_FILTER_USE_KEY
                );
                if (empty($filteredInfo['extendedFields']['items'])) {
                    unset($filteredInfo['extendedFields']);
                }
            }

            $filteredInfo = $cleanEmpty($filteredInfo);

            // Must have at least name, email, or phone
            $hasMinimal = !empty($filteredInfo['name']) || !empty($filteredInfo['emails']['items']) || !empty($filteredInfo['phones']['items']);
            if (!$hasMinimal) {
                $errors[] = "Contact missing required fields (name/email/phone)";
                continue;
            }

            $body = [
                'info' => $filteredInfo,
                // 'allowDuplicates' => true, // Uncomment to allow duplicates
            ];

            $response = Http::withHeaders([
                'Authorization' => $accessToken,
                'Content-Type'  => 'application/json'
            ])->post('https://www.wixapis.com/contacts/v4/contacts', $body);

            $result = $response->json();

            if ($response->status() === 201 && isset($result['contact']['id'])) {
                $imported++;
                WixHelper::log('Import Contacts', "Created contact: " . ($filteredInfo['name']['first'] ?? 'Unknown'), 'success');
            } else {
                $errMsg = "Status: " . $response->status() . "; Response: " . json_encode($result ?: $response->body());
                $errors[] = $errMsg;
                WixHelper::log('Import Contacts', "Failed to import contact: " . $errMsg, 'error');
            }
        }

        if ($imported > 0) {
            WixHelper::log('Import Contacts', "Import finished: $imported contact(s) imported." . (count($errors) ? " Some errors: " . implode("; ", $errors) : ""), 'success');
            return back()->with('success', "$imported contact(s) imported." . (count($errors) ? " Some errors: " . implode("; ", $errors) : ""));
        } else {
            WixHelper::log('Import Contacts', "No contacts imported. Errors: " . implode("; ", $errors), 'error');
            return back()->with('error', 'No contacts imported. Errors: ' . implode("; ", $errors));
        }
    }



}
