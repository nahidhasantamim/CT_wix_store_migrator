<?php

namespace App\Http\Controllers;

use App\Models\WixStore;
use App\Helpers\WixHelper;
use App\Models\WixContactMigration;
use DateTime;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class WixContactController extends Controller
{
    // Put these near your other properties
    /** Simple in-request caches to avoid duplicate Marketing-Consent queries */
    protected array $mcEmailCache = []; // [lowercasedEmail => consentRow|null]
    protected array $mcPhoneCache = []; // [e164 => consentRow|null]

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
        // $fromToken = WixHelper::getAccessToken($fromStoreId);
        // $toToken   = WixHelper::getAccessToken($toStoreId);
        $fromToken = "CoBuned_fU1TI9a8g0nNrFomA9h6xEcO9mCKQb01qRY.eyJpbnN0YW5jZUlkIjoiMjAwZDNkYTAtYmQyYS00N2Q0LWExODItZWQxNGI2ZWMxMzNmIiwiYXBwRGVmSWQiOiIyMmJlZjM0NS0zYzViLTRjMTgtYjc4Mi03NGQ0MDg1MTEyZmYiLCJtZXRhU2l0ZUlkIjoiMjAwZDNkYTAtYmQyYS00N2Q0LWExODItZWQxNGI2ZWMxMzNmIiwic2lnbkRhdGUiOiIyMDI1LTA5LTI2VDE2OjE5OjA0LjQ4OVoiLCJ1aWQiOiJjN2U5MTFmMC03Y2E5LTRjZTEtYjM3OS0wNDhkY2I4NTA0NGIiLCJwZXJtaXNzaW9ucyI6Ik9XTkVSIiwiZGVtb01vZGUiOmZhbHNlLCJzaXRlT3duZXJJZCI6ImM3ZTkxMWYwLTdjYTktNGNlMS1iMzc5LTA0OGRjYjg1MDQ0YiIsInNpdGVNZW1iZXJJZCI6ImM3ZTkxMWYwLTdjYTktNGNlMS1iMzc5LTA0OGRjYjg1MDQ0YiIsImV4cGlyYXRpb25EYXRlIjoiMjAyNS0wOS0yNlQyMDoxOTowNC40ODlaIiwibG9naW5BY2NvdW50SWQiOiJjN2U5MTFmMC03Y2E5LTRjZTEtYjM3OS0wNDhkY2I4NTA0NGIiLCJhb3IiOnRydWUsInNjZCI6IjIwMjUtMDQtMThUMTM6MzA6MDIuMjg4WiIsImFjZCI6IjIwMjUtMDQtMTdUMTM6MzA6NDVaIiwic3MiOmZhbHNlfQ";
        $toToken   = "ho4KogusOVdYfbEjT4mdS9BXfHnn0Sa5U0W9tQlCvcg.eyJpbnN0YW5jZUlkIjoiMTcyZGVlMWYtZDI0NC00MDJkLTljMTMtMjM3N2MwOTVhYjAxIiwiYXBwRGVmSWQiOiIyMmJlZjM0NS0zYzViLTRjMTgtYjc4Mi03NGQ0MDg1MTEyZmYiLCJtZXRhU2l0ZUlkIjoiMTcyZGVlMWYtZDI0NC00MDJkLTljMTMtMjM3N2MwOTVhYjAxIiwic2lnbkRhdGUiOiIyMDI1LTA5LTI2VDE2OjE4OjUyLjE5MloiLCJ1aWQiOiJjN2U5MTFmMC03Y2E5LTRjZTEtYjM3OS0wNDhkY2I4NTA0NGIiLCJwZXJtaXNzaW9ucyI6Ik9XTkVSIiwiZGVtb01vZGUiOmZhbHNlLCJzaXRlT3duZXJJZCI6ImM3ZTkxMWYwLTdjYTktNGNlMS1iMzc5LTA0OGRjYjg1MDQ0YiIsInNpdGVNZW1iZXJJZCI6ImM3ZTkxMWYwLTdjYTktNGNlMS1iMzc5LTA0OGRjYjg1MDQ0YiIsImV4cGlyYXRpb25EYXRlIjoiMjAyNS0wOS0yNlQyMDoxODo1Mi4xOTJaIiwibG9naW5BY2NvdW50SWQiOiJjN2U5MTFmMC03Y2E5LTRjZTEtYjM3OS0wNDhkY2I4NTA0NGIiLCJhb3IiOnRydWUsInNjZCI6IjIwMjUtMDgtMDZUMTk6MjU6MzMuNjUwWiIsImFjZCI6IjIwMjUtMDQtMTdUMTM6MzA6NDVaIiwic3MiOmZhbHNlfQ";
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

            // Labels → resolve to TARGET keys via displayName (inspired by older version)
            $targetLabelKeys = [];
            if (!empty($src['labelKeys'])) {
                foreach ($src['labelKeys'] as $lk) {
                    $displayName = $sourceLabelKeyToName[$lk] ?? $lk; // Fall back to key if no display name
                    $labelResp = $this->findOrCreateLabelInWix($toToken, $displayName);
                    if (isset($labelResp['key'])) {
                        $targetLabelKeys[] = $labelResp['key'];
                    }
                }
                if (!empty($targetLabelKeys)) {
                    $filtered['labelKeys'] = $targetLabelKeys;
                }
            }

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

        // $accessToken = WixHelper::getAccessToken($fromStoreId);
        $accessToken = "CoBuned_fU1TI9a8g0nNrFomA9h6xEcO9mCKQb01qRY.eyJpbnN0YW5jZUlkIjoiMjAwZDNkYTAtYmQyYS00N2Q0LWExODItZWQxNGI2ZWMxMzNmIiwiYXBwRGVmSWQiOiIyMmJlZjM0NS0zYzViLTRjMTgtYjc4Mi03NGQ0MDg1MTEyZmYiLCJtZXRhU2l0ZUlkIjoiMjAwZDNkYTAtYmQyYS00N2Q0LWExODItZWQxNGI2ZWMxMzNmIiwic2lnbkRhdGUiOiIyMDI1LTA5LTI2VDE2OjE5OjA0LjQ4OVoiLCJ1aWQiOiJjN2U5MTFmMC03Y2E5LTRjZTEtYjM3OS0wNDhkY2I4NTA0NGIiLCJwZXJtaXNzaW9ucyI6Ik9XTkVSIiwiZGVtb01vZGUiOmZhbHNlLCJzaXRlT3duZXJJZCI6ImM3ZTkxMWYwLTdjYTktNGNlMS1iMzc5LTA0OGRjYjg1MDQ0YiIsInNpdGVNZW1iZXJJZCI6ImM3ZTkxMWYwLTdjYTktNGNlMS1iMzc5LTA0OGRjYjg1MDQ0YiIsImV4cGlyYXRpb25EYXRlIjoiMjAyNS0wOS0yNlQyMDoxOTowNC40ODlaIiwibG9naW5BY2NvdW50SWQiOiJjN2U5MTFmMC03Y2E5LTRjZTEtYjM3OS0wNDhkY2I4NTA0NGIiLCJhb3IiOnRydWUsInNjZCI6IjIwMjUtMDQtMThUMTM6MzA6MDIuMjg4WiIsImFjZCI6IjIwMjUtMDQtMTdUMTM6MzA6NDVaIiwic3MiOmZhbHNlfQ";

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

            // Notes
            $contactId = $contact['id'] ?? null;
            $contact['notes'] = $contactId ? $this->getContactNotes($accessToken, $contactId) : [];

            // MEMBER DATA (if this contact is a member)
            $contact['member'] = null;
            if ($contactId) {
                $member = $this->findMemberByContactId($accessToken, $contactId);
                if ($member) {
                    $memberId = $member['id'];

                    $contact['member'] = [
                        'id'                 => $memberId,
                        'loginEmail'         => $member['loginEmail'] ?? null,
                        'profile'            => $member['profile'] ?? null,
                        'customFields'       => $member['customFields'] ?? null,
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

            // ===== Marketing Consent lookups (email + phone) =====
            $mcEmails = [];
            $mcPhones = [];

            // 4.1 Primary email
            $primaryEmail = $contact['primaryEmail']['email'] ?? null;
            if ($primaryEmail) {
                $mc = $this->getMarketingConsentByEmail($accessToken, $this->normEmail($primaryEmail));
                if ($mc) $mcEmails[$this->normEmail($primaryEmail)] = $mc;
            }

            // 4.2 All emails from info.emails.items
            $emailsItems = $contact['info']['emails']['items'] ?? [];
            foreach ($emailsItems as $em) {
                $e = $this->normEmail($em['email'] ?? null);
                if ($e && !isset($mcEmails[$e])) {
                    $mc = $this->getMarketingConsentByEmail($accessToken, $e);
                    if ($mc) $mcEmails[$e] = $mc;
                }
            }

            // 4.3 Primary phone (e164)
            $primaryE164 = $contact['primaryPhone']['e164Phone'] ?? null;
            if ($primaryE164) {
                $normPhone = $this->normE164($primaryE164);
                if ($normPhone) {
                    $mc = $this->getMarketingConsentByPhoneE164($accessToken, $normPhone);
                    if ($mc) $mcPhones[$normPhone] = $mc;
                }
            }

            // 4.4 All phones from info.phones.items (prefer e164 if present)
            $phonesItems = $contact['info']['phones']['items'] ?? [];
            foreach ($phonesItems as $ph) {
                $e164 = $this->normE164($ph['e164Phone'] ?? null);
                if ($e164 && !isset($mcPhones[$e164])) {
                    $mc = $this->getMarketingConsentByPhoneE164($accessToken, $e164);
                    if ($mc) $mcPhones[$e164] = $mc;
                }
            }

            // Attach to export payload (raw MC rows to keep full fidelity)
            $contact['marketingConsent'] = [
                'emails' => $mcEmails,   // keys: normalized email; values: MC record
                'phones' => $mcPhones,   // keys: e164; values: MC record
            ];

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
            'tokem' => $accessToken,
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

        // $accessToken = WixHelper::getAccessToken($toStoreId);
        $accessToken = "ho4KogusOVdYfbEjT4mdS9BXfHnn0Sa5U0W9tQlCvcg.eyJpbnN0YW5jZUlkIjoiMTcyZGVlMWYtZDI0NC00MDJkLTljMTMtMjM3N2MwOTVhYjAxIiwiYXBwRGVmSWQiOiIyMmJlZjM0NS0zYzViLTRjMTgtYjc4Mi03NGQ0MDg1MTEyZmYiLCJtZXRhU2l0ZUlkIjoiMTcyZGVlMWYtZDI0NC00MDJkLTljMTMtMjM3N2MwOTVhYjAxIiwic2lnbkRhdGUiOiIyMDI1LTA5LTI2VDE2OjE4OjUyLjE5MloiLCJ1aWQiOiJjN2U5MTFmMC03Y2E5LTRjZTEtYjM3OS0wNDhkY2I4NTA0NGIiLCJwZXJtaXNzaW9ucyI6Ik9XTkVSIiwiZGVtb01vZGUiOmZhbHNlLCJzaXRlT3duZXJJZCI6ImM3ZTkxMWYwLTdjYTktNGNlMS1iMzc5LTA0OGRjYjg1MDQ0YiIsInNpdGVNZW1iZXJJZCI6ImM3ZTkxMWYwLTdjYTktNGNlMS1iMzc5LTA0OGRjYjg1MDQ0YiIsImV4cGlyYXRpb25EYXRlIjoiMjAyNS0wOS0yNlQyMDoxODo1Mi4xOTJaIiwibG9naW5BY2NvdW50SWQiOiJjN2U5MTFmMC03Y2E5LTRjZTEtYjM3OS0wNDhkY2I4NTA0NGIiLCJhb3IiOnRydWUsInNjZCI6IjIwMjUtMDgtMDZUMTk6MjU6MzMuNjUwWiIsImFjZCI6IjIwMjUtMDQtMTdUMTM6MzA6NDVaIiwic3MiOmZhbHNlfQ";
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

        // Oldest → newest
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
        $updatedContacts   = 0;
        $skippedDuplicates = 0; // kept for summary compatibility
        $duplicateEmails   = [];
        $errors            = [];
        $oldMemberIdToNewMemberId = [];
        $oldContactIdToNewContactId = [];

        WixHelper::log('Import Contacts', 'Parsed '.count($contacts).' contact(s).', 'info');

        // PASS 0: Create missing custom fields
        $existingFields = [];
        $allCustomFields = $this->getAllCustomFields($accessToken);
        foreach ($allCustomFields as $field) $existingFields[$field['key']] = $field;
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

        // Claim/resolve helpers
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

        // Helper: deep-merge existing+incoming info
        $mergeInfo = function(array $existing, array $incoming): array {
            $result = $existing;

            if (!empty($incoming['name'])) {
                $result['name'] = array_merge($existing['name'] ?? [], $incoming['name']);
            }
            $mergeItems = function(array $old = null, array $new = null, callable $keyFn) {
                $out = []; $seen = [];
                $push = function($item) use (&$out,&$seen,$keyFn) {
                    $k = $keyFn($item);
                    if ($k === null) return;
                    if (isset($seen[$k])) return;
                    $seen[$k] = true; $out[] = $item;
                };
                foreach (($old['items'] ?? []) as $it) $push($it);
                foreach (($new['items'] ?? []) as $it) $push($it);
                return $out ? ['items' => array_values($out)] : [];
            };
            if (!empty($incoming['emails'])) {
                $result['emails'] = $mergeItems($existing['emails'] ?? [], $incoming['emails'], function($it) {
                    return isset($it['email']) ? mb_strtolower($it['email']) : null;
                });
            } else { $result['emails'] = $existing['emails'] ?? null; }

            if (!empty($incoming['phones'])) {
                $result['phones'] = $mergeItems($existing['phones'] ?? [], $incoming['phones'], function($it) {
                    return $it['e164Phone'] ?? ($it['formattedPhone'] ?? ($it['phone'] ?? null));
                });
            } else { $result['phones'] = $existing['phones'] ?? null; }

            if (!empty($incoming['addresses'])) {
                $result['addresses'] = $mergeItems($existing['addresses'] ?? [], $incoming['addresses'], fn($it) => md5(json_encode($it)));
            } else { $result['addresses'] = $existing['addresses'] ?? null; }

            foreach (['company','jobTitle','birthdate','locale','picture'] as $k) {
                if (isset($incoming[$k])) $result[$k] = $incoming[$k];
            }

            $labels = array_values(array_unique(array_merge(
                $existing['labelKeys'] ?? [],
                $incoming['labelKeys'] ?? []
            )));
            if ($labels) $result['labelKeys'] = $labels;

            if (!empty($existing['extendedFields']['items']) || !empty($incoming['extendedFields']['items'])) {
                $merged = $existing['extendedFields']['items'] ?? [];
                foreach (($incoming['extendedFields']['items'] ?? []) as $k => $v) { $merged[$k] = $v; }
                $result['extendedFields'] = ['items' => $merged];
            }

            $clean = function($a) use (&$clean) {
                foreach ($a as $k => $v) {
                    if (is_array($v)) {
                        $a[$k] = $clean($v);
                        if ($a[$k] === [] || $a[$k] === null) unset($a[$k]);
                    } elseif ($v === [] || $v === null) {
                        unset($a[$k]);
                    }
                }
                return $a;
            };
            return $clean($result);
        };

        foreach ($contacts as $contact) {
            // Build filtered "info"
            $info = $contact['info'] ?? $contact;
            $filteredInfo = [];
            foreach (['name','emails','phones','addresses','company','jobTitle','birthdate','locale','labelKeys','extendedFields','picture'] as $k) {
                if (isset($info[$k])) $filteredInfo[$k] = $info[$k];
            }

            foreach (['emails','phones','addresses'] as $k) {
                if (!empty($filteredInfo[$k]['items'])) {
                    $filteredInfo[$k] = ['items' => array_values($filteredInfo[$k]['items'])];
                } else {
                    unset($filteredInfo[$k]);
                }
            }

            $email = $filteredInfo['emails']['items'][0]['email'] ?? null;
            $name  = $filteredInfo['name']['first'] ?? ($filteredInfo['name']['formatted'] ?? null);

            // Minimum required fields check
            if (!$name && !$email && empty($filteredInfo['phones']['items'])) {
                $errors[] = 'Skipped (missing name/email/phone): '.json_encode(['email' => $email, 'name' => $name]);
                WixHelper::log('Import Contacts', 'Skipped contact (missing name/email/phone): '.json_encode(['email' => $email, 'name' => $name]), 'warn');
                continue;
            }

            // Existing contact check
            $existingContact = $email ? $this->findContactByEmail($accessToken, $email) : null;
            $targetContactId = null;
            $action = null;

            $targetRow = $claimPendingRow($email);
            $targetRow = $resolveTargetRow($targetRow, $email);

            if ($existingContact && isset($existingContact['id'])) {
                $mergedInfo = $mergeInfo($existingContact['info'] ?? [], $filteredInfo);
                $upd = $this->updateContactInWix($accessToken, $existingContact['id'], $mergedInfo, $existingContact['revision'] ?? 1);
                $targetContactId = $existingContact['id'];
                if (!empty($upd['contact']['id'])) {
                    $updatedContacts++;
                    $action = 'updated';
                    // Assign labels if they exist
                    if (!empty($contact['labelKeys'])) {
                        $this->labelContact($accessToken, $targetContactId, $contact['labelKeys']);
                    }
                } else {
                    $errors[] = 'Update failed: ' . json_encode(['sent' => $mergedInfo, 'response' => $upd]);
                    WixHelper::log('Import Contacts', "Update failed: " . json_encode($upd), 'warn');
                    // proceed to consents/attachments anyway with existing id
                }
                $oldId = $contact['info']['_old_id'] ?? null;
                if ($oldId) $oldContactIdToNewContactId[$oldId] = $targetContactId;
            } else {
                // Create contact
                $result = $this->createContactInWix($accessToken, $filteredInfo);
                if (isset($result['contact']['id'])) {
                    $targetContactId = $result['contact']['id'];
                    $importedContacts++;
                    $action = 'created';
                    // Assign labels if they exist
                    if (!empty($contact['labelKeys'])) {
                        $this->labelContact($accessToken, $targetContactId, $contact['labelKeys']);
                    }
                } else {
                    $errMsg = json_encode(['sent' => $filteredInfo, 'response' => $result]);
                    if ($targetRow) {
                        $targetRow->update([
                            'to_store_id' => $toStoreId,
                            'destination_contact_id' => null,
                            'status' => 'failed',
                            'error_message' => $errMsg,
                            'contact_name' => $name ?? $targetRow->contact_name,
                            'contact_email' => $targetRow->contact_email ?: ($email ?? null),
                        ]);
                    } else if ($email) {
                        WixContactMigration::updateOrCreate(
                            ['user_id' => $userId, 'from_store_id' => $fromStoreId, 'to_store_id' => $toStoreId, 'contact_email' => $email],
                            ['status' => 'failed', 'error_message' => $errMsg, 'contact_name' => $name, 'destination_contact_id' => null]
                        );
                    }
                    $errors[] = $errMsg;
                    WixHelper::log('Import Contacts', "Failed to import: {$errMsg}", 'error');
                    continue;
                }
            }

            // Upsert migration row
            if ($targetRow) {
                $targetRow->update([
                    'to_store_id' => $toStoreId,
                    'destination_contact_id' => $targetContactId,
                    'status' => 'success',
                    'error_message' => null,
                    'contact_name' => $name ?? $targetRow->contact_name,
                    'contact_email' => $targetRow->contact_email ?: ($email ?? null),
                ]);
            } else if ($email) {
                WixContactMigration::updateOrCreate(
                    ['user_id' => $userId, 'from_store_id' => $fromStoreId, 'to_store_id' => $toStoreId, 'contact_email' => $email],
                    ['destination_contact_id' => $targetContactId, 'status' => 'success', 'error_message' => null, 'contact_name' => $name]
                );
            }

            // Attachments
            if (!empty($contact['attachments']) && $targetContactId) {
                foreach ($contact['attachments'] as $att) {
                    if (!empty($att['fileName']) && !empty($att['content'])) {
                        $uploadUrl = $this->generateAttachmentUploadUrl(
                            $accessToken, $targetContactId,
                            $att['fileName'], $att['mimeType'] ?? 'application/octet-stream'
                        );
                        if (!empty($uploadUrl['uploadUrl'])) {
                            $this->uploadFileToUrl($uploadUrl['uploadUrl'], $att['content'], $att['mimeType'] ?? 'application/octet-stream');
                        }
                    }
                }
            }

            // Notes
            if (!empty($contact['notes']) && $targetContactId) {
                foreach ($contact['notes'] as $note) {
                    $this->createContactNote($accessToken, $targetContactId, $note['content'] ?? '');
                }
            }

            // Members
            if (!empty($contact['member']) && $targetContactId) {
                $member = $contact['member'];
                $memberEmail = $member['loginEmail'] ?? $email;
                $existingMember = $memberEmail ? $this->findMemberByEmail($accessToken, $memberEmail) : null;
                $newMemberId = $existingMember['id'] ?? null;

                $memberProfile = $member['profile'] ?? [];
                unset($memberProfile['slug'], $memberProfile['photo']); // Remove read-only or non-transferable fields
                $memberCustomFields = $member['customFields'] ?? [];

                if (!$newMemberId) {
                    $createBody = [
                        'loginEmail' => $memberEmail,
                        'profile'    => $memberProfile,
                        'customFields' => $memberCustomFields,
                    ];
                    $made = $this->createMemberInWix($accessToken, $createBody);
                    $newMemberId = $made['member']['id'] ?? null;
                } else {
                    if (!empty($memberProfile)) {
                        $this->updateMemberProfile($accessToken, $newMemberId, $memberProfile);
                    }
                    if (!empty($memberCustomFields)) {
                        $this->updateMemberCustomFields($accessToken, $newMemberId, $memberCustomFields);
                    }
                }

                if ($newMemberId) {
                    $oldMemberId = $member['id'] ?? null;
                    if ($oldMemberId) $oldMemberIdToNewMemberId[$oldMemberId] = $newMemberId;

                    // About
                    if (!empty($member['about'])) {
                        $payload = [
                            'memberAbout' => [
                                'memberId' => $newMemberId,
                                'content'  => $member['about']['content'] ?? '',
                                'revision' => $member['about']['revision'] ?? '0',
                            ]
                        ];
                        $existingAbout = $this->getMemberAbout($accessToken, $newMemberId);
                        if ($existingAbout) {
                            Http::withHeaders([
                                'Authorization' => $accessToken, 'Content-Type' => 'application/json'
                            ])->patch("https://www.wixapis.com/members/v2/abouts/{$existingAbout['id']}", $payload);
                        } else {
                            Http::withHeaders([
                                'Authorization' => $accessToken, 'Content-Type' => 'application/json'
                            ])->post("https://www.wixapis.com/members/v2/abouts", $payload);
                        }
                    }

                    // Badges
                    if (!empty($member['badges'])) {
                        foreach ($member['badges'] as $badge) {
                            if (!empty($badge['badgeKey'])) {
                                $this->assignBadge($accessToken, $newMemberId, $badge['badgeKey']);
                            }
                        }
                    }

                    // Following
                    if (!empty($member['following'])) {
                        foreach ($member['following'] as $followedOld) {
                            if ($followedOld && isset($oldMemberIdToNewMemberId[$followedOld])) {
                                $this->followMember($accessToken, $newMemberId, $oldMemberIdToNewMemberId[$followedOld]);
                            }
                        }
                    }
                }
            }

            // Marketing consents
            if (!empty($contact['marketingConsent']) && $targetContactId) {
                foreach (($contact['marketingConsent']['emails'] ?? []) as $normEmail => $mc) {
                    $state = strtoupper($mc['state'] ?? 'PENDING');
                    $payload = [
                        'details' => ['type' => 'EMAIL', 'email' => $normEmail],
                        'state'   => $state,
                    ];
                    if ($state === 'REVOKED') {
                        $payload['lastRevokeActivity'] = $this->buildLRA($mc['lastRevokeActivity'] ?? null);
                    } elseif (in_array($state, ['PENDING','CONFIRMED'], true)) {
                        $payload['lastConfirmationActivity'] = $this->buildLCA($mc['lastConfirmationActivity'] ?? null);
                    }
                    $this->upsertMarketingConsent($accessToken, $payload, [
                        'origin'  => 'import',
                        'kind'    => 'email',
                        'contact' => $targetContactId,
                        'email'   => $normEmail,
                    ]);
                }
                foreach (($contact['marketingConsent']['phones'] ?? []) as $e164 => $mc) {
                    $state = strtoupper($mc['state'] ?? 'PENDING');
                    $payload = [
                        'details' => ['type' => 'PHONE', 'phone' => $e164],
                        'state'   => $state,
                    ];
                    if ($state === 'REVOKED') {
                        $payload['lastRevokeActivity'] = $this->buildLRA($mc['lastRevokeActivity'] ?? null);
                    } elseif (in_array($state, ['PENDING','CONFIRMED'], true)) {
                        $payload['lastConfirmationActivity'] = $this->buildLCA($mc['lastConfirmationActivity'] ?? null);
                    }
                    $this->upsertMarketingConsent($accessToken, $payload, [
                        'origin'  => 'import',
                        'kind'    => 'phone',
                        'contact' => $targetContactId,
                        'phone'   => $e164,
                    ]);
                }
            }
        }

        $summary = "Imported: {$importedContacts}, Updated: {$updatedContacts}, Skipped: {$skippedDuplicates}";
        if ($skippedDuplicates && $duplicateEmails) {
            $summary .= '. Duplicates (sample): ' . implode(', ', array_slice(array_unique($duplicateEmails), 0, 10));
        }
        if ($errors) $summary .= '. Errors: ' . count($errors);

        if ($importedContacts + $updatedContacts > 0) {
            WixHelper::log('Import Contacts', "Done. {$summary}", 'success');
            $resp = back()->with('success', "Contact import completed. {$summary}");
            if ($errors) $resp = $resp->with('warning', 'Some contacts failed. Check logs for details.');
            return $resp;
        }

        if ($errors) {
            WixHelper::log('Import Contacts', "Done. {$summary}", 'error');
            return back()->with('error', "No contacts imported. {$summary}");
        }

        $nothingNote = $skippedDuplicates > 0
            ? "Nothing imported — all {$skippedDuplicates} were duplicates."
            : "Nothing to import.";
        WixHelper::log('Import Contacts', "Done. {$summary}. {$nothingNote}", 'info');
        return back()->with('success', "Contact import completed. {$summary}. {$nothingNote}");
    }




    /** Upsert Marketing Consent (EMAIL/PHONE). If PHONE fails, auto-retry with SMS type. */
    /**
     * Strong, verbose logger around the Marketing Consent Upsert call.
     * - DOES NOT mutate your payload (so we can see what you sent).
     * - Logs start/end, status, timing, request-id header, and a truncated body.
     * - Accepts an optional $ctx array to tag logs (origin=import/migrateAuto, email/phone, contact, etc.).
     */
    private function upsertMarketingConsent(string $token, array $payload, array $ctx = []): array
    {
        $ctxStr = function(array $a) {
            $pairs = [];
            foreach (['origin','kind','contact','email','phone'] as $k) if (!empty($a[$k])) $pairs[] = "$k={$a[$k]}";
            return $pairs ? (' ['.implode(', ', $pairs).']') : '';
        };
        $reqId  = bin2hex(random_bytes(6));
        $prefix = "MC Upsert{$ctxStr($ctx)} #{$reqId}";

        // REQUIRED by API: wrap inside {"marketingConsent": {...}}
        $wrapped = ['marketingConsent' => $payload];

        \App\Helpers\WixHelper::log($prefix, 'BEGIN payload='.json_encode($wrapped), 'info');

        $t0 = microtime(true);
        try {
            $resp = \Illuminate\Support\Facades\Http::withHeaders([
                'Authorization' => $token,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ])->withOptions([
                'http_errors' => false,
                'timeout'     => 30,
            ])->post('https://www.wixapis.com/marketing-consent/v1/marketing-consent/upsert', $wrapped);

            $ms    = (int) round((microtime(true) - $t0) * 1000);
            $code  = $resp->status();
            $hdrId = $resp->header('x-wix-request-id') ?? $resp->header('x-request-id') ?? 'N/A';
            $body  = (string) $resp->body();

            $level = $resp->successful() ? 'info' : ($code >= 500 ? 'error' : 'warn');
            \App\Helpers\WixHelper::log($prefix, "END   status={$code} time_ms={$ms} request_id={$hdrId} body={$body}", $level);

            if (!$resp->successful()) {
                $json = json_decode($body, true);
                $appCode = $json['details']['applicationError']['code'] ?? ($json['code'] ?? null);
                $appDesc = $json['details']['applicationError']['description'] ?? ($json['message'] ?? null);
                \App\Helpers\WixHelper::log($prefix, 'FAIL meta='.json_encode(['code'=>$appCode,'desc'=>$appDesc]), 'warn');
            }

            return ['ok' => $resp->successful(), 'status' => $code, 'json' => $resp->json(), 'reqId' => $reqId, 'reqHdr' => $hdrId, 'ms' => $ms];
        } catch (\Throwable $e) {
            $ms = (int) round((microtime(true) - $t0) * 1000);
            \App\Helpers\WixHelper::log($prefix, 'EXCEPTION time_ms='.$ms.' msg='.$e->getMessage(), 'error');
            return ['ok' => false, 'status' => 0, 'error' => $e->getMessage(), 'reqId' => $reqId, 'ms' => $ms];
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
                "paging" => ["limit" => 1],
                "fieldsets" => ["FULL"]
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

    private function getContactById($accessToken, $contactId)
    {
        $response = Http::withHeaders(['Authorization' => $accessToken])
            ->get("https://www.wixapis.com/contacts/v4/contacts/{$contactId}", ['fieldsets' => 'FULL']);
        return $response->ok() ? $response->json()['contact'] : null;
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

    private function updateContactInWix($accessToken, $contactId, $info, $revision)
    {
        $body = [
            'info' => $info,
            'revision' => $revision
        ];

        $response = Http::withHeaders([
            'Authorization' => $accessToken,
            'Content-Type'  => 'application/json'
        ])->patch("https://www.wixapis.com/contacts/v4/contacts/{$contactId}", $body);

        WixHelper::log('Import Contacts', "updateContactInWix → {$response->status()}", $response->ok() ? 'debug' : 'warn');

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

    // -------- Contact Notes --------
    private function getContactNotes($accessToken, $contactId)
    {
        $notes = [];
        $cursor = null;
        do {
            $query = [
                'query' => [
                    'cursor_paging' => ['limit' => 100]
                ],
                'contactId' => $contactId
            ];
            if ($cursor) $query['query']['cursor_paging']['cursor'] = $cursor;
            $resp = Http::withHeaders(['Authorization' => $accessToken])->get('https://www.wixapis.com/contacts/v4/notes', $query);
            if (!$resp->ok()) break;
            $data = $resp->json();
            $notes = array_merge($notes, $data['notes'] ?? []);
            $cursor = $data['pagingMetadata']['cursors']['next'] ?? null;
        } while ($cursor);
        return $notes;
    }

    private function createContactNote($accessToken, $contactId, $content)
    {
        $body = ['note' => ['contactId' => $contactId, 'content' => $content]];
        $resp = Http::withHeaders([
            'Authorization' => $accessToken,
            'Content-Type' => 'application/json'
        ])->post('https://www.wixapis.com/contacts/v4/notes', $body);
        return $resp->ok();
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

    private function updateMemberProfile($accessToken, $memberId, $profile)
    {
        $body = ['member' => ['profile' => $profile]];
        $resp = Http::withHeaders([
            'Authorization' => $accessToken,
            'Content-Type' => 'application/json'
        ])->patch("https://www.wixapis.com/members/v1/members/{$memberId}", $body);
        return $resp->json();
    }

    private function updateMemberCustomFields($accessToken, $memberId, $customFields)
    {
        $body = ['member' => ['customFields' => $customFields]];
        $resp = Http::withHeaders([
            'Authorization' => $accessToken,
            'Content-Type' => 'application/json'
        ])->patch("https://www.wixapis.com/members/v1/members/{$memberId}", $body);
        return $resp->json();
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


    /** normalize email */
    private function normEmail(?string $email): ?string {
        if (!$email) return null;
        $e = trim($email);
        return $e === '' ? null : mb_strtolower($e);
    }

    /** normalize to strict E.164: keep leading + and digits only; must start with + and >= 8 digits */
    private function normE164(?string $p): ?string {
        if (!$p) return null;
        $s = preg_replace('/[^+\d]/', '', trim($p));
        if (!$s || $s[0] !== '+') return null;
        // very loose length check
        return (strlen(preg_replace('/\D/', '', $s)) >= 8) ? $s : null;
    }
    /** Core Marketing-Consent query */
    private function mcQuery(string $accessToken, array $filter): ?array {
        try {
            $body = [
                'query' => [
                    'sort' => [],
                    'filter' => $filter,
                    'cursor_paging' => ['limit' => 1],
                ]
            ];
            $resp = Http::withHeaders([
                'Authorization' => $accessToken,
                'Content-Type'  => 'application/json',
            ])->post('https://www.wixapis.com/marketing-consent/v1/marketing-consent/query', $body);

            if (!$resp->ok()) {
                WixHelper::log('Export Contacts', 'MC query failed: '.$resp->status().' | '.$resp->body(), 'warn');
                return null;
            }
            $data = $resp->json();
            $rows = $data['marketingConsent'] ?? $data['results'] ?? $data['items'] ?? []; // be tolerant to shape
            if (is_array($rows) && count($rows) > 0) {
                return $rows[0]; // first match
            }
            return null;
        } catch (\Throwable $e) {
            WixHelper::log('Export Contacts', 'MC query exception: '.$e->getMessage(), 'warn');
            return null;
        }
    }

    /** Get MC by email (uses cache) */
    private function getMarketingConsentByEmail(string $accessToken, ?string $email): ?array {
        $norm = $this->normEmail($email);
        if (!$norm) return null;
        if (array_key_exists($norm, $this->mcEmailCache)) {
            return $this->mcEmailCache[$norm];
        }
        $row = $this->mcQuery($accessToken, ['details.email' => $norm]);
        return $this->mcEmailCache[$norm] = $row;
    }

    /** Get MC by phone (strict E.164; uses cache) */
    private function getMarketingConsentByPhoneE164(string $accessToken, ?string $e164): ?array {
        $norm = $this->normE164($e164);
        if (!$norm) return null;
        if (array_key_exists($norm, $this->mcPhoneCache)) {
            return $this->mcPhoneCache[$norm];
        }
        $row = $this->mcQuery($accessToken, ['details.phone' => $norm]);
        return $this->mcPhoneCache[$norm] = $row;
    }

    /** After an upsert, fetch the live MC state for email/phone and log it. */
    private function verifyMarketingConsent(string $token, string $kind, string $value): ?string
    {
        try {
            if ($kind === 'email') {
                $mc = $this->getMarketingConsentByEmail($token, $this->normEmail($value));
            } else {
                $mc = $this->getMarketingConsentByPhoneE164($token, $this->normE164($value));
            }
            $state = strtoupper($mc['state'] ?? '');
            \App\Helpers\WixHelper::log('MC Verify', "{$kind}={$value} → state={$state}", 'info');
            return $state ?: null;
        } catch (\Throwable $e) {
            \App\Helpers\WixHelper::log('MC Verify', "{$kind}={$value} EXCEPTION ".$e->getMessage(), 'warn');
            return null;
        }
    }

    private function sanitizeOptInLevel(?string $val): string
    {
        $v = strtoupper((string) $val);
        return match ($v) {
            'SINGLE', 'SINGLE_OPT_IN', 'SINGLE_CONFIRMATION' => 'SINGLE_CONFIRMATION',
            'DOUBLE', 'DOUBLE_OPT_IN', 'DOUBLE_CONFIRMATION', '' => 'DOUBLE_CONFIRMATION',
            'UNKNOWN_OPT_IN_LEVEL' => 'DOUBLE_CONFIRMATION', // exports sometimes show this; normalize
            default => 'DOUBLE_CONFIRMATION',
        };
    }

    private function buildLCA(array $src = null, string $fallbackDesc = 'Migrated consent'): array
    {
        $src = $src ?? [];
        return [
            'source'      => $src['source'] ?? 'IN_PERSON',
            'description' => $src['description'] ?? $fallbackDesc,
            'updatedDate' => $src['updatedDate'] ?? ($src['updated_date'] ?? now()->toIso8601String()),
            'optInLevel'  => $this->sanitizeOptInLevel($src['optInLevel'] ?? null),
        ];
    }

    private function buildLRA(array $src = null, string $fallbackDesc = 'Migrated revoke'): array
    {
        $src = $src ?? [];
        return [
            'source'      => $src['source'] ?? 'IN_PERSON',
            'description' => $src['description'] ?? $fallbackDesc,
            'updatedDate' => $src['updatedDate'] ?? ($src['updated_date'] ?? now()->toIso8601String()),
        ];
    }

    private function labelContact(string $accessToken, string $contactId, array $labelKeys)
    {
        $body = ['labelKeys' => $labelKeys];

        $response = Http::withHeaders([
            'Authorization' => $accessToken,
            'Content-Type' => 'application/json'
        ])->post("https://www.wixapis.com/contacts/v4/contacts/{$contactId}/labels", $body);

        if (!$response->ok()) {
            WixHelper::log('Label Contact', "Failed to assign labels to contact {$contactId}: " . $response->body(), 'warn');
        }

        return $response->ok();
    }

}

