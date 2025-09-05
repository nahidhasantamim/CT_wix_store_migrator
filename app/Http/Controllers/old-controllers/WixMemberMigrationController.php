<?php

namespace App\Http\Controllers;

use App\Models\WixStore;
use App\Models\WixMemberMigration;
use App\Helpers\WixHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;

class WixMemberMigrationController extends Controller
{
    // =========================================================
    // Export Members (with followers, badges, activity counters)
    // =========================================================
    public function export(WixStore $store)
    {
        WixHelper::log('Export Members', "Export started for store: {$store->store_name}", 'info');

        $accessToken = WixHelper::getAccessToken($store->instance_id);
        if (!$accessToken) {
            WixHelper::log('Export Members', "Failed: Could not get access token.", 'error');
            return response()->json(['error' => 'Could not get Wix access token.'], 401);
        }

        $userId = Auth::id() ?: 1;
        $members = $this->getMembersFromWix($accessToken);

        foreach ($members as &$member) {
            $memberId = $member['id'];

            // Export followers & following
            $member['followers'] = $this->getMemberFollowers($accessToken, $memberId, 'followers');
            $member['following'] = $this->getMemberFollowers($accessToken, $memberId, 'following');

            // Export badges
            $member['badges'] = $this->getMemberBadges($accessToken, $memberId);

            // Export activity counters (read-only)
            $member['activity_counters'] = $this->getMemberActivityCounters($accessToken, $memberId);

            // Save migration for tracking
            WixMemberMigration::updateOrCreate(
                [
                    'user_id' => $userId,
                    'from_store_id' => $store->instance_id,
                    'source_member_id' => $memberId,
                ],
                [
                    'source_member_email' => $member['loginEmail'] ?? null,
                    'source_member_name'  => $member['profile']['nickname'] ?? null,
                    'status'              => 'pending',
                ]
            );
        }
        unset($member);

        $payload = [
            'from_store_id' => $store->instance_id,
            'members'       => $members
        ];

        WixHelper::log('Export Members', "Exported " . count($members) . " members.", 'success');

        return response()->streamDownload(function() use ($payload) {
            echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }, 'members.json', [
            'Content-Type' => 'application/json'
        ]);
    }

    // =========================================================
    // Import Members (duplicate check, restore followers/badges)
    // =========================================================
    public function import(Request $request, WixStore $store)
    {
        $userId    = Auth::id() ?: 1;
        $toStoreId = $store->instance_id;
        WixHelper::log('Import Members', "Import started for store: $store->store_name", 'info');

        $accessToken = WixHelper::getAccessToken($toStoreId);
        if (!$accessToken) {
            WixHelper::log('Import Members', "Failed to get access token for store: $store->store_name", 'error');
            return back()->with('error', 'Unauthorized');
        }

        if (!$request->hasFile('members_json')) {
            WixHelper::log('Import Members', "No file uploaded.", 'error');
            return back()->with('error', 'No file uploaded.');
        }

        $json = file_get_contents($request->file('members_json')->getRealPath());
        $decoded = json_decode($json, true);

        if (!isset($decoded['from_store_id'], $decoded['members']) || !is_array($decoded['members'])) {
            WixHelper::log('Import Members', "Invalid JSON structure.", 'error');
            return back()->with('error', 'Invalid JSON structure. Required keys: from_store_id and members.');
        }

        $fromStoreId = $decoded['from_store_id'];
        $members     = $decoded['members'];
        $imported    = 0;
        $errors      = [];
        $oldToNewMap = []; // Map old member ID => new member ID

        // FIRST PASS: Create all members (with duplicate check)
        foreach ($members as $member) {
            $sourceId = $member['id'] ?? null;
            if (!$sourceId) continue;

            $migration = WixMemberMigration::where([
                'user_id' => $userId,
                'from_store_id' => $fromStoreId,
                'source_member_id' => $sourceId,
            ])->first();

            if ($migration && $migration->status === 'success') continue;

            // Duplicate check by email and phone
            $email = $member['loginEmail'] ?? null;
            $phone = null;
            if (isset($member['profile']['phones']['items'][0]['phone'])) {
                $phone = $member['profile']['phones']['items'][0]['phone'];
            }

            $alreadyExists = false;
            if ($email) {
                $existing = $this->findMemberByEmail($accessToken, $email);
                if ($existing) {
                    $alreadyExists = true;
                    $newMemberId = $existing['id'];
                }
            }
            if (!$alreadyExists && $phone) {
                $existing = $this->findMemberByPhone($accessToken, $phone);
                if ($existing) {
                    $alreadyExists = true;
                    $newMemberId = $existing['id'];
                }
            }

            if ($alreadyExists) {
                WixHelper::log('Import Members', "Skipped create: Member with email/phone already exists.", 'warning');
                $oldToNewMap[$sourceId] = $newMemberId;
                continue;
            }

            // Only import if at least one of name, phone, or email is present
            $nickname = $member['profile']['nickname'] ?? null;
            if (!$email && !$nickname && !$phone) {
                $error = 'Contact must have a name, phone number or email address.';
                $errors[] = $error;
                WixHelper::log('Import Members', "Failed: $error", 'error');
                continue;
            }

            $body = [
                'loginEmail' => $email,
                'profile'    => [
                    'nickname' => $nickname,
                ],
            ];

            $result = $this->createMemberInWix($accessToken, $body);

            if (isset($result['member']['id'])) {
                $newMemberId = $result['member']['id'];
                $oldToNewMap[$sourceId] = $newMemberId;

                WixMemberMigration::updateOrCreate([
                    'user_id' => $userId,
                    'from_store_id' => $fromStoreId,
                    'source_member_id' => $sourceId,
                ], [
                    'to_store_id' => $toStoreId,
                    'destination_member_id' => $newMemberId,
                    'status' => 'success',
                    'error_message' => null,
                ]);
                $imported++;
                WixHelper::log('Import Members', "Imported member: {$email}", 'success');
            } else {
                $error = json_encode($result);
                WixMemberMigration::updateOrCreate([
                    'user_id' => $userId,
                    'from_store_id' => $fromStoreId,
                    'source_member_id' => $sourceId,
                ], [
                    'to_store_id' => $toStoreId,
                    'destination_member_id' => null,
                    'status' => 'failed',
                    'error_message' => $error,
                ]);
                $errors[] = $error;
                WixHelper::log('Import Members', "Failed to import member: $error", 'error');
            }
        }

        // SECOND PASS: Restore followers and badges
        foreach ($members as $member) {
            $sourceId = $member['id'] ?? null;
            if (empty($oldToNewMap[$sourceId])) continue; // Not imported/skipped
            $newMemberId = $oldToNewMap[$sourceId];

            // Restore badges
            if (!empty($member['badges'])) {
                foreach ($member['badges'] as $badge) {
                    if (!empty($badge['badgeKey'])) {
                        $this->assignBadge($accessToken, $newMemberId, $badge['badgeKey']);
                    }
                }
            }

            // Restore "following": member should follow same as before (if imported)
            if (!empty($member['following'])) {
                foreach ($member['following'] as $f) {
                    $followedOldId = $f['id'] ?? null;
                    if ($followedOldId && !empty($oldToNewMap[$followedOldId])) {
                        $this->followMember($accessToken, $newMemberId, $oldToNewMap[$followedOldId]);
                    }
                }
            }
        }

        if ($imported > 0) {
            WixHelper::log('Import Members', "Import finished: $imported member(s) imported." . (count($errors) ? " Some errors." : ""), count($errors) ? 'warning' : 'success');
            return back()->with('success', "$imported member(s) imported." . (count($errors) ? " Some errors occurred." : ""));
        } else {
            WixHelper::log('Import Members', "No members imported. Errors: " . implode("; ", $errors), 'error');
            return back()->with('error', 'No members imported.' . (count($errors) ? " Errors: " . implode("; ", $errors) : ''));
        }
    }

    // =========================================================
    // Utilities
    // =========================================================

    public function getMembersFromWix($accessToken, $limit = 100)
    {
        $members = [];
        $offset = 0;
        $total = null;

        do {
            $query = [
                'paging' => [
                    'limit'  => $limit,
                    'offset' => $offset
                ]
            ];

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $accessToken,
                'Content-Type'  => 'application/json'
            ])->post(
                'https://www.wixapis.com/members/v1/members/query',
                $query
            );

            if (!$response->ok()) {
                return [
                    'error' => 'Failed to fetch members from Wix.',
                    'raw'   => $response->body()
                ];
            }

            $data = $response->json();

            if (!empty($data['members'])) {
                foreach ($data['members'] as $member) {
                    $members[] = $member;
                }
            }

            $count = isset($data['paging']['count']) ? $data['paging']['count'] : (isset($data['members']) ? count($data['members']) : 0);
            $total = $data['paging']['total'] ?? (is_null($total) ? $count : $total);
            $offset += $count;

        } while ($count > 0 && $offset < $total);

        return $members;
    }

    // Find member by email
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
            'Authorization' => 'Bearer ' . $accessToken,
            'Content-Type'  => 'application/json'
        ])->post('https://www.wixapis.com/members/v1/members/query', $query);
        if ($response->ok() && !empty($response->json('members'))) {
            return $response->json('members')[0];
        }
        return null;
    }

    // Find member by phone
    private function findMemberByPhone($accessToken, $phone)
    {
        if (!$phone) return null;
        $query = [
            'query' => [
                'filter' => [
                    'profile.phones.items.phone' => ['$eq' => $phone]
                ],
                'paging' => ['limit' => 1]
            ]
        ];
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $accessToken,
            'Content-Type'  => 'application/json'
        ])->post('https://www.wixapis.com/members/v1/members/query', $query);
        if ($response->ok() && !empty($response->json('members'))) {
            return $response->json('members')[0];
        }
        return null;
    }

    // Create member
    private function createMemberInWix($accessToken, $body)
    {
        $response = Http::withHeaders([
            'Authorization' => $accessToken,
            'Content-Type'  => 'application/json'
        ])->post('https://www.wixapis.com/members/v1/members', ['member' => $body]);
        return $response->json();
    }

    // ========== Followers & Following ==========

    private function getMemberFollowers($accessToken, $memberId, $type = 'followers')
    {
        $endpoint = $type === 'followers'
            ? "https://www.wixapis.com/members/v1/members/$memberId/followers"
            : "https://www.wixapis.com/members/v1/members/$memberId/following";
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $accessToken,
            'Content-Type'  => 'application/json'
        ])->get($endpoint);
        return $response->ok() ? ($response->json()['members'] ?? []) : [];
    }
    private function followMember($accessToken, $memberId, $targetMemberId)
    {
        Http::withHeaders([
            'Authorization' => 'Bearer ' . $accessToken,
            'Content-Type'  => 'application/json'
        ])->post("https://www.wixapis.com/members/v1/members/$memberId/following", [
            'memberId' => $targetMemberId
        ]);
    }

    // ========== Badges ==========

    private function getMemberBadges($accessToken, $memberId)
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $accessToken,
            'Content-Type'  => 'application/json'
        ])->get("https://www.wixapis.com/members/v1/members/$memberId/badges");
        return $response->ok() ? ($response->json()['badges'] ?? []) : [];
    }
    private function assignBadge($accessToken, $memberId, $badgeKey)
    {
        Http::withHeaders([
            'Authorization' => 'Bearer ' . $accessToken,
            'Content-Type'  => 'application/json'
        ])->post("https://www.wixapis.com/members/v1/members/$memberId/badges", [
            'badgeKey' => $badgeKey
        ]);
    }

    // ========== Activity Counters ==========

    private function getMemberActivityCounters($accessToken, $memberId)
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $accessToken,
            'Content-Type'  => 'application/json'
        ])->get("https://www.wixapis.com/members/v1/members/$memberId/activity-counters");
        return $response->ok() ? $response->json() : [];
    }
}
