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
    protected array $mcEmailCache = [];
    protected array $mcPhoneCache = [];

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

        WixHelper::log('Auto Contacts Migration', "Start (DUPLICATE-ONLY): {$fromLabel} → {$toLabel}", 'info');

        // TOKENS
        $fromToken = WixHelper::getAccessToken($fromStoreId);
        $toToken   = WixHelper::getAccessToken($toStoreId);
        if (!$fromToken || !$toToken) {
            WixHelper::log('Auto Contacts Migration', 'Missing access token(s).', 'error');
            return back()->with('error', 'Could not get Wix access token(s).');
        }

        // ===== LABEL HELPERS =====
        $queryAllLabels = function (string $token): array {
            $all = [];
            $offset = 0; $limit = 1000;
            do {
                $payload = [
                    'query' => [
                        'filter' => (object)[],
                        'paging' => ['limit' => $limit, 'offset' => $offset],
                        'sort'   => [['fieldName' => 'displayName', 'order' => 'ASC']]
                    ]
                ];
                $resp = Http::withHeaders([
                    'Authorization' => $token,
                    'Content-Type'  => 'application/json',
                ])->post('https://www.wixapis.com/contacts/v4/labels/query', $payload);

                WixHelper::log('Labels:Query', "offset={$offset} status={$resp->status()}", $resp->ok() ? 'info' : 'warn');
                if (!$resp->ok()) break;

                $json   = $resp->json();
                $labels = $json['labels'] ?? [];
                foreach ($labels as $l) $all[] = $l;

                $count  = $json['pagingMetadata']['count'] ?? count($labels);
                $total  = $json['pagingMetadata']['total'] ?? null;
                $offset += $count;
                if ($total !== null && $offset >= $total) break;
            } while (true);

            WixHelper::log('Labels:Query', 'total='.count($all), 'info');
            return $all;
        };

        $findOrCreateLabelKeyByName = function (string $token, string $displayName): ?string {
            $resp = Http::withHeaders([
                'Authorization' => $token,
                'Content-Type'  => 'application/json',
            ])->post('https://www.wixapis.com/contacts/v4/labels', ['displayName' => $displayName]);

            $ok    = $resp->ok();
            $json  = $resp->json();
            $label = $json['label'] ?? $json;
            $key   = is_array($label) ? ($label['key'] ?? null) : null;
            $flag  = ($json['newLabel'] ?? false) ? 'created' : 'found';

            WixHelper::log('Labels:FindOrCreate', "name=\"{$displayName}\" status={$resp->status()} result={$flag} key=".($key ?: 'NULL'), $ok ? 'info' : 'warn');
            return $key;
        };

        $attachLabelsToContact = function (string $token, string $contactId, array $labelKeys): bool {
            $labelKeys = array_values(array_unique(array_filter($labelKeys)));
            if (!$labelKeys) return true;

            $resp = Http::withHeaders([
                'Authorization' => $token,
                'Content-Type'  => 'application/json',
            ])->post("https://www.wixapis.com/contacts/v4/contacts/{$contactId}/labels", [
                'labelKeys' => $labelKeys
            ]);

            $ok = $resp->ok();
            WixHelper::log(
                'Labels:Attach',
                "contact={$contactId} keys=[".implode(',', $labelKeys)."] status={$resp->status()} body=".$resp->body(),
                $ok ? 'info' : 'warn'
            );
            return $ok;
        };

        $extractSrcLabelKeys = function (array $c): array {
            if (!empty($c['labelKeys'])) {
                if (isset($c['labelKeys']['items']) && is_array($c['labelKeys']['items'])) {
                    return array_values(array_unique(array_filter($c['labelKeys']['items'], 'is_string')));
                }
                if (is_array($c['labelKeys'])) {
                    return array_values(array_unique(array_filter($c['labelKeys'], 'is_string')));
                }
            }
            if (!empty($c['info']['labelKeys']['items']) && is_array($c['info']['labelKeys']['items'])) {
                return array_values(array_unique(array_filter($c['info']['labelKeys']['items'], 'is_string')));
            }
            return [];
        };

        // Warm up source labels, then ensure they exist on target
        $srcLabels        = $queryAllLabels($fromToken);
        $srcKeyToName     = [];
        $uniqueLabelNames = [];
        foreach ($srcLabels as $L) {
            $name = $L['displayName'] ?? null;
            $key  = $L['key'] ?? null;
            if ($key && $name) {
                $srcKeyToName[$key] = $name;
                $uniqueLabelNames[$name] = true;
            }
        }
        $uniqueLabelNames = array_keys($uniqueLabelNames);
        WixHelper::log('Labels:Warmup', 'unique_names='.count($uniqueLabelNames), 'info');

        $tgtNameToKey = [];
        foreach ($uniqueLabelNames as $name) {
            $k = $findOrCreateLabelKeyByName($toToken, $name);
            if ($k) $tgtNameToKey[$name] = $k;
        }
        WixHelper::log('Labels:Warmup', 'ensured_on_target='.count($tgtNameToKey), 'info');

        // Helper to deep-merge (kept for completeness; not used to update in duplicate-only mode)
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

            foreach (['company','jobTitle','birthdate','locale','picture','assignedUserIds'] as $k) {
                if (isset($incoming[$k])) $result[$k] = $incoming[$k];
            }

            if (isset($result['labelKeys'])) unset($result['labelKeys']);
            if (isset($result['assignedUserIds'])) unset($result['assignedUserIds']);

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
                    } elseif ($v === [] || $v === null) unset($a[$k]);
                }
                return $a;
            };
            return $clean($result);
        };

        // Pull source contacts (FULL) and sort oldest → newest
        $contacts = $this->getContactsFromWix($fromToken);
        if (isset($contacts['error'])) {
            WixHelper::log('Auto Contacts Migration', "Source API error: ".$contacts['raw'], 'error');
            return back()->with('error', 'Failed to fetch contacts from source.');
        }
        if ($max > 0) { $contacts = array_slice($contacts, 0, $max); }

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

        // Source extended-field defs (for displayName mapping)
        $sourceExtDefs = $this->getAllExtendedFields($fromToken);

        // Counters
        $imported = 0; $updated = 0; $skipped = 0; $failed = 0;

        // Member replay caches
        $oldMemberIdToNew = [];
        $sourceMemberBadges    = [];
        $sourceMemberFollowing = [];

        // Assigned-user mapping caches
        $oldContactIdToNew = [];     // [oldContactId => newContactId]
        $pendingAssigned   = [];     // [newContactId => string[]] (emails, userIds, or old contactIds)

        $allowedInfoKeys = [
            'name','emails','phones','addresses','company','jobTitle',
            'birthdate','locale','extendedFields','picture','assignedUserIds'
        ];

        foreach ($contacts as $src) {
            // ---------- Build filtered "info" ----------
            $info = $src['info'] ?? $src;
            $filtered = [];
            foreach ($allowedInfoKeys as $k) if (isset($info[$k])) $filtered[$k] = $info[$k];

            foreach (['emails','phones','addresses'] as $k) {
                if (!empty($filtered[$k]['items'])) {
                    $filtered[$k] = ['items' => array_values($filtered[$k]['items'])];
                } else {
                    unset($filtered[$k]);
                }
            }

            // Capture & log assignedUserIds from source; do NOT send in create/update
            $srcAssigned = [];
            if (!empty($info['assignedUserIds']['items']) && is_array($info['assignedUserIds']['items'])) {
                $srcAssigned = array_values(array_filter($info['assignedUserIds']['items'], 'is_string'));
            }
            WixHelper::log(
                'AssignedUsers',
                'SRC_CAPTURE migrateAuto'
                .' | src_contact_id='.($src['id'] ?? 'NULL')
                .' | primary_email='.($filtered['emails']['items'][0]['email'] ?? 'NULL')
                .' | raw_items='.json_encode($srcAssigned),
                'info'
            );
            unset($filtered['assignedUserIds']);

            // Extended fields → target by displayName; keep invoices.vatId
            $targetExtItems = [];
            $srcExtItems = $info['extendedFields']['items'] ?? [];
            foreach ($srcExtItems as $key => $val) {
                $def = $sourceExtDefs[$key] ?? null;
                if ($key === 'invoices.vatId') { $targetExtItems[$key] = $val; continue; }
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

            $filtered = $this->cleanEmpty($filtered);

            // Basic identity
            $email = $filtered['emails']['items'][0]['email'] ?? null;
            $name  = $filtered['name']['first'] ?? ($filtered['name']['formatted'] ?? null);

            // ---------- SOURCE Marketing Consents ----------
            $mcEmails = []; $mcPhones = [];
            $primaryEmail = $src['primaryEmail']['email'] ?? null;
            if ($primaryEmail) {
                $mc = $this->getMarketingConsentByEmail($fromToken, $primaryEmail);
                if ($mc) $mcEmails[$this->normEmail($primaryEmail)] = $mc;
            }
            $emailsItems = $src['info']['emails']['items'] ?? [];
            foreach ($emailsItems as $em) {
                $e = $this->normEmail($em['email'] ?? null);
                if ($e && !isset($mcEmails[$e])) {
                    $mc = $this->getMarketingConsentByEmail($fromToken, $e);
                    if ($mc) $mcEmails[$e] = $mc;
                }
            }
            $primaryE164 = $src['primaryPhone']['e164Phone'] ?? null;
            if ($primaryE164) {
                $norm = $this->normE164($primaryE164);
                if ($norm) {
                    $mc = $this->getMarketingConsentByPhoneE164($fromToken, $norm);
                    if ($mc) $mcPhones[$norm] = $mc;
                }
            }
            $phonesItems = $src['info']['phones']['items'] ?? [];
            foreach ($phonesItems as $ph) {
                $e164 = $this->normE164($ph['e164Phone'] ?? null);
                if ($e164 && !isset($mcPhones[$e164])) {
                    $mc = $this->getMarketingConsentByPhoneE164($fromToken, $e164);
                    if ($mc) $mcPhones[$e164] = $mc;
                }
            }
            $src['marketingConsent'] = ['emails' => $mcEmails, 'phones' => $mcPhones];

            // ---------- Compute target labelKeys ----------
            $srcKeysForContact = $extractSrcLabelKeys($src);
            $targetLabelKeys   = [];
            foreach ($srcKeysForContact as $sKey) {
                $dn = $srcKeyToName[$sKey] ?? null;
                if ($dn && !empty($tgtNameToKey[$dn])) {
                    $targetLabelKeys[] = $tgtNameToKey[$dn];
                } elseif ($dn) {
                    $k = $findOrCreateLabelKeyByName($toToken, $dn);
                    if ($k) { $tgtNameToKey[$dn] = $k; $targetLabelKeys[] = $k; }
                    else { WixHelper::log('Labels:Map', "could_not_map name=\"{$dn}\" (sourceKey={$sKey})", 'warn'); }
                }
            }
            $targetLabelKeys = array_values(array_unique($targetLabelKeys));

            // ---------- Required fields check ----------
            if (!$name && !$email && empty($filtered['phones']['items'])) {
                $failed++;
                if ($email) {
                    WixContactMigration::updateOrCreate(
                        [
                            'user_id'       => $userId,
                            'from_store_id' => $fromStoreId,
                            'to_store_id'   => $toStoreId,
                            'contact_email' => $email,
                        ],
                        [
                            'destination_contact_id'  => null,
                            'status'                  => 'failed',
                            'error_message'           => 'Missing required fields (name/email/phone)',
                            'contact_name'            => $name,
                        ]
                    );
                }
                continue;
            }

            // ---------- ALWAYS CREATE on TARGET (labels/assigned not in body) ----------
            $created = $this->createContactInWix($toToken, $filtered);
            $targetContactId = $created['contact']['id'] ?? null;

            if ($targetContactId) {
                $imported++;
            } else {
                $failed++;
                $err = json_encode(['sent' => $filtered, 'response' => $created]);
                if ($email) {
                    WixContactMigration::updateOrCreate(
                        [
                            'user_id'       => $userId,
                            'from_store_id' => $fromStoreId,
                            'to_store_id'   => $toStoreId,
                            'contact_email' => $email,
                        ],
                        [
                            'destination_contact_id'  => null,
                            'status'                  => 'failed',
                            'error_message'           => $err,
                            'contact_name'            => $name,
                        ]
                    );
                }
                WixHelper::log('Auto Contacts Migration', "Create failed (dup-mode): {$err}", 'warn');
                continue;
            }

            // ---------- Assign labels now ----------
            if ($targetContactId && $targetLabelKeys) {
                $attachLabelsToContact($toToken, $targetContactId, $targetLabelKeys);
            }

            // ---------- Queue assignedUserIds mapping for PATCH ----------
            if (!empty($src['id']) && $targetContactId) {
                $oldContactIdToNew[$src['id']] = $targetContactId;
                if (!empty($srcAssigned)) {
                    $pendingAssigned[$targetContactId] = $srcAssigned;
                    WixHelper::log(
                        'AssignedUsers',
                        'QUEUE migrateAuto'
                        .' | src_contact_id='.$src['id']
                        .' | tgt_contact_id='.$targetContactId
                        .' | queued_items='.json_encode($srcAssigned),
                        'info'
                    );
                }
            }

            // ---------- Upsert/refresh migration row ----------
            if ($email) {
                WixContactMigration::updateOrCreate(
                    [
                        'user_id'       => $userId,
                        'from_store_id' => $fromStoreId,
                        'to_store_id'   => $toStoreId,
                        'contact_email' => $email,
                    ],
                    [
                        'destination_contact_id'  => $targetContactId,
                        'status'                  => 'success',
                        'error_message'           => null,
                        'contact_name'            => $name,
                    ]
                );
            }

            // ---------- Attachments ----------
            if ($copyAttach && !empty($src['id']) && $targetContactId) {
                try {
                    $atts = $this->listContactAttachments($fromToken, $src['id']);
                    foreach ($atts as $att) {
                        $file = $this->downloadContactAttachment($fromToken, $src['id'], $att['id']);
                        if ($file && !empty($file['content'])) {
                            $uploadUrl = $this->generateAttachmentUploadUrl(
                                $toToken, $targetContactId,
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

            // ---------- Notes ----------
            if (!empty($src['id']) && $targetContactId) {
                try {
                    $notes = $this->getContactNotes($fromToken, $src['id']);
                    foreach ($notes as $note) {
                        $this->createContactNote($toToken, $targetContactId, $note['content'] ?? '');
                    }
                } catch (\Throwable $e) {
                    WixHelper::log('Auto Contacts Migration', 'Notes copy failed: '.$e->getMessage(), 'warn');
                }
            }

            // ---------- Email subscription ----------
            if (!empty($email)) {
                $desired = strtoupper((string)($src['primaryEmail']['subscriptionStatus'] ?? $defaultSub));
                $this->upsertEmailSubscription($toToken, $email, $desired);
            }

            // ---------- Marketing Consent UPSERT to TARGET ----------
            if (!empty($src['marketingConsent'])) {
                // EMAIL consents
                foreach (($src['marketingConsent']['emails'] ?? []) as $normEmail => $mc) {
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
                    $this->upsertMarketingConsent($toToken, $payload, [
                        'origin'  => 'migrateAuto',
                        'kind'    => 'email',
                        'contact' => $targetContactId ?? null,
                        'email'   => $normEmail,
                    ]);

                    $live = $this->verifyMarketingConsent($toToken, 'email', $normEmail);
                    if ($state === 'CONFIRMED' && $live !== 'CONFIRMED') {
                        WixHelper::log('MC Verify', "EMAIL mismatch wanted=CONFIRMED got=".($live ?: 'NULL')." email={$normEmail}", 'warn');
                    }
                }

                // PHONE consents
                foreach (($src['marketingConsent']['phones'] ?? []) as $e164 => $mc) {
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
                    $this->upsertMarketingConsent($toToken, $payload, [
                        'origin'  => 'migrateAuto',
                        'kind'    => 'phone',
                        'contact' => $targetContactId ?? null,
                        'phone'   => $e164,
                    ]);

                    $live = $this->verifyMarketingConsent($toToken, 'phone', $e164);
                    if ($state === 'CONFIRMED' && $live !== 'CONFIRMED') {
                        WixHelper::log('MC Verify', "PHONE mismatch wanted=CONFIRMED got=".($live ?: 'NULL')." phone={$e164}. Retrying as SMS.", 'warn');
                        $retry = $payload; $retry['details']['type'] = 'SMS';
                        $this->upsertMarketingConsent($toToken, $retry, [
                            'origin'  => 'migrateAuto',
                            'kind'    => 'phone_sms_retry',
                            'contact' => $targetContactId ?? null,
                            'phone'   => $e164,
                        ]);
                        $live2 = $this->verifyMarketingConsent($toToken, 'phone', $e164);
                        if ($live2 !== 'CONFIRMED') {
                            WixHelper::log('MC Verify', "PHONE still not CONFIRMED after SMS retry; got=".($live2 ?: 'NULL')." phone={$e164}", 'warn');
                        }
                    }
                }
            }

            // ---------- Members (optional) ----------
            if ($copyMembers && !empty($src['id'])) {
                $srcMember = $this->findMemberByContactId($fromToken, $src['id']);
                if ($srcMember) {
                    $oldMemberId = $srcMember['id'] ?? null;
                    $memberEmail = $srcMember['loginEmail'] ?? ($email ?? null);

                    $existingMember = $memberEmail ? $this->findMemberByEmail($toToken, $memberEmail) : null;
                    $newMemberId    = $existingMember['id'] ?? null;

                    $memberProfile = $srcMember['profile'] ?? [];
                    unset($memberProfile['slug'], $memberProfile['photo']);

                    $memberCustomFields = $srcMember['customFields'] ?? [];

                    if (!$newMemberId) {
                        $createBody = [
                            'loginEmail'   => $memberEmail,
                            'profile'      => $memberProfile,
                            'customFields' => $memberCustomFields,
                        ];
                        $made = $this->createMemberInWix($toToken, $createBody);
                        $newMemberId = $made['member']['id'] ?? null;
                    } else {
                        if (!empty($memberProfile)) {
                            $this->updateMemberProfile($toToken, $newMemberId, $memberProfile);
                        }
                        if (!empty($memberCustomFields)) {
                            $this->updateMemberCustomFields($toToken, $newMemberId, $memberCustomFields);
                        }
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
        } // foreach contacts

        // PASS 2: replay member relationships (badges & following)
        if ($copyMembers && $oldMemberIdToNew) {
            foreach ($oldMemberIdToNew as $oldId => $newId) {
                foreach ($sourceMemberBadges[$oldId] ?? [] as $badge) {
                    if (!empty($badge['badgeKey'])) $this->assignBadge($toToken, $newId, $badge['badgeKey']);
                }
                foreach ($sourceMemberFollowing[$oldId] ?? [] as $f) {
                    $followedOld = $f['id'] ?? null;
                    if ($followedOld && !empty($oldMemberIdToNew[$followedOld])) {
                        $this->followMember($toToken, $newId, $oldMemberIdToNew[$followedOld]);
                    }
                }
            }
        }

        // PASS 2B: Map & PATCH assignedUserIds (same mapping logic)
        $uuidRegex = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

        foreach ($pendingAssigned as $targetContactId => $items) {
            WixHelper::log(
                'AssignedUsers',
                'MAP_START migrateAuto'
                .' | tgt_contact_id='.$targetContactId
                .' | raw_items='.json_encode($items),
                'info'
            );

            $mapped = [];
            foreach ($items as $item) {
                if (!is_string($item) || $item === '') {
                    WixHelper::log('AssignedUsers', 'SKIP_ITEM migrateAuto | reason=not_string_or_empty | item='.json_encode($item), 'warn');
                    continue;
                }

                // 1) Email path
                if (strpos($item, '@') !== false) {
                    $found = $this->findContactByEmail($toToken, $item);
                    $foundId = $found['id'] ?? null;
                    WixHelper::log(
                        'AssignedUsers',
                        'RESOLVE_EMAIL migrateAuto'
                        .' | email='.$item
                        .' | resolved_id='.($foundId ?? 'NULL'),
                        $foundId ? 'info' : 'warn'
                    );
                    if ($foundId) { $mapped[] = $foundId; continue; }
                }

                // 2) Old contactId path
                $newId = $oldContactIdToNew[$item] ?? null;
                WixHelper::log(
                    'AssignedUsers',
                    'RESOLVE_OLD_ID migrateAuto'
                    .' | old_id='.$item
                    .' | mapped_new_id='.($newId ?? 'NULL'),
                    $newId ? 'info' : 'warn'
                );
                if ($newId) { $mapped[] = $newId; continue; }

                // 3) Member lookup on SOURCE by ID → email → target contact
                if (preg_match($uuidRegex, $item)) {
                    try {
                        $mResp = Http::withHeaders([
                            'Authorization' => $fromToken,
                            'Content-Type'  => 'application/json',
                        ])->get("https://www.wixapis.com/members/v1/members/{$item}");

                        WixHelper::log(
                            'AssignedUsers',
                            'MEMBER_LOOKUP migrateAuto'
                            .' | src_member_id='.$item
                            .' | status='.$mResp->status()
                            .' | ok='.($mResp->ok() ? '1':'0'),
                            $mResp->ok() ? 'info' : 'warn'
                        );

                        if ($mResp->ok()) {
                            $mj   = $mResp->json();
                            $mEmail = $mj['member']['loginEmail'] ?? ($mj['loginEmail'] ?? null);
                            WixHelper::log(
                                'AssignedUsers',
                                'MEMBER_EMAIL migrateAuto'
                                .' | src_member_id='.$item
                                .' | email='.($mEmail ?? 'NULL'),
                                $mEmail ? 'info' : 'warn'
                            );
                            if ($mEmail) {
                                $found = $this->findContactByEmail($toToken, $mEmail);
                                $foundId = $found['id'] ?? null;
                                WixHelper::log(
                                    'AssignedUsers',
                                    'EMAIL_TO_TARGET_CONTACT migrateAuto'
                                    .' | email='.$mEmail
                                    .' | resolved_id='.($foundId ?? 'NULL'),
                                    $foundId ? 'info' : 'warn'
                                );
                                if ($foundId) { $mapped[] = $foundId; continue; }
                            }
                        }
                    } catch (\Throwable $e) {
                        WixHelper::log('AssignedUsers', 'MEMBER_LOOKUP_ERR migrateAuto | id='.$item.' | err='.$e->getMessage(), 'warn');
                    }
                }

                // 4) As a last resort, check if the ID already exists as a contact on TARGET
                if (preg_match($uuidRegex, $item)) {
                    $cResp = Http::withHeaders([
                        'Authorization' => $toToken,
                        'Content-Type'  => 'application/json',
                    ])->get("https://www.wixapis.com/contacts/v4/contacts/{$item}");

                    WixHelper::log(
                        'AssignedUsers',
                        'TARGET_CONTACT_PROBE migrateAuto'
                        .' | probe_id='.$item
                        .' | status='.$cResp->status()
                        .' | ok='.($cResp->ok() ? '1':'0'),
                        $cResp->ok() ? 'info' : 'warn'
                    );
                    if ($cResp->ok()) {
                        $mapped[] = $item;
                        continue;
                    }
                }

                // 5) Name-like string (no @ and not UUID) → skip but log
                if (strpos($item, '@') === false && !preg_match($uuidRegex, $item)) {
                    WixHelper::log('AssignedUsers', 'UNRESOLVED_NAME migrateAuto | value='.$item.' | hint=provide email for deterministic mapping', 'warn');
                }
            }

            $mapped = array_values(array_unique(array_filter($mapped)));
            WixHelper::log(
                'AssignedUsers',
                'MAP_RESULT migrateAuto'
                .' | tgt_contact_id='.$targetContactId
                .' | mapped_ids='.json_encode($mapped),
                $mapped ? 'info' : 'warn'
            );

            if (!$mapped) {
                WixHelper::log('AssignedUsers', 'PATCH_SKIP migrateAuto | reason=no_mapped_ids | tgt_contact_id='.$targetContactId, 'warn');
                continue;
            }

            $patchBody = ['info' => ['assignedUserIds' => ['items' => $mapped]]];
            WixHelper::log(
                'AssignedUsers',
                'PATCH_REQUEST migrateAuto'
                .' | tgt_contact_id='.$targetContactId
                .' | body='.json_encode($patchBody),
                'info'
            );

            $resp = Http::withHeaders([
                'Authorization' => $toToken,
                'Content-Type'  => 'application/json',
            ])->patch("https://www.wixapis.com/contacts/v4/contacts/{$targetContactId}", $patchBody);

            WixHelper::log(
                'AssignedUsers',
                'PATCH_RESPONSE migrateAuto'
                .' | tgt_contact_id='.$targetContactId
                .' | status='.$resp->status()
                .' | ok='.($resp->ok() ? '1':'0')
                .' | body='.$resp->body(),
                $resp->ok() ? 'success' : 'error'
            );
        }

        $summary = "Contacts: imported={$imported}, updated={$updated}, skipped={$skipped}, failed={$failed}";

        if ($imported + $updated > 0) {
            WixHelper::log('Auto Contacts Migration', "Done. {$summary}", $failed ? 'warn' : 'success');
            $resp = back()->with('success', "Contacts auto-migration completed. {$summary}");
            if ($failed > 0) $resp = $resp->with('warning', 'Some contacts failed to migrate. Check logs for details.');
            return $resp;
        }

        if ($failed > 0) {
            WixHelper::log('Auto Contacts Migration', "Done. {$summary}", 'error');
            return back()->with('error', "No contacts imported. {$summary}");
        }

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
    public function export(Request $request, WixStore $store)
    {
        $userId      = Auth::id() ?: 1;
        $fromStoreId = $store->instance_id;

        // Manual options to mirror migrateAuto
        $max         = (int) ($request->input('max', 0));
        $copyMembers = (bool) $request->boolean('include_members', true);
        $copyAttach  = (bool) $request->boolean('include_attachments', true);

        // ---- Optional Pacific Time date-range filter ----
        // use_date_range=1, date_field=created|updated, start_date & end_date (YYYY-MM-DD or DD.MM.YYYY)
        $useRange   = $request->boolean('use_date_range', false);
        $dateField  = strtolower((string)$request->input('date_field', 'created')) === 'updated' ? 'updatedDate' : 'createdDate';
        $startInput = $request->input('start_date');
        $endInput   = $request->input('end_date');

        WixHelper::log(
            'Export Contacts',
            "Start: {$store->store_name} ({$fromStoreId}) [max={$max}, members=" . ($copyMembers?'1':'0') . ", attach=" . ($copyAttach?'1':'0') . ", use_range=" . ($useRange?'1':'0') . ", field={$dateField}]",
            'info'
        );

        $accessToken = WixHelper::getAccessToken($fromStoreId);
        if (!$accessToken) {
            WixHelper::log('Export Contacts', "Unauthorized: Could not get access token for instanceId: {$fromStoreId}.", 'error');
            return response()->json(['error' => 'You are not authorized to access.'], 401);
        }

        // ===== Fetch contacts (ALL vs filtered by date range) =====
        if ($useRange) {
            [$gteIso, $lteIso] = $this->buildPacificIsoDateRange($startInput, $endInput);
            if (!$gteIso || !$lteIso) {
                return response()->json(['error' => 'Invalid date range. Provide start_date and end_date as YYYY-MM-DD or DD.MM.YYYY'], 422);
            }

            // Use $and shape — Wix is sometimes picky about combined range objects
            $filter = [
                '$and' => [
                    [$dateField => ['$gte' => $gteIso]],
                    [$dateField => ['$lte' => $lteIso]],
                ]
            ];

            $contacts = $this->getContactsFromWixByQuery($accessToken, $filter, $dateField);
            if (isset($contacts['error'])) {
                WixHelper::log('Export Contacts', "API error (query): status={$contacts['status']} body={$contacts['raw']}", 'error');
                return response()->json([
                    'error'  => $contacts['error'],
                    'status' => $contacts['status'] ?? null,
                    'detail' => $contacts['raw'] ?? null,
                    'sent'   => $contacts['sent'] ?? null,
                ], 500);
            }
            WixHelper::log('Export Contacts', "Date filter {$dateField}: {$gteIso} → {$lteIso}. Returned ".count($contacts)." contact(s).", 'info');
        } else {
            $contacts = $this->getContactsFromWix($accessToken);
            if (isset($contacts['error'])) {
                WixHelper::log('Export Contacts', "API error: " . $contacts['raw'], 'error');
                return response()->json(['error' => $contacts['error']], 500);
            }
            WixHelper::log('Export Contacts', "No date filter. Returned ".count($contacts)." contact(s).", 'info');
        }

        // -------- Query ALL labels once & build catalogs (same as your original) --------
        $queryAllLabels = function (string $token): array {
            $all = []; $offset = 0; $limit = 1000;
            do {
                $payload = [
                    'query' => [
                        'filter' => (object)[],
                        'paging' => ['limit' => $limit, 'offset' => $offset],
                        'sort'   => [['fieldName' => 'displayName', 'order' => 'ASC']]
                    ]
                ];
                $resp = Http::withHeaders([
                    'Authorization' => $token,
                    'Content-Type'  => 'application/json',
                ])->post('https://www.wixapis.com/contacts/v4/labels/query', $payload);

                WixHelper::log('Labels:Query', "offset={$offset} status={$resp->status()}", $resp->ok() ? 'info' : 'warn');
                if (!$resp->ok()) break;

                $json   = $resp->json();
                $labels = $json['labels'] ?? [];
                foreach ($labels as $l) $all[] = $l;

                $count  = $json['pagingMetadata']['count'] ?? count($labels);
                $total  = $json['pagingMetadata']['total'] ?? null;
                $offset += $count;
                if ($total !== null && $offset >= $total) break;
            } while (true);
            return $all;
        };

        // key => displayName catalog
        $labelMap = [];
        foreach ($queryAllLabels($accessToken) as $L) {
            $key = $L['key'] ?? null;
            $dn  = $L['displayName'] ?? null;
            if ($key && $dn) $labelMap[$key] = $dn;
        }
        WixHelper::log('Labels:Query', 'total='.count($labelMap), 'info');

        // --- Extended Field defs (for humanized list) ---
        $extendedFieldDefs = $this->getAllExtendedFields($accessToken);

        // ===== Transform contacts (FULL, same as your original) =====
        foreach ($contacts as &$contact) {
            // carry _old_id so import can map relationships (assignedUserIds, etc.)
            if (!isset($contact['info'])) { $contact['info'] = []; }
            $contact['info']['_old_id'] = $contact['id'] ?? null;

            // ===== Resolve label KEYS from both possible shapes =====
            $keys = [];
            if (!empty($contact['labelKeys'])) {
                $keys = is_array($contact['labelKeys']) && isset($contact['labelKeys']['items'])
                    ? (array) $contact['labelKeys']['items']
                    : (array) $contact['labelKeys'];
            }
            if (empty($keys) && !empty($contact['info']['labelKeys']['items'])) {
                $keys = (array) $contact['info']['labelKeys']['items'];
            }

            // Labels => humanized for import
            $contact['labels'] = [];
            foreach ($keys as $key) {
                $contact['labels'][] = [
                    'key'         => $key,
                    'displayName' => $labelMap[$key] ?? $key,
                ];
            }

            // Extended fields (user-defined only for humanized list; raw info.extendedFields stays intact)
            $contact['extendedFields'] = [];
            $items = $contact['info']['extendedFields']['items'] ?? [];
            foreach ($items as $key => $value) {
                $def = $extendedFieldDefs[$key] ?? null;
                if (!isset($def['dataType']) || !isset($def['displayName'])) continue;
                if ($this->isSystemExtendedField($def['displayName'])) continue;
                $contact['extendedFields'][] = [
                    'key'         => $key,
                    'displayName' => $def['displayName'],
                    'dataType'    => $def['dataType'],
                    'value'       => $value,
                ];
            }

            // Attachments (toggle)
            $contact['attachments'] = [];
            if ($copyAttach && !empty($contact['id'])) {
                $atts = $this->listContactAttachments($accessToken, $contact['id']);
                foreach ($atts as $att) {
                    $attFile = $this->downloadContactAttachment($accessToken, $contact['id'], $att['id']);
                    $contact['attachments'][] = [
                        'fileName'       => $att['fileName'],
                        'mimeType'       => $att['mimeType'],
                        'meta'           => $att,
                        'content_base64' => isset($attFile['content']) ? base64_encode($attFile['content']) : null
                    ];
                }
            }

            // Notes
            $contactId = $contact['id'] ?? null;
            $contact['notes'] = $contactId ? $this->getContactNotes($accessToken, $contactId) : [];

            // Member (toggle)
            $contact['member'] = null;
            if ($copyMembers && $contactId) {
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
                    $memberAbout = $this->getMemberAbout($accessToken, $memberId);
                    if ($memberAbout) $contact['member']['about'] = $memberAbout;
                }
            }

            // Marketing Consent (emails + phones)
            $mcEmails = []; $mcPhones = [];
            $primaryEmail = $contact['primaryEmail']['email'] ?? null;
            if ($primaryEmail) {
                $mc = $this->getMarketingConsentByEmail($accessToken, $this->normEmail($primaryEmail));
                if ($mc) $mcEmails[$this->normEmail($primaryEmail)] = $mc;
            }
            foreach (($contact['info']['emails']['items'] ?? []) as $em) {
                $e = $this->normEmail($em['email'] ?? null);
                if ($e && !isset($mcEmails[$e])) {
                    $mc = $this->getMarketingConsentByEmail($accessToken, $e);
                    if ($mc) $mcEmails[$e] = $mc;
                }
            }
            $primaryE164 = $contact['primaryPhone']['e164Phone'] ?? null;
            if ($primaryE164) {
                $normPhone = $this->normE164($primaryE164);
                if ($normPhone) {
                    $mc = $this->getMarketingConsentByPhoneE164($accessToken, $normPhone);
                    if ($mc) $mcPhones[$normPhone] = $mc;
                }
            }
            foreach (($contact['info']['phones']['items'] ?? []) as $ph) {
                $e164 = $this->normE164($ph['e164Phone'] ?? null);
                if ($e164 && !isset($mcPhones[$e164])) {
                    $mc = $this->getMarketingConsentByPhoneE164($accessToken, $e164);
                    if ($mc) $mcPhones[$e164] = $mc;
                }
            }
            $contact['marketingConsent'] = ['emails' => $mcEmails, 'phones' => $mcPhones];
        }
        unset($contact);

        // Oldest-first
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

        if ($max > 0) {
            $contacts = array_slice($contacts, 0, $max);
            WixHelper::log('Export Contacts', "Applied max={$max}, exporting ".count($contacts)." contact(s).", 'info');
        }

        // Append-only pending rows
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

        // Members custom fields meta
        $customFields = $this->getAllCustomFields($accessToken);
        $customFieldIds = array_map(fn($f) => $f['id'], $customFields);
        $customFieldApplications = $this->getCustomFieldApplications($accessToken, $customFieldIds);

        $payload = [
            'tokem'                    => $accessToken, // kept key name as in your original
            'from_store_id'            => $fromStoreId,
            'contacts'                 => $contacts,
            'customFields'             => $customFields,
            'customFieldApplications'  => $customFieldApplications,
            'labels_catalog'           => $labelMap,
            '_export_meta'             => [
                'include_members'     => $copyMembers,
                'include_attachments' => $copyAttach,
                'max'                 => $max,
                'date_filter'         => $useRange ? [
                    'field'       => $dateField,
                    'start_input' => $startInput,
                    'end_input'   => $endInput,
                    'tz'          => 'America/Los_Angeles',
                ] : null,
                'exported_at'         => now()->toIso8601String(),
            ],
        ];

        WixHelper::log('Export Contacts', "Done. Exported ".count($contacts)." contact(s); saved {$pendingSaved} pending row(s).", 'success');

        return response()->streamDownload(function() use ($payload) {
            echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }, 'contacts.json', ['Content-Type' => 'application/json']);
    }

    // =========================================================
    // Import Contacts — oldest-first, ALWAYS CREATE (no updates)
    // Mirrors migrateAuto options: max, include_members, include_attachments, default_sub_status
    // =========================================================
    public function import(Request $request, WixStore $store)
    {
        $userId    = Auth::id() ?: 1;
        $toStoreId = $store->instance_id;

        // Manual options to mirror migrateAuto
        $max           = (int) ($request->input('max', 0));
        $copyMembers   = (bool) $request->boolean('include_members', true);
        $copyAttach    = (bool) $request->boolean('include_attachments', true);
        $defaultSub    = strtoupper((string) ($request->input('default_sub_status', 'SUBSCRIBED')));

        WixHelper::log('Import Contacts', "Start: {$store->store_name} ({$toStoreId}) [max={$max}, members=" . ($copyMembers?'1':'0') . ", attach=" . ($copyAttach?'1':'0') . ", default_sub={$defaultSub}]", 'info');

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

        // catalogs to resolve label keys → display names
        $sourceToken       = $decoded['tokem'] ?? null;            // original export token (key kept as in export)
        $labelsCatalog     = $decoded['labels_catalog'] ?? [];     // key => displayName

        // ---------- LABEL HELPERS ----------
        $getLabelDisplayNameFromSource = function (string $key) use ($labelsCatalog, $sourceToken) : ?string {
            if (!empty($labelsCatalog[$key])) return $labelsCatalog[$key];
            if (!$sourceToken) return null;
            $resp = Http::withHeaders([
                'Authorization' => $sourceToken,
                'Content-Type'  => 'application/json',
            ])->get('https://www.wixapis.com/contacts/v4/labels/'.urlencode($key));
            if ($resp->ok()) {
                $json = $resp->json();
                $dn   = $json['label']['displayName'] ?? ($json['displayName'] ?? null);
                WixHelper::log('Labels:SourceLookup', "key={$key} dn=".($dn ?: 'NULL')." status={$resp->status()}", 'info');
                return $dn ?: null;
            }
            WixHelper::log('Labels:SourceLookup', "key={$key} status={$resp->status()} body=".$resp->body(), 'warn');
            return null;
        };

        $checkTargetHasKey = function (string $token, string $key): bool {
            $resp = Http::withHeaders([
                'Authorization' => $token,
                'Content-Type'  => 'application/json',
            ])->get('https://www.wixapis.com/contacts/v4/labels/'.urlencode($key));
            return $resp->ok();
        };

        $findOrCreateLabelKeyByName = function (string $token, string $displayName): ?string {
            $resp = Http::withHeaders([
                'Authorization' => $token,
                'Content-Type'  => 'application/json',
            ])->post('https://www.wixapis.com/contacts/v4/labels', ['displayName' => $displayName]);

            $ok    = $resp->ok();
            $json  = $resp->json();
            $label = $json['label'] ?? $json;
            $key   = is_array($label) ? ($label['key'] ?? null) : null;
            $flag  = ($json['newLabel'] ?? false) ? 'created' : 'found';

            WixHelper::log('Labels:FindOrCreate', "name=\"{$displayName}\" status={$resp->status()} result={$flag} key=".($key ?: 'NULL'), $ok ? 'info' : 'warn');
            return $key;
        };

        $attachLabelsToContact = function (string $token, string $contactId, array $labelKeys): bool {
            $labelKeys = array_values(array_unique(array_filter($labelKeys)));
            if (!$labelKeys) return true;

            $resp = Http::withHeaders([
                'Authorization' => $token,
                'Content-Type'  => 'application/json',
            ])->post("https://www.wixapis.com/contacts/v4/contacts/{$contactId}/labels", [
                'labelKeys' => $labelKeys
            ]);

            $ok = $resp->ok();
            WixHelper::log('Labels:Attach', "contact={$contactId} keys=[".implode(',', $labelKeys)."] status={$resp->status()} body=".$resp->body(), $ok ? 'info' : 'warn');
            return $ok;
        };

        // Build the full set of label DISPLAY NAMES needed
        $neededDisplayNames = [];
        foreach ($contacts as $c) {
            if (!empty($c['labels'])) {
                foreach ($c['labels'] as $L) {
                    $dn = $L['displayName'] ?? null;
                    if ($dn) $neededDisplayNames[$dn] = true;
                }
            } else {
                $keys = [];
                if (!empty($c['info']['labelKeys']['items'])) $keys = (array) $c['info']['labelKeys']['items'];
                elseif (!empty($c['labelKeys'])) {
                    $keys = is_array($c['labelKeys']) && isset($c['labelKeys']['items'])
                        ? (array) $c['labelKeys']['items']
                        : (array) $c['labelKeys'];
                }
                foreach ($keys as $k) {
                    $dn = $getLabelDisplayNameFromSource($k);
                    if ($dn) $neededDisplayNames[$dn] = true;
                    else     WixHelper::log('Labels:Resolve', "no_display_name_for_key={$k}", 'warn');
                }
            }
        }
        $neededDisplayNames = array_keys($neededDisplayNames);
        WixHelper::log('Labels:Warmup', 'unique_names='.count($neededDisplayNames), 'info');

        // Ensure all needed labels exist on TARGET and cache name → key
        $tgtNameToKey = [];
        foreach ($neededDisplayNames as $name) {
            $k = $findOrCreateLabelKeyByName($accessToken, $name);
            if ($k) $tgtNameToKey[$name] = $k;
        }
        WixHelper::log('Labels:Warmup', 'ensured_on_target='.count($tgtNameToKey), 'info');

        // ---------- Oldest-first ----------
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
        if ($max > 0) {
            $contacts = array_slice($contacts, 0, $max);
            WixHelper::log('Import Contacts', "Applied max={$max}, importing ".count($contacts)." contact(s).", 'info');
        }

        $importedContacts  = 0;
        $updatedContacts   = 0; // will stay 0 (we never update)
        $skippedDuplicates = 0; // not used (we create duplicates)
        $duplicateEmails   = [];
        $errors            = [];
        $oldMemberIdToNewMemberId = [];
        $oldContactIdToNewContactId = [];   // mapping source old contact id -> new contact id
        $pendingAssignedForPatch   = [];    // [newContactId => string[]] emails or old ids

        WixHelper::log('Import Contacts', 'Parsed '.count($contacts).' contact(s).', 'info');

        // PASS 0: members custom fields
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

        // Claim/resolve helpers (we keep them; they help you track rows)
        $claimPendingRow = function (?string $email) use ($userId, $fromStoreId) {
            return DB::transaction(function () use ($userId, $fromStoreId, $email) {
                $row = null;
                if ($email) {
                    $row = WixContactMigration::where('user_id', $userId)
                        ->where('from_store_id', $fromStoreId)
                        ->where('status', 'pending')
                        ->where(function ($q) use ($email) {
                            $q->where('contact_email', $email)->orWhereNull('contact_email');
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

        // Merge helper (kept for completeness; not used for updates now)
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

            foreach (['company','jobTitle','birthdate','locale','picture','assignedUserIds'] as $k) {
                if (isset($incoming[$k])) $result[$k] = $incoming[$k];
            }

            // Attach labels and assignedUserIds separately later
            if (isset($result['labelKeys'])) unset($result['labelKeys']);
            if (isset($result['assignedUserIds'])) unset($result['assignedUserIds']);

            if (!empty($existing['extendedFields']['items']) || !empty($incoming['extendedFields']['items'])) {
                $merged = $existing['extendedFields']['items'] ?? [];
                foreach (($incoming['extendedFields']['items'] ?? []) as $k => $v) { $merged[$k] = $v; }
                $result['extendedFields'] = ['items' => $merged];
            }

            $clean = function($a) use (&$clean) {
                foreach ($a as $k => $v) {
                    if (is_array($v)) { $a[$k] = $clean($v); if ($a[$k] === [] || $a[$k] === null) unset($a[$k]); }
                    elseif ($v === [] || $v === null) unset($a[$k]);
                }
                return $a;
            };
            return $clean($result);
        };

        // ---------- PASS 1: Contacts ----------
        foreach ($contacts as $contact) {
            unset(
                $contact['id'], $contact['revision'], $contact['source'],
                $contact['createdDate'], $contact['updatedDate'],
                $contact['memberInfo'], $contact['primaryEmail'],
                $contact['primaryInfo'], $contact['picture']
            );

            $info = $contact['info'] ?? $contact;

            $allowedInfoKeys = [
                'name', 'emails', 'phones', 'addresses', 'company',
                'jobTitle', 'birthdate', 'locale', 'extendedFields', 'picture', 'assignedUserIds'
            ];

            $filteredInfo = [];
            foreach ($allowedInfoKeys as $key) if (isset($info[$key])) $filteredInfo[$key] = $info[$key];

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
                    // keep custom.* and invoices.vatId
                    fn($k) => strpos($k, 'custom.') === 0 || $k === 'invoices.vatId',
                    ARRAY_FILTER_USE_KEY
                );
                if (empty($filteredInfo['extendedFields']['items'])) unset($filteredInfo['extendedFields']);
            }

            // Capture & log assignedUserIds from source; do NOT send in create
            $srcAssigned = [];
            if (!empty($info['assignedUserIds']['items']) && is_array($info['assignedUserIds']['items'])) {
                $srcAssigned = array_values(array_filter($info['assignedUserIds']['items'], 'is_string'));
            }
            WixHelper::log(
                'AssignedUsers',
                'SRC_CAPTURE import'
                .' | src_old_id='.($contact['info']['_old_id'] ?? 'NULL')
                .' | primary_email='.($filteredInfo['emails']['items'][0]['email'] ?? 'NULL')
                .' | raw_items='.json_encode($srcAssigned),
                'info'
            );
            unset($filteredInfo['assignedUserIds']);

            $email = $filteredInfo['emails']['items'][0]['email'] ?? null;
            $name  = $filteredInfo['name']['first'] ?? ($filteredInfo['name']['formatted'] ?? null);

            // ---------- Build target labelKeys for this contact ----------
            $targetLabelKeys = [];

            if (!empty($contact['labels'])) {
                foreach ($contact['labels'] as $L) {
                    $dn = $L['displayName'] ?? null;
                    if (!$dn) continue;
                    $key = $tgtNameToKey[$dn] ?? null;
                    if (!$key) {
                        $key = $findOrCreateLabelKeyByName($accessToken, $dn);
                        if ($key) $tgtNameToKey[$dn] = $key;
                    }
                    if ($key) $targetLabelKeys[] = $key;
                }
            } else {
                $keys = [];
                if (!empty($contact['info']['labelKeys']['items'])) $keys = (array) $contact['info']['labelKeys']['items'];
                elseif (!empty($contact['labelKeys'])) {
                    $keys = is_array($contact['labelKeys']) && isset($contact['labelKeys']['items'])
                        ? (array) $contact['labelKeys']['items']
                        : (array) $contact['labelKeys'];
                }
                foreach ($keys as $k) {
                    if ($checkTargetHasKey($accessToken, $k)) {
                        $targetLabelKeys[] = $k;
                    } else {
                        $dn = $getLabelDisplayNameFromSource($k) ?: preg_replace('/^custom\./', '', $k);
                        $mapped = $findOrCreateLabelKeyByName($accessToken, $dn);
                        if ($mapped) $targetLabelKeys[] = $mapped;
                        else WixHelper::log('Labels:Map', "could_not_map key={$k} dn_attempt={$dn}", 'warn');
                    }
                }
            }
            $targetLabelKeys = array_values(array_unique(array_filter($targetLabelKeys)));

            // Claim/resolve row (kept for tracking)
            $claimed   = $claimPendingRow($email);
            $targetRow = $resolveTargetRow($claimed, $email);

            // Required fields check
            if (!$name && !$email && empty($filteredInfo['phones']['items'])) {
                $msg = "Contact missing required fields (name/email/phone)";
                if ($targetRow) {
                    $targetRow->update([
                        'to_store_id'                => $toStoreId,
                        'destination_contact_id'     => null,
                        'status'                     => 'failed',
                        'error_message'              => $msg,
                        'contact_name'              => $name ?? $targetRow->contact_name,
                        'contact_email'             => $targetRow->contact_email ?: ($email ?? null),
                    ]);
                } else if ($email) {
                    WixContactMigration::updateOrCreate(
                        ['user_id'=>$userId,'from_store_id'=>$fromStoreId,'to_store_id'=>$toStoreId,'contact_email'=>$email],
                        ['status'=>'failed','error_message'=>$msg,'contact_name'=>$name,'destination_contact_id'=>null]
                    );
                }
                $errors[] = $msg;
                continue;
            }

            // ---------- ALWAYS CREATE a new contact (duplicates allowed) ----------
            $result = $this->createContactInWix($accessToken, $filteredInfo);
            $targetContactId = $result['contact']['id'] ?? null;
            if (!empty($targetContactId)) {
                $importedContacts++;
                $action = 'created';
            } else {
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
                } else if ($email) {
                    WixContactMigration::updateOrCreate(
                        ['user_id'=>$userId,'from_store_id'=>$fromStoreId,'to_store_id'=>$toStoreId,'contact_email'=>$email],
                        ['status'=>'failed','error_message'=>$errMsg,'contact_name'=>$name,'destination_contact_id'=>null]
                    );
                }
                $errors[] = $errMsg;
                WixHelper::log('Import Contacts', "Failed to import: {$errMsg}", 'error');
                continue;
            }

            // ATTACH LABELS now
            if ($targetContactId && $targetLabelKeys) {
                $attachLabelsToContact($accessToken, $targetContactId, $targetLabelKeys);
            } else {
                WixHelper::log('Labels:Attach', "contact=".($targetContactId ?: 'NULL')." no_label_keys_to_attach", 'info');
            }

            // Queue assignedUserIds mapping for PATCH
            $oldId = $contact['info']['_old_id'] ?? null;
            if ($oldId && $targetContactId) {
                $oldContactIdToNewContactId[$oldId] = $targetContactId;
                WixHelper::log(
                    'AssignedUsers',
                    'MAP_OLD_NEW import'
                    .' | old_id='.$oldId
                    .' | new_id='.$targetContactId,
                    'info'
                );
            }
            if ($targetContactId && !empty($srcAssigned)) {
                $pendingAssignedForPatch[$targetContactId] = $srcAssigned;
                WixHelper::log(
                    'AssignedUsers',
                    'QUEUE import'
                    .' | new_id='.$targetContactId
                    .' | queued_items='.json_encode($srcAssigned),
                    'info'
                );
            }

            // Update migration row
            if ($targetRow) {
                DB::transaction(function () use ($targetRow, $toStoreId, $targetContactId, $name, $email) {
                    $targetRow->update([
                        'to_store_id'               => $toStoreId,
                        'destination_contact_id'    => $targetContactId,
                        'status'                    => 'success',
                        'error_message'             => null,
                        'contact_name'              => $name ?? $targetRow->contact_name,
                        'contact_email'             => $targetRow->contact_email ?: ($email ?? null),
                    ]);
                }, 3);
            } else if ($email) {
                WixContactMigration::updateOrCreate(
                    ['user_id'=>$userId,'from_store_id'=>$fromStoreId,'to_store_id'=>$toStoreId,'contact_email'=>$email],
                    ['destination_contact_id'=>$targetContactId,'status'=>'success','error_message'=>null,'contact_name'=>$name]
                );
            }

            WixHelper::log('Import Contacts', ucfirst($action ?? 'processed')." contact: " . ($name ?? 'Unknown') . " (ID: {$targetContactId})", 'success');

            // ---------- Attachments (toggle) ----------
            if ($copyAttach && !empty($contact['attachments']) && $targetContactId) {
                foreach ($contact['attachments'] as $att) {
                    if (!empty($att['fileName']) && !empty($att['mimeType']) && !empty($att['content_base64'])) {
                        $uploadUrlResp = $this->generateAttachmentUploadUrl($accessToken, $targetContactId, $att['fileName'], $att['mimeType']);
                        if (!empty($uploadUrlResp['uploadUrl'])) {
                            $this->uploadFileToUrl($uploadUrlResp['uploadUrl'], base64_decode($att['content_base64']), $att['mimeType']);
                        }
                    }
                }
            }

            // ---------- Notes (always imported if present) ----------
            if (!empty($contact['notes']) && $targetContactId) {
                foreach ($contact['notes'] as $note) {
                    if (!empty($note['content'])) {
                        $this->createContactNote($accessToken, $targetContactId, $note['content']);
                    }
                }
            }

            // ---------- Email subscription ----------
            if (!empty($email)) {
                $desiredSubStatus = null;
                if (isset($contact['primaryEmail']['subscriptionStatus']) && is_string($contact['primaryEmail']['subscriptionStatus'])) {
                    $desiredSubStatus = strtoupper($contact['primaryEmail']['subscriptionStatus']);
                }
                $desiredSubStatus = $desiredSubStatus ?: $defaultSub;
                $this->upsertEmailSubscription($accessToken, $email, $desiredSubStatus);
            }

            // ---------- Marketing Consent ----------
            if (!empty($contact['marketingConsent'])) {
                // EMAIL
                $emailConsents = $contact['marketingConsent']['emails'] ?? [];
                foreach ($emailConsents as $key => $mc) {
                    $emailVal = $mc['details']['email'] ?? (is_string($key) ? $key : null);
                    if (!$emailVal) continue;

                    $state = strtoupper($mc['state'] ?? 'PENDING');
                    $payload = [
                        'details' => ['type' => 'EMAIL', 'email' => $emailVal],
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
                        'contact' => $targetContactId ?? null,
                        'email'   => $emailVal,
                    ]);

                    $live = $this->verifyMarketingConsent($accessToken, 'email', $emailVal);
                    if ($state === 'CONFIRMED' && $live !== 'CONFIRMED') {
                        WixHelper::log('MC Verify', "EMAIL mismatch wanted=CONFIRMED got=".($live ?: 'NULL')." email={$emailVal}", 'warn');
                    }
                }

                // PHONE
                $phoneConsents = $contact['marketingConsent']['phones'] ?? [];
                foreach ($phoneConsents as $key => $mc) {
                    $rawPhone = $mc['details']['phone'] ?? (is_string($key) ? $key : null);
                    if (!$rawPhone) continue;

                    $state = strtoupper($mc['state'] ?? 'PENDING');
                    $payload = [
                        'details' => ['type' => 'PHONE', 'phone' => $rawPhone],
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
                        'contact' => $targetContactId ?? null,
                        'phone'   => $rawPhone,
                    ]);

                    $live = $this->verifyMarketingConsent($accessToken, 'phone', $rawPhone);
                    if ($state === 'CONFIRMED' && $live !== 'CONFIRMED') {
                        WixHelper::log('MC Verify', "PHONE mismatch wanted=CONFIRMED got=".($live ?: 'NULL')." phone={$rawPhone}. Retrying as SMS.", 'warn');
                        $retry = $payload; $retry['details']['type'] = 'SMS';
                        $this->upsertMarketingConsent($accessToken, $retry, [
                            'origin'  => 'import',
                            'kind'    => 'phone_sms_retry',
                            'contact' => $targetContactId ?? null,
                            'phone'   => $rawPhone,
                        ]);
                        $live2 = $this->verifyMarketingConsent($accessToken, 'phone', $rawPhone);
                        if ($live2 !== 'CONFIRMED') {
                            WixHelper::log('MC Verify', "PHONE still not CONFIRMED after SMS retry; got=".($live2 ?: 'NULL')." phone={$rawPhone}", 'warn');
                        }
                    }
                }
            }

            // ---------- Members (toggle) ----------
            if ($copyMembers && !empty($contact['member'])) {
                $oldMemberId = $contact['member']['id'] ?? null;

                $memberEmail   = $contact['member']['loginEmail'] ?? ($email ?? null);
                $memberProfile = $contact['member']['profile'] ?? [];
                unset($memberProfile['slug'], $memberProfile['photo']);
                $memberCustomFields = $contact['member']['customFields'] ?? [];

                // We still de-dup members by loginEmail (member system generally expects unique loginEmail)
                $existingMember = $memberEmail ? $this->findMemberByEmail($accessToken, $memberEmail) : null;
                if ($existingMember) {
                    $newMemberId = $existingMember['id'];
                    if (!empty($memberProfile)) {
                        $this->updateMemberProfile($accessToken, $newMemberId, $memberProfile);
                    }
                    if (!empty($memberCustomFields)) {
                        $this->updateMemberCustomFields($accessToken, $newMemberId, $memberCustomFields);
                    }
                } else {
                    $createBody = [
                        'loginEmail' => $memberEmail,
                        'profile'    => $memberProfile,
                        'customFields' => $memberCustomFields,
                    ];
                    $created = $this->createMemberInWix($accessToken, $createBody);
                    $newMemberId = $created['member']['id'] ?? null;
                }

                if (!empty($newMemberId) && !empty($oldMemberId)) {
                    $oldMemberIdToNewMemberId[$oldMemberId] = $newMemberId;
                }

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
                            'Content-Type' => 'application/json'
                        ])->patch("https://www.wixapis.com/members/v2/abouts/{$existingAbout['id']}", $aboutPayload);
                    } else {
                        Http::withHeaders([
                            'Authorization' => $accessToken,
                            'Content-Type' => 'application/json'
                        ])->post("https://www.wixapis.com/members/v2/abouts", $aboutPayload);
                    }
                }
            }
        } // foreach contacts

        // PASS 2: restore member relationships (badges & following)
        if ($copyMembers) {
            foreach ($contacts as $contact) {
                if (empty($contact['member'])) continue;

                $oldMemberId = $contact['member']['id'] ?? null;
                $newMemberId = $oldMemberId ? ($oldMemberIdToNewMemberId[$oldMemberId] ?? null) : null;
                if (!$oldMemberId || !$newMemberId) continue;

                if (!empty($contact['member']['badges'])) {
                    foreach ($contact['member']['badges'] as $badge) {
                        if (!empty($badge['badgeKey'])) {
                            $this->assignBadge($accessToken, $newMemberId, $badge['badgeKey']);
                        }
                    }
                }

                if (!empty($contact['member']['following'])) {
                    foreach ($contact['member']['following'] as $f) {
                        $followedOldId = $f['id'] ?? null;
                        if ($followedOldId) {
                            $target = $oldMemberIdToNewMemberId[$followedOldId] ?? null;
                            if ($target) {
                                $this->followMember($accessToken, $newMemberId, $target);
                            }
                        }
                    }
                }
            }
        }

        // PASS 2B: map & patch assignedUserIds (verbose logs)
        foreach ($pendingAssignedForPatch as $thisNewId => $srcAssigned) {
            WixHelper::log(
                'AssignedUsers',
                'MAP_START import'
                .' | new_id='.$thisNewId
                .' | raw_items='.json_encode($srcAssigned),
                'info'
            );

            if (!$srcAssigned || !is_array($srcAssigned)) {
                WixHelper::log('AssignedUsers', 'PATCH_SKIP import | reason=no_raw_items | new_id='.$thisNewId, 'warn');
                continue;
            }

            $mapped = [];
            foreach ($srcAssigned as $item) {
                if (!is_string($item) || $item === '') {
                    WixHelper::log('AssignedUsers', 'SKIP_ITEM import | reason=not_string_or_empty | item='.json_encode($item), 'warn');
                    continue;
                }

                if (strpos($item, '@') !== false) {
                    $found = $this->findContactByEmail($accessToken, $item);
                    $foundId = $found['id'] ?? null;
                    WixHelper::log(
                        'AssignedUsers',
                        'RESOLVE_EMAIL import'
                        .' | email='.$item
                        .' | resolved_id='.($foundId ?? 'NULL'),
                        $foundId ? 'info' : 'warn'
                    );
                    if ($foundId) $mapped[] = $foundId;
                } else {
                    $newId = $oldContactIdToNewContactId[$item] ?? null;
                    WixHelper::log(
                        'AssignedUsers',
                        'RESOLVE_OLD_ID import'
                        .' | old_id='.$item
                        .' | mapped_new_id='.($newId ?? 'NULL'),
                        $newId ? 'info' : 'warn'
                    );
                    if ($newId) $mapped[] = $newId;
                }
            }

            $mapped = array_values(array_unique(array_filter($mapped)));
            WixHelper::log(
                'AssignedUsers',
                'MAP_RESULT import'
                .' | new_id='.$thisNewId
                .' | mapped_ids='.json_encode($mapped),
                $mapped ? 'info' : 'warn'
            );

            if (!$mapped) {
                WixHelper::log('AssignedUsers', 'PATCH_SKIP import | reason=no_mapped_ids | new_id='.$thisNewId, 'warn');
                continue;
            }

            $patchBody = ['info' => ['assignedUserIds' => ['items' => $mapped]]];
            WixHelper::log(
                'AssignedUsers',
                'PATCH_REQUEST import'
                .' | new_id='.$thisNewId
                .' | body='.json_encode($patchBody),
                'info'
            );

            $resp = Http::withHeaders([
                'Authorization' => $accessToken,
                'Content-Type'  => 'application/json',
            ])->patch("https://www.wixapis.com/contacts/v4/contacts/{$thisNewId}", $patchBody);

            WixHelper::log(
                'AssignedUsers',
                'PATCH_RESPONSE import'
                .' | new_id='.$thisNewId
                .' | status='.$resp->status()
                .' | ok='.($resp->ok() ? '1':'0')
                .' | body='.$resp->body(),
                $resp->ok() ? 'success' : 'error'
            );
        }

        // Final reporting
        $dupPreview = '';
        if ($skippedDuplicates > 0) {
            $preview = array_slice(array_unique($duplicateEmails), 0, 10);
            $dupPreview = ' Duplicates skipped: '.$skippedDuplicates.(count($preview) ? ' (e.g. '.implode(', ', $preview).')' : '');
        }
        $touched = $importedContacts + $updatedContacts;
        if ($touched > 0) {
            $msg = "Import finished: created={$importedContacts}, updated={$updatedContacts}.$dupPreview";
            if (count($errors)) {
                $msg .= " Some errors: " . implode("; ", $errors);
                WixHelper::log('Import Contacts', "Done. {$msg}", 'warning');
            } else {
                WixHelper::log('Import Contacts', "Done. {$msg}", 'success');
            }
            return back()->with('success', $msg);
        } else {
            $msg = "No contacts imported or updated.$dupPreview";
            if (count($errors)) $msg .= " Errors: " . implode("; ", $errors);
            WixHelper::log('Import Contacts', "Done. {$msg}", 'error');
            return back()->with('error', $msg);
        }
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
        // Extract labelKeys from info (Wix expects them at the top level, not inside "info")
        $labelKeys = null;
        if (isset($info['labelKeys']) && is_array($info['labelKeys'])) {
            $labelKeys = array_values(array_unique(array_filter($info['labelKeys'])));
            unset($info['labelKeys']);
        }

        $body = [
            'info'            => (object) $info,
            'allowDuplicates' => true,
        ];

        // Attach labels at the root if present
        if ($labelKeys && count($labelKeys) > 0) {
            $body['labelKeys'] = $labelKeys;
        }

        $response = Http::withHeaders([
            'Authorization' => $accessToken,
            'Content-Type'  => 'application/json',
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

    // Find member by contactId
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
            $rows = $data['marketingConsent'] ?? $data['results'] ?? $data['items'] ?? [];
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

    private function getContactsFromWixByQuery(string $accessToken, array $filter, ?string $sortField = 'createdDate', int $pageLimit = 1000): array
    {
        $contacts = [];
        $offset   = 0;

        do {
            $body = [
                'query' => [
                    'filter'    => $filter,
                    'paging'    => ['limit' => $pageLimit, 'offset' => $offset],
                    'fieldsets' => ['FULL'],
                ],
            ];
            if ($sortField) {
                $body['query']['sort'] = [
                    ['fieldName' => $sortField, 'order' => 'ASC'],
                ];
            }

            $resp = Http::withHeaders([
                'Authorization' => $accessToken,
                'Content-Type'  => 'application/json',
            ])->post('https://www.wixapis.com/contacts/v4/contacts/query', $body);

            WixHelper::log(
                'Export Contacts',
                'Query (POST /contacts/query) status='.$resp->status().', offset='.$offset.' body='.$resp->body(),
                $resp->ok() ? 'info' : 'warn'
            );

            if (!$resp->ok()) {
                return [
                    'error'  => 'Failed to query contacts from Wix.',
                    'status' => $resp->status(),
                    'raw'    => (string) $resp->body(),
                    'sent'   => $body,
                ];
            }

            $json  = $resp->json();
            $batch = $json['contacts'] ?? [];

            foreach ($batch as $c) $contacts[] = $c;

            $count  = $json['pagingMetadata']['count'] ?? count($batch);
            $total  = $json['pagingMetadata']['total'] ?? null;
            $offset += $count;

            if ($total !== null && $offset >= $total) break;
        } while ($count > 0);

        return $contacts;
    }



    /**
     * Build an **inclusive** Pacific Time (America/Los_Angeles) date range and return UTC ISO 8601 strings: [$gte, $lte].
     * Accepts 'Y-m-d' (2025-10-01) or 'd.m.Y' (01.10.2025). Falls back to Carbon::parse().
     */
    private function buildPacificIsoDateRange(?string $startInput, ?string $endInput): array
    {
        if (!$startInput || !$endInput) {
            return [null, null];
        }

        $tz = 'America/Los_Angeles';

        $parse = function (string $s, bool $endOfDay = false) use ($tz) {
            try {
                if (preg_match('/^\d{2}\.\d{2}\.\d{4}$/', $s)) {
                    // d.m.Y -> Y-m-d HH:MM:SS
                    [$d, $m, $y] = explode('.', $s);
                    $dt = \Carbon\Carbon::createFromFormat(
                        'Y-m-d H:i:s',
                        sprintf('%04d-%02d-%02d %s', $y, $m, $d, $endOfDay ? '23:59:59' : '00:00:00'),
                        $tz
                    );
                } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
                    // Y-m-d HH:MM:SS
                    $dt = \Carbon\Carbon::createFromFormat(
                        'Y-m-d H:i:s',
                        $s.' '.($endOfDay ? '23:59:59' : '00:00:00'),
                        $tz
                    );
                } else {
                    // Fallback: parse flexible input, then clamp to start/end of day
                    $dt = \Carbon\Carbon::parse($s, $tz);
                    $dt = $endOfDay ? $dt->endOfDay() : $dt->startOfDay();
                }

                // Return ISO 8601 in UTC
                return $dt->clone()->setTimezone('UTC')->toIso8601String();
            } catch (\Throwable $e) {
                return null;
            }
        };

        $gteIso = $parse($startInput, false);
        $lteIso = $parse($endInput, true);

        return [$gteIso, $lteIso];
    }

}