<?php

namespace App\Http\Controllers;

use App\Models\WixStore;
use App\Helpers\WixHelper;
use App\Models\WixContactMigration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class WixContactController extends Controller
{
    // ========================================================= Automatic Migrator =========================================================
    public function migrateAuto(Request $request)
    {
        $request->validate([
            'from_store'           => 'required|string',
            'to_store'             => 'required|string|different:from_store',
            'max'                  => 'nullable|integer|min:1',
            'include_members'      => 'nullable|boolean',
            'include_attachments'  => 'nullable|boolean',
            'default_sub_status'   => 'nullable|string|in:SUBSCRIBED,UNSUBSCRIBED,NOT_SET',
        ]);

        $userId        = Auth::id() ?: 1;
        $fromStoreId   = (string) $request->input('from_store');
        $toStoreId     = (string) $request->input('to_store');
        $max           = (int) ($request->input('max', 0));
        $copyMembers   = (bool) $request->boolean('include_members', true);
        $copyAttach    = (bool) $request->boolean('include_attachments', true);
        $defaultSub    = strtoupper((string) ($request->input('default_sub_status', 'SUBSCRIBED')));

        $fromStore = WixStore::where('instance_id', $fromStoreId)->first();
        $toStore   = WixStore::where('instance_id', $toStoreId)->first();
        $fromLabel = $fromStore?->store_name ?: $fromStoreId;
        $toLabel   = $toStore?->store_name   ?: $toStoreId;

        WixHelper::log('Auto Contacts Migration', "Start: {$fromLabel} → {$toLabel}", 'info');

        // Tokens
        $fromToken = WixHelper::getAccessToken($fromStoreId);
        $toToken   = WixHelper::getAccessToken($toStoreId);
        if (!$fromToken || !$toToken) {
            WixHelper::log('Auto Contacts Migration', 'Missing access token(s).', 'error');
            return back()->with('error', 'Could not get Wix access token(s).');
        }

        // Pull source contacts (FULL)
        $contacts = $this->getContactsFromWix($fromToken);
        if (isset($contacts['error'])) {
            WixHelper::log('Auto Contacts Migration', "Source API error: ".$contacts['raw'], 'error');
            return back()->with('error', 'Failed to fetch contacts from source.');
        }
        if ($max > 0) { $contacts = array_slice($contacts, 0, $max); }

        // Sort oldest → newest (robust resolver)
        $createdAtMillis = function (array $item): int {
            foreach (['createdDate','dateCreated','createdAt','creationDate','date_created'] as $k) {
                if (array_key_exists($k, $item)) {
                    $v = $item[$k];
                    if (is_numeric($v)) return (int)$v;
                    if (is_string($v)) { $ts = strtotime($v); if ($ts !== false) return $ts * 1000; }
                }
            }
            foreach ([['audit','createdDate'], ['audit','dateCreated'], ['metadata','createdDate'], ['metadata','dateCreated']] as $path) {
                $cur = $item;
                foreach ($path as $p) { if (!is_array($cur) || !array_key_exists($p, $cur)) { $cur=null; break; } $cur=$cur[$p]; }
                if ($cur !== null) {
                    if (is_numeric($cur)) return (int)$cur;
                    if (is_string($cur)) { $ts = strtotime($cur); if ($ts !== false) return $ts * 1000; }
                }
            }
            return PHP_INT_MAX;
        };
        usort($contacts, fn($a, $b) => $createdAtMillis($a) <=> $createdAtMillis($b));

        // Build label display map from SOURCE (key -> displayName) for any labels present
        $allLabelKeys = [];
        foreach ($contacts as $c) if (!empty($c['labelKeys'])) { $allLabelKeys = array_merge($allLabelKeys, $c['labelKeys']); }
        $allLabelKeys = array_values(array_unique($allLabelKeys));
        $sourceLabelKeyToName = [];
        foreach ($allLabelKeys as $lk) {
            $lr = $this->getLabelFromWix($fromToken, $lk);
            if (!empty($lr['displayName'])) $sourceLabelKeyToName[$lk] = $lr['displayName'];
        }

        // Source contact extended field defs (Contacts v4)
        $sourceExtDefs = $this->getAllExtendedFields($fromToken); // [key => ['displayName','dataType']]

        // Progress counters
        $imported = 0; $skipped = 0; $failed = 0;
        $dupeEmails = [];

        // For member relationship replay (only if $copyMembers)
        $oldMemberIdToNew = [];
        $sourceMemberBadges    = []; // oldMemberId => [badges]
        $sourceMemberFollowing = []; // oldMemberId => [members]

        // Allowed info keys to carry over
        $allowedInfoKeys = [
            'name','emails','phones','addresses','company','jobTitle',
            'birthdate','locale','labelKeys','extendedFields','locations'
        ];

        foreach ($contacts as $src) {
            // Build filtered "info" similar to your import flow
            $info = $src['info'] ?? $src;
            $filtered = [];
            foreach ($allowedInfoKeys as $k) {
                if (isset($info[$k])) $filtered[$k] = $info[$k];
            }

            // flatten arrays
            foreach (['emails','phones','addresses'] as $k) {
                if (!empty($filtered[$k]['items'])) {
                    $filtered[$k] = ['items' => array_values($filtered[$k]['items'])];
                } else {
                    unset($filtered[$k]);
                }
            }

            // Labels → resolve to TARGET keys via displayName
            $targetLabelKeys = [];
            foreach (($src['labelKeys'] ?? []) as $lk) {
                $name = $sourceLabelKeyToName[$lk] ?? null;
                if ($name) {
                    $res = $this->findOrCreateLabelInWix($toToken, $name);
                    if (!empty($res['key'])) $targetLabelKeys[] = $res['key'];
                }
            }
            if ($targetLabelKeys) $filtered['labelKeys'] = $targetLabelKeys;

            // Extended fields (only user-defined) → map to TARGET keys by displayName
            $targetExtItems = [];
            $srcExtItems = $info['extendedFields']['items'] ?? [];
            foreach ($srcExtItems as $key => $val) {
                $def = $sourceExtDefs[$key] ?? null;
                if (!$def) continue;
                $displayName = $def['displayName'] ?? null;
                $dataType    = $def['dataType']    ?? 'TEXT';
                if ($this->isSystemExtendedField($displayName)) continue;
                if ($displayName !== null) {
                    $tKey = $this->findOrCreateExtendedField($toToken, $displayName, $dataType);
                    if ($tKey) $targetExtItems[$tKey] = $val;
                }
            }
            if ($targetExtItems) $filtered['extendedFields'] = ['items' => $targetExtItems];

            // Clean empties
            $filtered = $this->cleanEmpty($filtered);

            // Basic identity
            $email = $filtered['emails']['items'][0]['email'] ?? null;
            $name  = $filtered['name']['first'] ?? ($filtered['name']['formatted'] ?? null);

            // Stage/append a pending row keyed by (user, from_store, email)
            if ($email) {
                WixContactMigration::updateOrCreate(
                    [
                        'user_id'       => $userId,
                        'from_store_id' => $fromStoreId,
                        'contact_email' => $email,
                    ],
                    [
                        'to_store_id'             => $toStoreId,
                        'contact_name'            => $name,
                        'destination_contact_id'  => null,
                        'status'                  => 'pending',
                        'error_message'           => null,
                    ]
                );
            }

            // Minimum required fields check
            if (!$name && !$email && empty($filtered['phones']['items'])) {
                $failed++;
                if ($email) {
                    WixContactMigration::where([
                        'user_id' => $userId, 'from_store_id' => $fromStoreId, 'contact_email' => $email
                    ])->update([
                        'to_store_id'            => $toStoreId,
                        'status'                 => 'failed',
                        'error_message'          => 'Missing required fields (name/email/phone)',
                    ]);
                }
                continue;
            }

            // Target dedupe by email
            $existing = $email ? $this->findContactByEmail($toToken, $email) : null;
            if ($existing && !empty($existing['id'])) {
                $skipped++;
                if ($email) $dupeEmails[] = $email;
                if ($email) {
                    WixContactMigration::where([
                        'user_id' => $userId, 'from_store_id' => $fromStoreId, 'contact_email' => $email
                    ])->update([
                        'to_store_id'             => $toStoreId,
                        'destination_contact_id'  => $existing['id'],
                        'status'                  => 'skipped',
                        'error_message'           => 'Duplicate by email; contact already exists in target.',
                        'contact_name'            => $name,
                    ]);
                }
                continue;
            }

            // Create on TARGET
            $created = $this->createContactInWix($toToken, $filtered);
            $newContactId = $created['contact']['id'] ?? null;

            if ($newContactId) {
                $imported++;
                if ($email) {
                    WixContactMigration::where([
                        'user_id' => $userId, 'from_store_id' => $fromStoreId, 'contact_email' => $email
                    ])->update([
                        'to_store_id'             => $toStoreId,
                        'destination_contact_id'  => $newContactId,
                        'status'                  => 'success',
                        'error_message'           => null,
                        'contact_name'            => $name,
                    ]);
                }

                // Attachments (optional)
                if ($copyAttach && !empty($src['id'])) {
                    try {
                        $atts = $this->listContactAttachments($fromToken, $src['id']);
                        foreach ($atts as $att) {
                            $file = $this->downloadContactAttachment($fromToken, $src['id'], $att['id']);
                            if ($file && !empty($file['content'])) {
                                $uploadUrl = $this->generateAttachmentUploadUrl(
                                    $toToken, $newContactId,
                                    $att['fileName'] ?? 'attachment', $att['mimeType'] ?? 'application/octet-stream'
                                );
                                if (!empty($uploadUrl['uploadUrl'])) {
                                    $this->uploadFileToUrl($uploadUrl['uploadUrl'], $file['content'], $att['mimeType'] ?? 'application/octet-stream');
                                }
                            }
                        }
                    } catch (\Throwable $e) {
                        WixHelper::log('Auto Contacts Migration', 'Attachment copy failed: '.$e->getMessage(), 'warn');
                    }
                }

                // Email subscription upsert
                if (!empty($email)) {
                    $desired = strtoupper((string)($src['primaryEmail']['subscriptionStatus'] ?? $defaultSub));
                    $this->upsertEmailSubscription($toToken, $email, $desired);
                }

                // Members (optional)
                if ($copyMembers && !empty($src['id'])) {
                    $srcMember = $this->findMemberByContactId($fromToken, $src['id']);
                    if ($srcMember) {
                        $oldMemberId = $srcMember['id'] ?? null;
                        $memberEmail = $srcMember['loginEmail'] ?? ($email ?? null);
                        $nickname    = $srcMember['profile']['nickname'] ?? ($name ?? null);

                        $existingMember = $memberEmail ? $this->findMemberByEmail($toToken, $memberEmail) : null;
                        $newMemberId    = $existingMember['id'] ?? null;

                        if (!$newMemberId) {
                            $made = $this->createMemberInWix($toToken, [
                                'loginEmail' => $memberEmail,
                                'profile'    => ['nickname' => $nickname],
                            ]);
                            $newMemberId = $made['member']['id'] ?? null;
                        }

                        if ($oldMemberId && $newMemberId) {
                            $oldMemberIdToNew[$oldMemberId] = $newMemberId;

                            // About
                            $about = $this->getMemberAbout($fromToken, $oldMemberId);
                            if ($about) {
                                $payload = [
                                    'memberAbout' => [
                                        'memberId' => $newMemberId,
                                        'content'  => $about['content'] ?? '',
                                        'revision' => $about['revision'] ?? '0',
                                    ]
                                ];
                                $existingAbout = $this->getMemberAbout($toToken, $newMemberId);
                                if ($existingAbout) {
                                    Http::withHeaders([
                                        'Authorization' => $toToken, 'Content-Type' => 'application/json'
                                    ])->patch("https://www.wixapis.com/members/v2/abouts/{$existingAbout['id']}", $payload);
                                } else {
                                    Http::withHeaders([
                                        'Authorization' => $toToken, 'Content-Type' => 'application/json'
                                    ])->post("https://www.wixapis.com/members/v2/abouts", $payload);
                                }
                            }

                            // Cache badges & following for pass 2
                            $sourceMemberBadges[$oldMemberId]    = $this->getMemberBadges($fromToken, $oldMemberId) ?: [];
                            $sourceMemberFollowing[$oldMemberId] = $this->getMemberFollowers($fromToken, $oldMemberId, 'following') ?: [];
                        }
                    }
                }
            } else {
                $failed++;
                $err = json_encode(['sent' => $filtered, 'response' => $created]);
                if ($email) {
                    WixContactMigration::where([
                        'user_id' => $userId, 'from_store_id' => $fromStoreId, 'contact_email' => $email
                    ])->update([
                        'to_store_id'             => $toStoreId,
                        'destination_contact_id'  => null,
                        'status'                  => 'failed',
                        'error_message'           => $err,
                        'contact_name'            => $name,
                    ]);
                }
                WixHelper::log('Auto Contacts Migration', "Create failed: {$err}", 'warn');
            }
        }

        // PASS 2: replay member relationships (badges + following)
        if ($copyMembers && $oldMemberIdToNew) {
            foreach ($oldMemberIdToNew as $oldId => $newId) {
                // Badges
                foreach ($sourceMemberBadges[$oldId] ?? [] as $badge) {
                    if (!empty($badge['badgeKey'])) {
                        $this->assignBadge($toToken, $newId, $badge['badgeKey']);
                    }
                }
                // Following (only if followed account also migrated)
                foreach ($sourceMemberFollowing[$oldId] ?? [] as $f) {
                    $followedOld = $f['id'] ?? null;
                    if ($followedOld && !empty($oldMemberIdToNew[$followedOld])) {
                        $this->followMember($toToken, $newId, $oldMemberIdToNew[$followedOld]);
                    }
                }
            }
        }

        $summary = "Contacts: imported={$imported}, skipped={$skipped}, failed={$failed}";
        if ($skipped && $dupeEmails) {
            $summary .= '. Duplicates (sample): ' . implode(', ', array_slice(array_unique($dupeEmails), 0, 10));
        }

        if ($imported > 0) {
            // Partial failures => warn in logs, but still show a visible success banner.
            WixHelper::log('Auto Contacts Migration', "Done. {$summary}", $failed ? 'warn' : 'success');

            $resp = back()->with('success', "Contacts auto-migration completed. {$summary}");
            if ($failed > 0) {
                // Add a secondary warning flash if your UI supports it.
                $resp = $resp->with('warning', 'Some contacts failed to migrate. Check logs for details.');
            }
            return $resp;
        }

        if ($failed > 0) {
            // None imported and some failed → clear error to user.
            WixHelper::log('Auto Contacts Migration', "Done. {$summary}", 'error');
            return back()->with('error', "No contacts imported. {$summary}");
        }

        // Nothing imported and no failures → likely all duplicates / nothing to do.
        $nothingNote = $skipped > 0
            ? "Nothing imported — all {$skipped} were duplicates/skipped."
            : "Nothing to import.";
        WixHelper::log('Auto Contacts Migration', "Done. {$summary}. {$nothingNote}", 'info');
        return back()->with('success', "Contacts auto-migration completed. {$summary}. {$nothingNote}");

    }

    // ========================================================= Manual Migrator =========================================================
    // =========================================================
    // Export Contacts (+ member data) — append-only pending rows
    // =========================================================
    public function export(WixStore $store)
    {
        $userId      = Auth::id() ?: 1;
        $fromStoreId = $store->instance_id;

        WixHelper::log('Export Contacts', "Start: {$store->store_name} ({$fromStoreId})", 'info');

        $accessToken = WixHelper::getAccessToken($fromStoreId);
        if (!$accessToken) {
            WixHelper::log('Export Contacts', "Unauthorized: Could not get access token for instanceId: {$fromStoreId}.", 'error');
            return response()->json(['error' => 'You are not authorized to access.'], 401);
        }

        $contacts = $this->getContactsFromWix($accessToken);
        if (isset($contacts['error'])) {
            WixHelper::log('Export Contacts', "API error: " . $contacts['raw'], 'error');
            return response()->json(['error' => $contacts['error']], 500);
        }

        // --- Label Handling ---
        $allLabelKeys = [];
        foreach ($contacts as $contact) {
            if (!empty($contact['labelKeys'])) {
                $allLabelKeys = array_merge($allLabelKeys, $contact['labelKeys']);
            }
        }
        $allLabelKeys = array_unique($allLabelKeys);

        $labelMap = [];
        foreach ($allLabelKeys as $labelKey) {
            $labelResp = $this->getLabelFromWix($accessToken, $labelKey);
            if (isset($labelResp['displayName'])) {
                $labelMap[$labelKey] = $labelResp['displayName'];
            }
        }

        // --- Extended Field defs (only export user fields) ---
        $extendedFieldDefs = $this->getAllExtendedFields($accessToken);

        foreach ($contacts as &$contact) {
            // Labels => humanized
            $contact['labels'] = [];
            if (!empty($contact['labelKeys'])) {
                foreach ($contact['labelKeys'] as $key) {
                    $contact['labels'][] = [
                        'key' => $key,
                        'displayName' => $labelMap[$key] ?? $key
                    ];
                }
            }

            // Extended fields: only user-defined (not system)
            $contact['extendedFields'] = [];
            $items = $contact['info']['extendedFields']['items'] ?? [];
            foreach ($items as $key => $value) {
                $def = $extendedFieldDefs[$key] ?? null;
                if (!isset($def['dataType']) || !isset($def['displayName'])) continue;
                if ($this->isSystemExtendedField($def['displayName'])) continue;
                $contact['extendedFields'][] = [
                    'key' => $key,
                    'displayName' => $def['displayName'],
                    'dataType' => $def['dataType'],
                    'value' => $value,
                ];
            }

            // Attachments
            $contact['attachments'] = [];
            if (!empty($contact['id'])) {
                $atts = $this->listContactAttachments($accessToken, $contact['id']);
                foreach ($atts as $att) {
                    $attFile = $this->downloadContactAttachment($accessToken, $contact['id'], $att['id']);
                    $contact['attachments'][] = [
                        'fileName' => $att['fileName'],
                        'mimeType' => $att['mimeType'],
                        'meta' => $att,
                        'content_base64' => isset($attFile['content']) ? base64_encode($attFile['content']) : null
                    ];
                }
            }

            // MEMBER DATA (if this contact is a member)
            $contact['member'] = null;
            $contactId = $contact['id'] ?? null;
            if ($contactId) {
                $member = $this->findMemberByContactId($accessToken, $contactId);
                if ($member) {
                    $memberId = $member['id'];

                    $contact['member'] = [
                        'id'                 => $memberId,
                        'loginEmail'         => $member['loginEmail'] ?? null,
                        'profile'            => $member['profile'] ?? null,
                        'followers'          => $this->getMemberFollowers($accessToken, $memberId, 'followers'),
                        'following'          => $this->getMemberFollowers($accessToken, $memberId, 'following'),
                        'badges'             => $this->getMemberBadges($accessToken, $memberId),
                        'activity_counters'  => $this->getMemberActivityCounters($accessToken, $memberId),
                    ];

                    // Member About
                    $memberAbout = $this->getMemberAbout($accessToken, $memberId);
                    if ($memberAbout) {
                        $contact['member']['about'] = $memberAbout;
                    }
                }
            }
        }
        unset($contact);

        // --- Custom Fields (Members v1 namespace) ---
        $customFields = $this->getAllCustomFields($accessToken);
        $customFieldIds = array_map(fn($f) => $f['id'], $customFields);
        $customFieldApplications = $this->getCustomFieldApplications($accessToken, $customFieldIds);

        // Oldest-first save order (robust date resolver)
        $createdAtMillis = function (array $item): int {
            foreach (['createdDate','dateCreated','createdAt','creationDate','date_created'] as $k) {
                if (array_key_exists($k, $item)) {
                    $v = $item[$k];
                    if (is_numeric($v)) return (int)$v;
                    if (is_string($v)) { $ts = strtotime($v); if ($ts !== false) return $ts * 1000; }
                }
            }
            foreach ([['audit','createdDate'], ['audit','dateCreated'], ['metadata','createdDate'], ['metadata','dateCreated']] as $path) {
                $cur = $item;
                foreach ($path as $p) {
                    if (!is_array($cur) || !array_key_exists($p, $cur)) { $cur = null; break; }
                    $cur = $cur[$p];
                }
                if ($cur !== null) {
                    if (is_numeric($cur)) return (int)$cur;
                    if (is_string($cur)) { $ts = strtotime($cur); if ($ts !== false) return $ts * 1000; }
                }
            }
            return PHP_INT_MAX; // unknowns go last
        };
        usort($contacts, fn($a, $b) => $createdAtMillis($a) <=> $createdAtMillis($b));

        // Append-only pending rows (no overwrite)
        $pendingSaved = 0;
        foreach ($contacts as $c) {
            $email = $c['info']['emails']['items'][0]['email'] ?? null;
            $name  = $c['info']['name']['first'] ?? ($c['info']['name']['formatted'] ?? null);
            if (!$email) {
                WixHelper::log('Export Contacts', 'Skipped pending row: contact has no email.', 'warn');
                continue;
            }
            WixContactMigration::create([
                'user_id'                 => $userId,
                'from_store_id'           => $fromStoreId,
                'to_store_id'             => null,
                'contact_email'           => $email,
                'contact_name'            => $name,
                'destination_contact_id'  => null,
                'status'                  => 'pending',
                'error_message'           => null,
            ]);
            $pendingSaved++;
        }

        $payload = [
            'from_store_id'           => $fromStoreId,
            'contacts'                => $contacts,
            'customFields'            => $customFields,
            'customFieldApplications' => $customFieldApplications,
        ];

        WixHelper::log('Export Contacts', "Done. Exported ".count($contacts)." contact(s); saved {$pendingSaved} pending row(s).", 'success');

        return response()->streamDownload(function() use ($payload) {
            echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }, 'contacts.json', ['Content-Type' => 'application/json']);
    }

    // =========================================================
    // Import Contacts — oldest-first + claim/resolve saving
    // =========================================================
    public function import(Request $request, WixStore $store)
    {
        $userId    = Auth::id() ?: 1;
        $toStoreId = $store->instance_id;

        WixHelper::log('Import Contacts', "Start: {$store->store_name} ({$toStoreId})", 'info');

        $accessToken = WixHelper::getAccessToken($toStoreId);
        if (!$accessToken) {
            WixHelper::log('Import Contacts', 'Unauthorized: Could not get access token.', 'error');
            return back()->with('error', 'Could not get Wix access token.');
        }

        if (!$request->hasFile('contacts_json')) {
            WixHelper::log('Import Contacts', 'No file uploaded.', 'error');
            return back()->with('error', 'No file uploaded.');
        }

        $file    = $request->file('contacts_json');
        $json    = file_get_contents($file->getRealPath());
        $decoded = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE || !isset($decoded['from_store_id'], $decoded['contacts']) || !is_array($decoded['contacts'])) {
            WixHelper::log('Import Contacts', 'Invalid JSON structure.', 'error');
            return back()->with('error', 'Invalid JSON structure. Required keys: from_store_id and contacts.');
        }

        $fromStoreId       = $decoded['from_store_id'];
        $contacts          = $decoded['contacts'];
        $customFields      = $decoded['customFields'] ?? [];
        $customFieldApps   = $decoded['customFieldApplications'] ?? [];

        // --- Oldest → newest (robust resolver)
        $createdAtMillis = function (array $item): int {
            foreach (['createdDate','dateCreated','createdAt','creationDate','date_created'] as $k) {
                if (array_key_exists($k, $item)) {
                    $v = $item[$k];
                    if (is_numeric($v)) return (int)$v;
                    if (is_string($v)) { $ts = strtotime($v); if ($ts !== false) return $ts * 1000; }
                }
            }
            foreach ([['audit','createdDate'], ['audit','dateCreated'], ['metadata','createdDate'], ['metadata','dateCreated']] as $path) {
                $cur = $item;
                foreach ($path as $p) {
                    if (!is_array($cur) || !array_key_exists($p, $cur)) { $cur = null; break; }
                    $cur = $cur[$p];
                }
                if ($cur !== null) {
                    if (is_numeric($cur)) return (int)$cur;
                    if (is_string($cur)) { $ts = strtotime($cur); if ($ts !== false) return $ts * 1000; }
                }
            }
            return PHP_INT_MAX;
        };
        usort($contacts, fn($a, $b) => $createdAtMillis($a) <=> $createdAtMillis($b));

        $importedContacts  = 0;
        $skippedDuplicates = 0;
        $duplicateEmails   = [];
        $errors            = [];
        $oldMemberIdToNewMemberId = [];
        $oldContactIdToNewContactId = [];

        WixHelper::log('Import Contacts', 'Parsed '.count($contacts).' contact(s).', 'info');

        // ========== PASS 0: Create missing custom fields ==========
        $existingFields = [];
        $allCustomFields = $this->getAllCustomFields($accessToken);
        foreach ($allCustomFields as $field) {
            $existingFields[$field['key']] = $field;
        }
        foreach ($customFields as $field) {
            if (!isset($existingFields[$field['key']])) {
                $resp = Http::withHeaders([
                    'Authorization' => $accessToken,
                    'Content-Type'  => 'application/json'
                ])->post('https://www.wixapis.com/members/v1/custom-fields', [
                    'field' => [
                        'name'            => $field['name'],
                        'fieldType'       => $field['fieldType'],
                        'defaultPrivacy'  => $field['defaultPrivacy'],
                        'socialType'      => $field['socialType'] ?? 'UNKNOWN'
                    ]
                ]);
                if (!$resp->ok()) {
                    WixHelper::log('Import Contacts', 'Custom field create failed: '.$resp->status().' | '.$resp->body(), 'warn');
                }
            }
        }

        // --- Claim/resolve helpers (coupon-style)
        $claimPendingRow = function (?string $email) use ($userId, $fromStoreId) {
            return DB::transaction(function () use ($userId, $fromStoreId, $email) {
                $row = null;
                if ($email) {
                    $row = WixContactMigration::where('user_id', $userId)
                        ->where('from_store_id', $fromStoreId)
                        ->where('status', 'pending')
                        ->where(function ($q) use ($email) {
                            $q->where('contact_email', $email)
                              ->orWhereNull('contact_email');
                        })
                        ->orderByRaw("CASE WHEN contact_email = ? THEN 0 ELSE 1 END", [$email])
                        ->orderBy('created_at', 'asc')
                        ->lockForUpdate()
                        ->first();
                }
                if (!$row) {
                    $row = WixContactMigration::where('user_id', $userId)
                        ->where('from_store_id', $fromStoreId)
                        ->where('status', 'pending')
                        ->orderBy('created_at', 'asc')
                        ->lockForUpdate()
                        ->first();
                }
                return $row;
            }, 3);
        };

        $resolveTargetRow = function ($claimed, ?string $email) use ($userId, $fromStoreId, $toStoreId) {
            if ($email) {
                $existing = WixContactMigration::where('user_id', $userId)
                    ->where('from_store_id', $fromStoreId)
                    ->where('to_store_id', $toStoreId)
                    ->where('contact_email', $email)
                    ->orderBy('created_at', 'asc')
                    ->first();

                if ($existing) {
                    if ($claimed && $claimed->id !== $existing->id && $claimed->status === 'pending') {
                        $claimed->update([
                            'status'        => 'skipped',
                            'error_message' => 'Merged into existing migration row id '.$existing->id,
                        ]);
                    }
                    return $existing;
                }
            }
            return $claimed;
        };

        // ========= PASS 1: Contacts (and immediate member create if needed) =========
        foreach ($contacts as $contact) {
            // Remove system fields
            unset(
                $contact['id'], $contact['revision'], $contact['source'],
                $contact['createdDate'], $contact['updatedDate'],
                $contact['memberInfo'], $contact['primaryEmail'],
                $contact['primaryInfo'], $contact['picture']
            );

            $info = $contact['info'] ?? $contact;

            $allowedInfoKeys = [
                'name', 'emails', 'phones', 'addresses', 'company',
                'jobTitle', 'birthdate', 'locale', 'labelKeys',
                'extendedFields', 'locations'
            ];

            $filteredInfo = [];
            foreach ($allowedInfoKeys as $key) {
                if (isset($info[$key])) {
                    $filteredInfo[$key] = $info[$key];
                }
            }

            foreach (['emails', 'phones', 'addresses'] as $field) {
                if (!empty($filteredInfo[$field]['items'])) {
                    $filteredInfo[$field] = ['items' => array_values($filteredInfo[$field]['items'])];
                } else {
                    unset($filteredInfo[$field]);
                }
            }

            if (!empty($filteredInfo['extendedFields']['items'])) {
                $filteredInfo['extendedFields']['items'] = array_filter(
                    $filteredInfo['extendedFields']['items'],
                    fn($k) => strpos($k, 'custom.') === 0,
                    ARRAY_FILTER_USE_KEY
                );
                if (empty($filteredInfo['extendedFields']['items'])) {
                    unset($filteredInfo['extendedFields']);
                }
            }

            $email = $filteredInfo['emails']['items'][0]['email'] ?? null;
            $name  = $filteredInfo['name']['first'] ?? ($filteredInfo['name']['formatted'] ?? null);

            // ----- LABELS -----
            $labelKeys = [];
            if (!empty($contact['labels'])) {
                foreach ($contact['labels'] as $label) {
                    $displayName = $label['displayName'] ?? $label['key'] ?? null;
                    if ($displayName) {
                        $labelResp = $this->findOrCreateLabelInWix($accessToken, $displayName);
                        if (isset($labelResp['key'])) {
                            $labelKeys[] = $labelResp['key'];
                        }
                    }
                }
            }
            if (!empty($labelKeys)) {
                $filteredInfo['labelKeys'] = $labelKeys;
            }

            // ----- EXTENDED FIELDS -----
            $extendedFieldItems = [];
            if (!empty($contact['extendedFields'])) {
                foreach ($contact['extendedFields'] as $extField) {
                    $displayName = $extField['displayName'] ?? null;
                    $dataType    = $extField['dataType'] ?? 'TEXT';
                    $value       = $extField['value'] ?? null;
                    if ($this->isSystemExtendedField($displayName)) continue;
                    if ($displayName && $value !== null) {
                        $key = $this->findOrCreateExtendedField($accessToken, $displayName, $dataType);
                        if ($key) $extendedFieldItems[$key] = $value;
                    }
                }
            }
            if ($extendedFieldItems) {
                $filteredInfo['extendedFields'] = ['items' => $extendedFieldItems];
            }

            $filteredInfo = $this->cleanEmpty($filteredInfo);

            // Claim a pending row by email and resolve any target duplicate row
            $claimed   = $claimPendingRow($email);
            $targetRow = $resolveTargetRow($claimed, $email);

            // Validate required fields
            if (!$name && !$email && empty($filteredInfo['phones']['items'])) {
                $msg = "Contact missing required fields (name/email/phone)";
                if ($targetRow) {
                    $targetRow->update([
                        'to_store_id'                => $toStoreId,
                        'destination_contact_id'     => null,
                        'status'                     => 'failed',
                        'error_message'              => $msg,
                        'contact_name'               => $name ?? $targetRow->contact_name,
                        'contact_email'              => $targetRow->contact_email ?: ($email ?? null),
                    ]);
                }
                $errors[] = $msg;
                continue;
            }

            // Dedupe by email in target
            $existingContact = $email ? $this->findContactByEmail($accessToken, $email) : null;
            if ($existingContact && isset($existingContact['id'])) {
                // Mark as skipped (already present)
                if ($targetRow) {
                    $targetRow->update([
                        'to_store_id'               => $toStoreId,
                        'destination_contact_id'    => $existingContact['id'],
                        'status'                    => 'skipped',
                        'error_message'             => 'Duplicate by email; contact already exists in target.',
                        'contact_name'              => $name ?? $targetRow->contact_name,
                        'contact_email'             => $targetRow->contact_email ?: ($email ?? null),
                    ]);
                }

                $skippedDuplicates++;
                if ($email) {
                    $duplicateEmails[] = $email;
                }
                WixHelper::log(
                    'Import Contacts',
                    "Skipped duplicate in target: {$email} (existing id: {$existingContact['id']})",
                    'info'
                );

                $oldId = $contact['info']['_old_id'] ?? null;
                if ($oldId) $oldContactIdToNewContactId[$oldId] = $existingContact['id'];
                continue;
            }

            // Create contact
            $result = $this->createContactInWix($accessToken, $filteredInfo);
            if (isset($result['contact']['id'])) {
                $newContactId = $result['contact']['id'];
                $importedContacts++;

                // Update migration row (success)
                if ($targetRow) {
                    DB::transaction(function () use ($targetRow, $toStoreId, $newContactId, $name, $email) {
                        $targetRow->update([
                            'to_store_id'               => $toStoreId,
                            'destination_contact_id'    => $newContactId,
                            'status'                    => 'success',
                            'error_message'             => null,
                            'contact_name'              => $name ?? $targetRow->contact_name,
                            'contact_email'             => $targetRow->contact_email ?: ($email ?? null),
                        ]);
                    }, 3);
                }

                WixHelper::log('Import Contacts', "Imported contact: " . ($name ?? 'Unknown') . " (ID: {$newContactId})", 'success');

                // --- Import Attachments ---
                if (!empty($contact['attachments'])) {
                    foreach ($contact['attachments'] as $att) {
                        if (!empty($att['fileName']) && !empty($att['mimeType']) && !empty($att['content_base64'])) {
                            $uploadUrlResp = $this->generateAttachmentUploadUrl($accessToken, $newContactId, $att['fileName'], $att['mimeType']);
                            if (!empty($uploadUrlResp['uploadUrl'])) {
                                $this->uploadFileToUrl($uploadUrlResp['uploadUrl'], base64_decode($att['content_base64']), $att['mimeType']);
                            }
                        }
                    }
                }

                // ===== EMAIL SUBSCRIPTION UPSERT =====
                if (!empty($email)) {
                    $desiredSubStatus = null;
                    if (isset($contact['primaryEmail']['subscriptionStatus']) && is_string($contact['primaryEmail']['subscriptionStatus'])) {
                        $desiredSubStatus = strtoupper($contact['primaryEmail']['subscriptionStatus']);
                    }
                    $desiredSubStatus = $desiredSubStatus ?: 'SUBSCRIBED';

                    $upsert = $this->upsertEmailSubscription($accessToken, $email, $desiredSubStatus);
                    WixHelper::log(
                        'Import Contacts',
                        "Email subscription upsert for {$email} → " . json_encode($upsert['meta']),
                        $upsert['ok'] ? 'info' : 'warn'
                    );
                }

                // ===== MEMBER CREATE (if member info present) =====
                if (!empty($contact['member'])) {
                    $oldMemberId = $contact['member']['id'] ?? null;

                    $memberEmail   = $contact['member']['loginEmail'] ?? ($email ?? null);
                    $memberProfile = $contact['member']['profile'] ?? [];
                    $nickname      = $memberProfile['nickname'] ?? ($name ?? null);

                    $existingMember = $memberEmail ? $this->findMemberByEmail($accessToken, $memberEmail) : null;
                    if ($existingMember) {
                        $newMemberId = $existingMember['id'];
                    } else {
                        $createBody = [
                            'loginEmail' => $memberEmail,
                            'profile'    => ['nickname' => $nickname],
                        ];
                        $created = $this->createMemberInWix($accessToken, $createBody);
                        $newMemberId = $created['member']['id'] ?? null;
                    }

                    if (!empty($newMemberId) && !empty($oldMemberId)) {
                        $oldMemberIdToNewMemberId[$oldMemberId] = $newMemberId;
                    }

                    // Import Member About
                    if (!empty($contact['member']['about']) && !empty($newMemberId)) {
                        $about = $contact['member']['about'];
                        $aboutPayload = [
                            'memberAbout' => [
                                'memberId' => $newMemberId,
                                'content'  => $about['content'],
                                'revision' => $about['revision'] ?? '0'
                            ]
                        ];
                        $existingAbout = $this->getMemberAbout($accessToken, $newMemberId);
                        if ($existingAbout) {
                            Http::withHeaders([
                                'Authorization' => $accessToken,
                                'Content-Type'  => 'application/json'
                            ])->patch("https://www.wixapis.com/members/v2/abouts/{$existingAbout['id']}", $aboutPayload);
                        } else {
                            Http::withHeaders([
                                'Authorization' => $accessToken,
                                'Content-Type'  => 'application/json'
                            ])->post("https://www.wixapis.com/members/v2/abouts", $aboutPayload);
                        }
                    }
                }
            } else {
                // Failure: update migration row
                $errMsg = json_encode(['sent' => $filteredInfo, 'response' => $result]);
                if ($targetRow) {
                    $targetRow->update([
                        'to_store_id'               => $toStoreId,
                        'destination_contact_id'    => null,
                        'status'                    => 'failed',
                        'error_message'             => $errMsg,
                        'contact_name'              => $name ?? $targetRow->contact_name,
                        'contact_email'             => $targetRow->contact_email ?: ($email ?? null),
                    ]);
                }
                $errors[] = $errMsg;
                WixHelper::log('Import Contacts', "Failed to import: {$errMsg}", 'error');
            }
        }

        // ========= PASS 2: Restore member relationships (followers, following, badges) =========
        foreach ($contacts as $contact) {
            if (empty($contact['member'])) continue;

            $oldMemberId = $contact['member']['id'] ?? null;
            if (empty($oldMemberId) || empty($oldMemberIdToNewMemberId[$oldMemberId])) continue;

            $newMemberId = $oldMemberIdToNewMemberId[$oldMemberId];

            // Badges
            if (!empty($contact['member']['badges'])) {
                foreach ($contact['member']['badges'] as $badge) {
                    if (!empty($badge['badgeKey'])) {
                        $this->assignBadge($accessToken, $newMemberId, $badge['badgeKey']);
                    }
                }
            }

            // Following (make the imported member follow targets that were imported)
            if (!empty($contact['member']['following'])) {
                foreach ($contact['member']['following'] as $f) {
                    $followedOldId = $f['id'] ?? null;
                    if ($followedOldId && !empty($oldMemberIdToNewMemberId[$followedOldId])) {
                        $this->followMember($accessToken, $newMemberId, $oldMemberIdToNewMemberId[$followedOldId]);
                    }
                }
            }
        }

        // ===== Final reporting (counts + duplicates surfaced) =====
        $dupPreview = '';
        if ($skippedDuplicates > 0) {
            $preview = array_slice(array_unique($duplicateEmails), 0, 10);
            $dupPreview = ' Duplicates skipped: '.$skippedDuplicates.(count($preview) ? ' (e.g. '.implode(', ', $preview).')' : '');
        }

        if ($importedContacts > 0) {
            $msg = "Import finished: {$importedContacts} contact(s) imported.{$dupPreview}";
            if (count($errors)) {
                $msg .= " Some errors: " . implode("; ", $errors);
                WixHelper::log('Import Contacts', "Done. {$msg}", 'warning');
            } else {
                WixHelper::log('Import Contacts', "Done. {$msg}", 'success');
            }
            return back()->with('success', $msg);
        } else {
            $msg = "No contacts imported.{$dupPreview}";
            if (count($errors)) {
                $msg .= " Errors: " . implode("; ", $errors);
            }
            WixHelper::log('Import Contacts', "Done. {$msg}", 'error');
            return back()->with('error', $msg);
        }
    }


    // =========================================================
    // Utilities (Contacts + Members)
    // =========================================================

    // -------- Contacts --------
    public function getContactsFromWix($accessToken, $limit = 1000)
    {
        $contacts = [];
        $offset = 0;
        $total = 0;

        do {
            $query = [
                'paging.limit'  => $limit,
                'paging.offset' => $offset,
                'fieldsets'     => 'FULL'
            ];

            $response = Http::withHeaders([
                'Authorization' => $accessToken,
                'Content-Type'  => 'application/json'
            ])->get('https://www.wixapis.com/contacts/v4/contacts', $query);

            WixHelper::log('Export Contacts', 'Query page received: status='.$response->status().', offset='.$offset, 'info');

            if (!$response->ok()) {
                WixHelper::log('Export Contacts', "API error: " . $response->body(), 'error');
                return [
                    'error' => 'Failed to fetch contacts from Wix.',
                    'raw' => $response->body()
                ];
            }

            $data = $response->json();

            if (!isset($data['contacts'])) {
                WixHelper::log('Export Contacts', "API error: " . json_encode($data), 'error');
                return [
                    'error' => 'Failed to fetch contacts from Wix.',
                    'raw' => json_encode($data)
                ];
            }

            foreach ($data['contacts'] as $contact) {
                $contacts[] = $contact;
            }

            $count = $data['pagingMetadata']['count'] ?? 0;
            $total = $data['pagingMetadata']['total'] ?? 0;
            $offset += $count;

        } while ($count > 0 && $offset < $total);

        return $contacts;
    }

    private function cleanEmpty($array)
    {
        foreach ($array as $k => $v) {
            if (is_array($v)) {
                $array[$k] = $this->cleanEmpty($v);
                if ($array[$k] === [] || $array[$k] === null) unset($array[$k]);
            } elseif ($v === [] || $v === null) {
                unset($array[$k]);
            }
        }
        return $array;
    }

    // Labels
    private function getLabelFromWix($accessToken, $labelKey)
    {
        $response = Http::withHeaders([
            'Authorization' => $accessToken,
            'Content-Type' => 'application/json'
        ])->get('https://www.wixapis.com/contacts/v4/labels/' . urlencode($labelKey));
        if ($response->ok()) {
            return $response->json();
        }
        return [];
    }
    private function findOrCreateLabelInWix($accessToken, $displayName)
    {
        $response = Http::withHeaders([
            'Authorization' => $accessToken,
            'Content-Type' => 'application/json'
        ])->post('https://www.wixapis.com/contacts/v4/labels', [
            'displayName' => $displayName
        ]);
        if ($response->ok()) {
            return $response->json();
        }
        return [];
    }

    // Extended fields (Contacts v4)
    private function getAllExtendedFields($accessToken)
    {
        $fields = [];
        $offset = 0;
        $limit = 100;
        do {
            $query = [
                'paging.limit' => $limit,
                'paging.offset' => $offset,
            ];
            $resp = Http::withHeaders([
                'Authorization' => $accessToken,
                'Content-Type' => 'application/json'
            ])->get('https://www.wixapis.com/contacts/v4/extended-fields', $query);

            if (!$resp->ok()) break;

            $data = $resp->json();
            foreach ($data['extendedFields'] ?? [] as $f) {
                $fields[$f['key']] = [
                    'displayName' => $f['displayName'],
                    'dataType' => $f['dataType'],
                ];
            }

            $count = $data['pagingMetadata']['count'] ?? 0;
            $offset += $count;
        } while ($count > 0);
        return $fields;
    }

    public function updateCustomExtendedFields(WixStore $store, $contactId, array $customFields)
    {
        $accessToken = WixHelper::getAccessToken($store->instance_id);
        if (!$accessToken) {
            throw new \Exception('Could not get Wix access token');
        }

        $fieldKeys = [];
        foreach ($customFields as $displayName => $value) {
            if (strpos($displayName, 'custom.') !== 0) continue;
            $dataType = is_numeric($value) ? 'NUMBER' : 'TEXT';
            $key = $this->findOrCreateExtendedField($accessToken, $displayName, $dataType);
            if ($key) {
                $fieldKeys[$key] = $value;
            }
        }

        if (empty($fieldKeys)) {
            throw new \Exception('No valid custom fields provided');
        }

        $updateBody = [
            "extendedFields" => [
                "items" => $fieldKeys
            ]
        ];

        $url = "https://www.wixapis.com/contacts/v4/contacts/{$contactId}";
        $response = Http::withHeaders([
            'Authorization' => $accessToken,
            'Content-Type'  => 'application/json'
        ])->patch($url, ["info" => $updateBody]);

        if (!$response->ok()) {
            throw new \Exception("Failed to update custom fields: " . $response->body());
        }

        return $response->json();
    }

    private function findOrCreateExtendedField($accessToken, $displayName, $dataType)
    {
        $resp = Http::withHeaders([
            'Authorization' => $accessToken,
            'Content-Type' => 'application/json'
        ])->post('https://www.wixapis.com/contacts/v4/extended-fields', [
            'displayName' => $displayName,
            'dataType' => $dataType ?: 'TEXT'
        ]);
        if ($resp->ok() && !empty($resp->json()['key'])) {
            return $resp->json()['key'];
        }
        return null;
    }

    private function isSystemExtendedField($displayName)
    {
        if (!$displayName) return true;
        return preg_match('/^(emailSubscriptions\.|contacts\.)/', $displayName);
    }

    private function findContactByEmail($accessToken, $email)
    {
        $query = [
            "query" => [
                "filter" => [
                    "info.emails.items.email" => [
                        "\$eq" => $email
                    ]
                ],
                "paging" => ["limit" => 1]
            ]
        ];

        $response = Http::withHeaders([
            'Authorization' => $accessToken,
            'Content-Type' => 'application/json'
        ])->post('https://www.wixapis.com/contacts/v4/contacts/query', $query);

        if ($response->ok() && !empty($response->json('contacts'))) {
            return $response->json('contacts')[0];
        }
        return null;
    }

    private function createContactInWix($accessToken, $info)
    {
        $body = [
            'info'            => (object)$info,
            'allowDuplicates' => true
        ];

        $response = Http::withHeaders([
            'Authorization' => $accessToken,
            'Content-Type'  => 'application/json'
        ])->post('https://www.wixapis.com/contacts/v4/contacts', $body);

        WixHelper::log('Import Contacts', "createContactInWix → {$response->status()}", $response->ok() ? 'debug' : 'warn');

        return $response->json();
    }

    // ===== Email Subscriptions (Email Marketing) =====
    /**
     * Upsert an email subscription status for a given email.
     * Returns ['ok'=>bool, 'meta'=>['status'=>int, 'body'=>string]]
     */
    private function upsertEmailSubscription(string $accessToken, string $email, string $status = 'SUBSCRIBED'): array
    {
        try {
            $resp = Http::withHeaders([
                'Authorization' => $accessToken,
                'Content-Type'  => 'application/json',
            ])->post('https://www.wixapis.com/email-marketing/v1/email-subscriptions', [
                'emailSubscription' => [
                    'email' => $email,
                    'subscriptionStatus' => strtoupper($status),
                ],
            ]);

            return [
                'ok'   => $resp->ok(),
                'meta' => ['status' => $resp->status(), 'body' => $resp->body()],
            ];
        } catch (\Throwable $e) {
            Log::warning('Email subscription upsert failed: '.$e->getMessage());
            return [
                'ok'   => false,
                'meta' => ['status' => 0, 'body' => $e->getMessage()],
            ];
        }
    }

    // -------- Contact Attachments --------
    private function listContactAttachments($accessToken, $contactId)
    {
        $attachments = [];
        $limit = 100; $offset = 0;
        do {
            $query = [
                'paging.limit' => $limit,
                'paging.offset' => $offset,
            ];
            $response = Http::withHeaders([
                'Authorization' => $accessToken,
            ])->get("https://www.wixapis.com/contacts/v4/attachments/$contactId", $query);
            if (!$response->ok()) break;
            $data = $response->json();
            foreach ($data['attachments'] ?? [] as $att) $attachments[] = $att;
            $count = $data['pagingMetadata']['count'] ?? 0;
            $offset += $count;
        } while ($count > 0);
        return $attachments;
    }

    private function downloadContactAttachment($accessToken, $contactId, $attachmentId)
    {
        $response = Http::withHeaders([
            'Authorization' => $accessToken,
        ])->get("https://www.wixapis.com/contacts/v4/attachments/$contactId/$attachmentId");
        if ($response->ok()) {
            return [
                'filename' => $response->header('content-disposition'),
                'mimeType' => $response->header('content-type'),
                'content' => $response->body(),
            ];
        }
        return null;
    }

    private function generateAttachmentUploadUrl($accessToken, $contactId, $fileName, $mimeType)
    {
        $response = Http::withHeaders([
            'Authorization' => $accessToken,
            'Content-Type'  => 'application/json'
        ])->post("https://www.wixapis.com/contacts/v4/attachments/$contactId/upload-url", [
            'fileName' => $fileName,
            'mimeType' => $mimeType,
        ]);
        return $response->ok() ? $response->json() : null;
    }

    private function uploadFileToUrl($url, $fileContent, $mimeType)
    {
        try {
            $client = new \GuzzleHttp\Client();
            $response = $client->put($url, [
                'body' => $fileContent,
                'headers' => [
                    'Content-Type' => $mimeType,
                ]
            ]);
            return $response->getStatusCode() === 200 || $response->getStatusCode() === 201;
        } catch (\Exception $e) {
            Log::error("Attachment upload failed: " . $e->getMessage());
            return false;
        }
    }

    // -------- Members helpers (tied to contacts) --------

    // Find member by contactId (preferred linkage)
    private function findMemberByContactId($accessToken, $contactId)
    {
        $query = [
            'query' => [
                'filter' => [
                    'contactId' => ['$eq' => $contactId]
                ],
                'paging' => ['limit' => 1]
            ]
        ];
        $resp = Http::withHeaders([
            'Authorization' => $accessToken,
            'Content-Type'  => 'application/json'
        ])->post('https://www.wixapis.com/members/v1/members/query', $query);

        if ($resp->ok() && !empty($resp->json('members'))) {
            return $resp->json('members')[0];
        }
        return null;
    }

    private function findMemberByEmail($accessToken, $email)
    {
        if (!$email) return null;
        $query = [
            'query' => [
                'filter' => [
                    'loginEmail' => ['$eq' => $email]
                ],
                'paging' => ['limit' => 1]
            ]
        ];
        $response = Http::withHeaders([
            'Authorization' => $accessToken,
            'Content-Type'  => 'application/json'
        ])->post('https://www.wixapis.com/members/v1/members/query', $query);
        if ($response->ok() && !empty($response->json('members'))) {
            return $response->json('members')[0];
        }
        return null;
    }

    private function createMemberInWix($accessToken, $body)
    {
        // API expects { member: { ... } }
        $response = Http::withHeaders([
            'Authorization' => $accessToken,
            'Content-Type'  => 'application/json'
        ])->post('https://www.wixapis.com/members/v1/members', ['member' => $body]);
        return $response->json();
    }

    private function getMemberFollowers($accessToken, $memberId, $type = 'followers')
    {
        $endpoint = $type === 'followers'
            ? "https://www.wixapis.com/members/v1/members/$memberId/followers"
            : "https://www.wixapis.com/members/v1/members/$memberId/following";
        $response = Http::withHeaders([
            'Authorization' => $accessToken,
            'Content-Type'  => 'application/json'
        ])->get($endpoint);
        return $response->ok() ? ($response->json()['members'] ?? []) : [];
    }

    private function followMember($accessToken, $memberId, $targetMemberId)
    {
        Http::withHeaders([
            'Authorization' => $accessToken,
            'Content-Type'  => 'application/json'
        ])->post("https://www.wixapis.com/members/v1/members/$memberId/following", [
            'memberId' => $targetMemberId
        ]);
    }

    private function getMemberBadges($accessToken, $memberId)
    {
        $response = Http::withHeaders([
            'Authorization' => $accessToken,
            'Content-Type'  => 'application/json'
        ])->get("https://www.wixapis.com/members/v1/members/$memberId/badges");
        return $response->ok() ? ($response->json()['badges'] ?? []) : [];
    }

    private function assignBadge($accessToken, $memberId, $badgeKey)
    {
        Http::withHeaders([
            'Authorization' => $accessToken,
            'Content-Type'  => 'application/json'
        ])->post("https://www.wixapis.com/members/v1/members/$memberId/badges", [
            'badgeKey' => $badgeKey
        ]);
    }

    private function getMemberActivityCounters($accessToken, $memberId)
    {
        $response = Http::withHeaders([
            'Authorization' => $accessToken,
            'Content-Type'  => 'application/json'
        ])->get("https://www.wixapis.com/members/v1/members/$memberId/activity-counters");
        return $response->ok() ? $response->json() : [];
    }

    // Get Member About by memberId
    private function getMemberAbout($accessToken, $memberId)
    {
        $response = Http::withHeaders([
            'Authorization' => $accessToken,
            'Content-Type' => 'application/json',
        ])->get("https://www.wixapis.com/members/v2/abouts/member/$memberId");

        if ($response->ok()) {
            return $response->json('memberAbout') ?? null;
        }
        return null;
    }

    // Get all custom fields (Members v1)
    private function getAllCustomFields($accessToken)
    {
        $fields = [];
        $limit = 100;
        $offset = 0;
        do {
            $response = Http::withHeaders([
                'Authorization' => $accessToken,
                'Content-Type' => 'application/json',
            ])->get('https://www.wixapis.com/members/v1/custom-fields', [
                'paging.limit' => $limit,
                'paging.offset' => $offset,
            ]);

            if (!$response->ok()) {
                break;
            }

            $data = $response->json();
            foreach ($data['fields'] ?? [] as $field) {
                $fields[$field['key']] = $field;
            }

            $count = $data['metadata']['count'] ?? 0;
            $offset += $count;
        } while ($count > 0);

        return $fields;
    }

    // Get custom field applications for given custom field IDs (Members v1)
    private function getCustomFieldApplications($accessToken, array $customFieldIds)
    {
        $response = Http::withHeaders([
            'Authorization' => $accessToken,
            'Content-Type' => 'application/json',
        ])->post('https://www.wixapis.com/members/v1/custom-fields-applications/applications', [
            'customFieldIds' => $customFieldIds
        ]);

        if ($response->ok()) {
            return $response->json('applications') ?? [];
        }
        return [];
    }
}
