<?php

namespace App\Http\Controllers;

use App\Helpers\WixHelper;
use App\Models\WixLoyaltyAccountMigration;
use App\Models\WixStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class WixLoyaltyAccountController extends Controller
{
    // ========================================================= Automatic Migrator =========================================================
    public function migrateAuto(Request $request)
    {
        $request->validate([
            'from_store' => 'required|string',
            'to_store'   => 'required|string|different:from_store',
            'max'        => 'nullable|integer|min:1',
        ]);

        $userId = Auth::id() ?: 1;
        $fromId = (string) $request->input('from_store');
        $toId   = (string) $request->input('to_store');
        $max    = (int)($request->input('max', 0));

        $fromStore = WixStore::where('instance_id', $fromId)->first();
        $toStore   = WixStore::where('instance_id', $toId)->first();
        $fromLabel = $fromStore?->store_name ?: $fromId;
        $toLabel   = $toStore?->store_name   ?: $toId;

        WixHelper::log('Auto Loyalty Migration', "Start: {$fromLabel} → {$toLabel}.", 'info');

        // --- tokens
        $fromToken = WixHelper::getAccessToken($fromId);
        $toToken   = WixHelper::getAccessToken($toId);
        if (!$fromToken || !$toToken) {
            WixHelper::log('Auto Loyalty Migration', 'Missing access token(s).', 'error');
            return back()->with('error', 'Could not get Wix access token(s).');
        }

        // --- 1) Fetch source accounts (oldest-first)
        [$srcAccounts, $pages] = $this->queryAllAccounts($fromToken);
        if ($max > 0) $srcAccounts = array_slice($srcAccounts, 0, $max);
        usort($srcAccounts, fn($a, $b) => $this->extractCreatedTs($a) <=> $this->extractCreatedTs($b));
        WixHelper::log('Auto Loyalty Migration', 'Fetched ' . count($srcAccounts) . " account(s) across {$pages} page(s).", 'info');

        // --- 2) Stage/append pending rows (target-aware)
        $staged = 0;
        foreach ($srcAccounts as $acc) {
            $sourceId  = $acc['id']        ?? null;
            $contactId = $acc['contactId'] ?? ($acc['contact']['id'] ?? null);
            if (!$sourceId) continue;

            $email    = $this->extractAccountEmail($acc);
            $name     = $acc['contact']['displayName'] ?? $acc['contact']['name'] ?? null;
            $balance  = isset($acc['points']['balance']) ? (int)$acc['points']['balance'] : null;
            $tierName = $acc['tier']['name'] ?? null;

            try {
                WixLoyaltyAccountMigration::create([
                    'user_id'                => $userId,
                    'from_store_id'          => $fromId,
                    'to_store_id'            => $toId,
                    'source_account_id'      => $sourceId,
                    'source_contact_id'      => $contactId,
                    'source_email'           => $email,
                    'source_name'            => $name,
                    'source_points_balance'  => $balance,
                    'source_tier_name'       => $tierName,
                    'destination_account_id' => null,
                    'destination_contact_id' => null,
                    'status'                 => 'pending',
                    'error_message'          => null,
                ]);
                $staged++;
            } catch (\Illuminate\Database\QueryException $e) {
                // duplicate pending row is fine in auto mode
            }
        }
        WixHelper::log('Auto Loyalty Migration', "Staged {$staged} pending row(s).", 'success');

        // --- 3) Ensure program active on TARGET
        $this->ensureProgramActive($toToken);

        // Prepare for import logic
        $prepared = $this->prepareAccountsForImport($srcAccounts);   // adds email/balance + sorts
        // Build indices on target
        $targetEmailIndex = $this->indexTargetContactsByEmail($toToken);
        $targetIndex      = $this->indexTargetLoyaltyAccounts($toToken); // contactId => [id,balance]

        // --- claim/resolve helpers (coupon/giftcard pattern)
        $claimPendingRow = function (?string $sourceAccountId) use ($userId, $fromId) {
            return DB::transaction(function () use ($userId, $fromId, $sourceAccountId) {
                $row = null;
                if ($sourceAccountId) {
                    $row = \App\Models\WixLoyaltyAccountMigration::where('user_id', $userId)
                        ->where('from_store_id', $fromId)
                        ->where('status', 'pending')
                        ->where(function ($q) use ($sourceAccountId) {
                            $q->where('source_account_id', $sourceAccountId)
                            ->orWhereNull('source_account_id');
                        })
                        ->orderByRaw("CASE WHEN source_account_id = ? THEN 0 ELSE 1 END", [$sourceAccountId])
                        ->orderBy('created_at', 'asc')
                        ->lockForUpdate()
                        ->first();
                }
                if (!$row) {
                    $row = \App\Models\WixLoyaltyAccountMigration::where('user_id', $userId)
                        ->where('from_store_id', $fromId)
                        ->where('status', 'pending')
                        ->orderBy('created_at', 'asc')
                        ->lockForUpdate()
                        ->first();
                }
                return $row;
            }, 3);
        };

        $resolveTargetRow = function ($claimed, ?string $sourceAccountId) use ($userId, $fromId, $toId) {
            if ($sourceAccountId) {
                $existing = \App\Models\WixLoyaltyAccountMigration::where('user_id', $userId)
                    ->where('from_store_id', $fromId)
                    ->where('to_store_id', $toId)
                    ->where('source_account_id', $sourceAccountId)
                    ->orderBy('created_at', 'asc')
                    ->first();

                if ($existing) {
                    if ($claimed && $claimed->id !== $existing->id && $claimed->status === 'pending') {
                        $claimed->update([
                            'status'        => 'skipped',
                            'error_message' => 'Merged into existing migration row id ' . $existing->id . '.',
                        ]);
                    }
                    return $existing;
                }
            }
            return $claimed;
        };

        // --- 4) Create/sync on TARGET
        $processed = 0; $imported = 0; $aligned = 0; $skipped = 0; $failed = 0; $total = count($prepared);

        foreach ($prepared as $entry) {
            $processed++;

            $sourceId = $entry['__source_id'];
            $email    = strtolower((string)($entry['__email'] ?? ''));
            $name     = $entry['__name'] ?? null;
            $desired  = is_numeric($entry['__points_balance']) ? max(0, (int)$entry['__points_balance']) : 0;

            $claimed   = $claimPendingRow($sourceId);
            $targetRow = $resolveTargetRow($claimed, $sourceId);

            if ($email === '') {
                $msg = 'No email on source account; cannot match to target contact.';
                if ($targetRow) {
                    $targetRow->update([
                        'to_store_id'              => $toId,
                        'destination_account_id'   => null,
                        'destination_contact_id'   => null,
                        'status'                   => 'failed',
                        'error_message'            => $msg,
                        'source_name'              => $name ?? $targetRow->source_name,
                        'source_account_id'        => $targetRow->source_account_id ?: ($sourceId ?? null),
                    ]);
                }
                WixHelper::log('Auto Loyalty Migration', $msg, 'warn');
                $failed++;
                continue;
            }

            $contactId = $targetEmailIndex[$email] ?? null;
            if (!$contactId) {
                $msg = "Target contact not found for email: {$email}.";
                if ($targetRow) {
                    $targetRow->update([
                        'to_store_id'              => $toId,
                        'destination_account_id'   => null,
                        'destination_contact_id'   => null,
                        'status'                   => 'failed',
                        'error_message'            => $msg,
                        'source_name'              => $name ?? $targetRow->source_name,
                        'source_account_id'        => $targetRow->source_account_id ?: ($sourceId ?? null),
                    ]);
                }
                WixHelper::log('Auto Loyalty Migration', $msg, 'warn');
                $failed++;
                continue;
            }

            // If account already exists for this contact on TARGET → align balance
            if (!empty($targetIndex[$contactId]['id'])) {
                $existingId  = $targetIndex[$contactId]['id'];
                $existingBal = (int)$targetIndex[$contactId]['balance'];

                if ($desired !== $existingBal) {
                    $this->setAccountBalance($toToken, $existingId, $desired, $existingBal);
                    $targetIndex[$contactId]['balance'] = $desired;
                    $aligned++;
                }

                if ($targetRow) {
                    $targetRow->update([
                        'to_store_id'                => $toId,
                        'destination_account_id'     => $existingId,
                        'destination_contact_id'     => $contactId,
                        'status'                     => 'skipped',
                        'error_message'              => 'Duplicate: target already has a loyalty account (balance aligned if needed).',
                        'source_name'                => $name ?? $targetRow->source_name,
                        'source_account_id'          => $targetRow->source_account_id ?: ($sourceId ?? null),
                    ]);
                }
                $skipped++;
                continue;
            }

            // Create new account on TARGET, then set desired balance
            $create = $this->createAccountInWix($toToken, $contactId);

            if (($create['ok'] ?? false) && !empty($create['id'])) {
                $newId = $create['id'];

                // tiny pause to avoid eventual-consistency races
                usleep(200 * 1000);

                if ($desired !== 0) {
                    $this->setAccountBalance($toToken, $newId, $desired, 0);
                }

                $targetIndex[$contactId] = ['id' => $newId, 'balance' => (int)$desired];

                if ($targetRow) {
                    DB::transaction(function () use ($targetRow, $toId, $newId, $contactId, $name, $sourceId) {
                        $targetRow->update([
                            'to_store_id'                => $toId,
                            'destination_account_id'     => $newId,
                            'destination_contact_id'     => $contactId,
                            'status'                     => 'success',
                            'error_message'              => null,
                            'source_name'                => $name ?? $targetRow->source_name,
                            'source_account_id'          => $targetRow->source_account_id ?: ($sourceId ?? null),
                        ]);
                    }, 3);
                }

                $imported++;
                WixHelper::log('Auto Loyalty Migration', "Created account for contact {$this->maskGuid($contactId)} (id {$this->maskGuid($newId)}).", 'success');
            } else {
                $err = $create['raw'] ?? 'Create account failed (no details).';
                if ($targetRow) {
                    DB::transaction(function () use ($targetRow, $toId, $contactId, $name, $sourceId, $err) {
                        $targetRow->update([
                            'to_store_id'                => $toId,
                            'destination_account_id'     => null,
                            'destination_contact_id'     => $contactId,
                            'status'                     => 'failed',
                            'error_message'              => $err,
                            'source_name'                => $name ?? $targetRow->source_name,
                            'source_account_id'          => $targetRow->source_account_id ?: ($sourceId ?? null),
                        ]);
                    }, 3);
                }
                $failed++;
                WixHelper::log('Auto Loyalty Migration', "Create failed: {$err}", 'warn');
            }

            if ($processed % 50 === 0) {
                WixHelper::log('Auto Loyalty Migration', "Progress: {$processed}/{$total}; imported={$imported}; aligned={$aligned}; skipped={$skipped}; failed={$failed}.", 'debug');
            }
        }

        // ===== Summary & flash (always surface a message) =====
        $summary = "Loyalty: imported={$imported}, aligned={$aligned}, skipped={$skipped}, failed={$failed}.";

        WixHelper::log('Auto Loyalty Migration', "Done. {$summary}", $failed ? 'warn' : 'success');

        // Show a visible banner even when some items failed or were just aligned/duplicated
        if ($imported > 0 || $aligned > 0 || $skipped > 0) {
            return back()->with('success', "Auto loyalty migration completed. {$summary}");
        }

        // None imported/aligned/skipped, but some failed → error banner
        if ($failed > 0) {
            return back()->with('error', "No loyalty accounts imported. {$summary}");
        }

        // Nothing to do
        return back()->with('success', 'Nothing to import.');

    }


    // ========================================================= Manual Migrator =========================================================
    // =========================================================
    // EXPORT — Program + Rewards + Accounts (oldest-first)
    // =========================================================
    public function export(WixStore $store)
    {
        $userId      = Auth::id() ?: 1;
        $fromStoreId = $store->instance_id;

        WixHelper::log('Export Loyalty', "Start: {$store->store_name} ({$fromStoreId}).", 'info');

        $accessToken = WixHelper::getAccessToken($fromStoreId);
        if (!$accessToken) {
            WixHelper::log('Export Loyalty', "Failed to get access token for {$fromStoreId}.", 'error');
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // Snapshot program + rewards (for audit/visibility)
        $program = $this->getLoyaltyProgram($accessToken);
        $rewards = $this->queryAllRewards($accessToken, 100);

        // Pull all accounts (paged; supports multiple shapes)
        [$allAccounts, $pages] = $this->queryAllAccounts($accessToken);

        // Sort oldest → newest (createdDate, fallback updated/lastActivity)
        usort($allAccounts, fn($a, $b) => $this->extractCreatedTs($a) <=> $this->extractCreatedTs($b));

        WixHelper::log('Export Loyalty', 'Fetched ' . count($allAccounts) . " account(s) across {$pages} page(s).", 'success');

        // Append-only pending rows
        $saved = 0;
        foreach ($allAccounts as $acc) {
            $sourceId  = $acc['id']        ?? null;
            $contactId = $acc['contactId'] ?? ($acc['contact']['id'] ?? null);
            if (!$sourceId) continue;

            $email    = $this->extractAccountEmail($acc);
            $name     = $acc['contact']['displayName'] ?? $acc['contact']['name'] ?? null;
            $balance  = isset($acc['points']['balance']) ? (int)$acc['points']['balance'] : null;
            $tierName = $acc['tier']['name'] ?? null;

            WixLoyaltyAccountMigration::create([
                'user_id'                => $userId,
                'from_store_id'          => $fromStoreId,
                'to_store_id'            => null,
                'source_account_id'      => $sourceId,
                'source_contact_id'      => $contactId,
                'source_email'           => $email,
                'source_name'            => $name,
                'source_points_balance'  => $balance,
                'source_tier_name'       => $tierName,
                'destination_account_id' => null,
                'destination_contact_id' => null,
                'status'                 => 'pending',
                'error_message'          => null,
            ]);
            $saved++;
        }

        WixHelper::log('Export Loyalty', "Saved {$saved} pending row(s).", 'success');

        // Output one file with program + rewards + accounts
        $payload = [
            'meta' => [
                'from_store_id' => $fromStoreId,
                'generated_at'  => now()->toIso8601String(),
            ],
            'program'         => $program,
            'rewards'         => $rewards,
            'loyaltyAccounts' => $allAccounts, // compatibility with Wix tester output
            'accounts'        => $allAccounts,
        ];

        return response()->streamDownload(function () use ($payload) {
            echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }, 'loyalty_export.json', ['Content-Type' => 'application/json']);
    }

    // =========================================================
    // IMPORT — email-match to target contacts, then create/sync
    // =========================================================
    public function import(Request $request, WixStore $store)
    {
        $userId    = Auth::id() ?: 1;
        $toStoreId = $store->instance_id;

        WixHelper::log('Import Loyalty', "Start: {$store->store_name} ({$toStoreId}).", 'info');

        $accessToken = WixHelper::getAccessToken($toStoreId);
        if (!$accessToken) {
            return back()->with('error', 'Unauthorized.');
        }

        if (!$request->hasFile('loyalty_json') && !$request->hasFile('loyalty_accounts_json')) {
            return back()->with('error', 'No file uploaded.');
        }

        $file    = $request->file('loyalty_json') ?: $request->file('loyalty_accounts_json');
        $json    = file_get_contents($file->getRealPath());
        $payload = json_decode($json, true);

        if (!is_array($payload)) {
            return back()->with('error', 'Invalid JSON.');
        }

        $accountsRaw = $payload['loyaltyAccounts']
            ?? $payload['accounts']
            ?? (isset($payload[0]) && is_array($payload[0]) ? $payload : []);

        if (!is_array($accountsRaw)) {
            return back()->with('error', 'Invalid JSON: accounts array not found.');
        }

        $fromStoreId = $request->input('from_store_id')
            ?: ($payload['meta']['from_store_id'] ?? ($payload['from_store_id'] ?? null));

        if (!$fromStoreId) {
            return back()->with('error', 'from_store_id is required (field or meta.from_store_id).');
        }

        $this->ensureProgramActive($accessToken);

        $max = (int)($request->input('max', 0));
        if ($max > 0) $accountsRaw = array_slice($accountsRaw, 0, $max);

        $prepared = $this->prepareAccountsForImport($accountsRaw);

        // Build full target email index (contactEmail -> contactId)
        $targetEmailIndex = $this->indexTargetContactsByEmail($accessToken);
        if (empty($targetEmailIndex)) {
            WixHelper::log('Import Loyalty', 'Target contact email index is empty. Make sure Contacts import ran first.', 'warn');
        }

        // Existing target accounts index (contactId => [id, balance])
        $targetIndex = $this->indexTargetLoyaltyAccounts($accessToken);

        $processed = 0; $imported = 0; $skipped = 0; $failed = 0; $total = count($prepared);

        $claimPendingRow = function (?string $sourceAccountId) use ($userId, $fromStoreId) {
            return DB::transaction(function () use ($userId, $fromStoreId, $sourceAccountId) {
                $row = null;

                if ($sourceAccountId) {
                    $row = \App\Models\WixLoyaltyAccountMigration::where('user_id', $userId)
                        ->where('from_store_id', $fromStoreId)
                        ->where('status', 'pending')
                        ->where(function ($q) use ($sourceAccountId) {
                            $q->where('source_account_id', $sourceAccountId)
                            ->orWhereNull('source_account_id');
                        })
                        ->orderByRaw("CASE WHEN source_account_id = ? THEN 0 ELSE 1 END", [$sourceAccountId])
                        ->orderBy('created_at', 'asc')
                        ->lockForUpdate()
                        ->first();
                }

                if (!$row) {
                    $row = \App\Models\WixLoyaltyAccountMigration::where('user_id', $userId)
                        ->where('from_store_id', $fromStoreId)
                        ->where('status', 'pending')
                        ->orderBy('created_at', 'asc')
                        ->lockForUpdate()
                        ->first();
                }

                return $row;
            }, 3);
        };

        $resolveTargetRow = function ($claimed, ?string $sourceAccountId) use ($userId, $fromStoreId, $toStoreId) {
            if ($sourceAccountId) {
                $existing = \App\Models\WixLoyaltyAccountMigration::where('user_id', $userId)
                    ->where('from_store_id', $fromStoreId)
                    ->where('to_store_id', $toStoreId)
                    ->where('source_account_id', $sourceAccountId)
                    ->orderBy('created_at', 'asc')
                    ->first();

                if ($existing) {
                    if ($claimed && $claimed->id !== $existing->id && $claimed->status === 'pending') {
                        $claimed->update([
                            'status'        => 'skipped',
                            'error_message' => 'Merged into existing migration row id ' . $existing->id . '.',
                        ]);
                    }
                    return $existing;
                }
            }
            return $claimed;
        };

        foreach ($prepared as $entry) {
            $processed++;

            $sourceId = $entry['__source_id'];
            $email    = strtolower((string)($entry['__email'] ?? ''));
            $name     = $entry['__name'] ?? null;

            // Desired starting balance (we’ll realize this via an ADJUST delta)
            $desiredBalance = is_numeric($entry['__points_balance']) ? max(0, (int)$entry['__points_balance']) : 0;

            $claimed   = $claimPendingRow($sourceId);
            $targetRow = $resolveTargetRow($claimed, $sourceId);

            if ($email === '') {
                $msg = 'No email on source account; cannot match to target contact.';
                if ($targetRow) {
                    $targetRow->update([
                        'to_store_id'              => $toStoreId,
                        'destination_account_id'   => null,
                        'destination_contact_id'   => null,
                        'status'                   => 'failed',
                        'error_message'            => $msg,
                        'source_name'              => $name ?? $targetRow->source_name,
                        'source_account_id'        => $targetRow->source_account_id ?: ($sourceId ?? null),
                    ]);
                }
                WixHelper::log('Import Loyalty', $msg, 'warn');
                $failed++;
                continue;
            }

            $contactId = $targetEmailIndex[$email] ?? null;

            if (!$contactId) {
                $msg = "Target contact not found for email: {$email}.";
                if ($targetRow) {
                    $targetRow->update([
                        'to_store_id'              => $toStoreId,
                        'destination_account_id'   => null,
                        'destination_contact_id'   => null,
                        'status'                   => 'failed',
                        'error_message'            => $msg,
                        'source_name'              => $name ?? $targetRow->source_name,
                        'source_account_id'        => $targetRow->source_account_id ?: ($sourceId ?? null),
                    ]);
                }
                WixHelper::log('Import Loyalty', $msg, 'warn');
                $failed++;
                continue;
            }

            // If account already exists for this contact on TARGET → sync to desired
            if (!empty($targetIndex[$contactId]['id'])) {
                $existingId  = $targetIndex[$contactId]['id'];
                $existingBal = (int)$targetIndex[$contactId]['balance'];

                if ($desiredBalance !== $existingBal) {
                    $this->setAccountBalance($accessToken, $existingId, $desiredBalance, $existingBal);
                    $targetIndex[$contactId]['balance'] = $desiredBalance;
                }

                if ($targetRow) {
                    $targetRow->update([
                        'to_store_id'                => $toStoreId,
                        'destination_account_id'     => $existingId,
                        'destination_contact_id'     => $contactId,
                        'status'                     => 'skipped',
                        'error_message'              => 'Duplicate: target already has a loyalty account (balance aligned if needed).',
                        'source_name'                => $name ?? $targetRow->source_name,
                        'source_account_id'          => $targetRow->source_account_id ?: ($sourceId ?? null),
                    ]);
                }
                $skipped++;
                continue;
            }

            // Create new account for TARGET contact (email-matched)
            WixHelper::log('Import Loyalty', 'Creating account for contactId=' . $this->maskGuid($contactId) . '.', 'debug');
            $create = $this->createAccountInWix($accessToken, $contactId);

            if (($create['ok'] ?? false) && !empty($create['id'])) {
                $newId = $create['id'];

                // tiny pause to avoid eventual-consistency races
                usleep(200 * 1000); // 200ms

                // Adjust to desired balance (delta from 0 for new account)
                if ($desiredBalance !== 0) {
                    $this->setAccountBalance($accessToken, $newId, $desiredBalance, 0);
                }

                // update local index for later duplicates
                $targetIndex[$contactId] = ['id' => $newId, 'balance' => (int)$desiredBalance];

                if ($targetRow) {
                    $targetRow->update([
                        'to_store_id'                => $toStoreId,
                        'destination_account_id'     => $newId,
                        'destination_contact_id'     => $contactId,
                        'status'                     => 'success',
                        'error_message'              => null,
                        'source_name'                => $name ?? $targetRow->source_name,
                        'source_account_id'          => $targetRow->source_account_id ?: ($sourceId ?? null),
                    ]);
                }
                $imported++;
            } else {
                $err = $create['raw'] ?? 'Create account failed (no details).';
                if ($targetRow) {
                    $targetRow->update([
                        'to_store_id'                => $toStoreId,
                        'destination_account_id'     => null,
                        'destination_contact_id'     => $contactId,
                        'status'                     => 'failed',
                        'error_message'              => $err,
                        'source_name'                => $name ?? $targetRow->source_name,
                        'source_account_id'          => $targetRow->source_account_id ?: ($sourceId ?? null),
                    ]);
                }
                WixHelper::log('Import Loyalty', "Create failed: {$err}", 'warn');
                $failed++;
            }

            if ($processed % 50 === 0) {
                WixHelper::log('Import Loyalty', "Progress: {$processed}/{$total}.", 'debug');
            }
        }

        if ($imported > 0) {
            return back()->with('success', "{$imported} account(s) imported. Skipped: {$skipped}. Failed: {$failed}.");
        }
        return back()->with('error', $failed ? "No loyalty accounts imported. Skipped: {$skipped}. Failed: {$failed}." : 'Nothing to import.');
    }




    // =========================================================
    // HELPERS — Program & Rewards
    // =========================================================
    private function getLoyaltyProgram(string $accessToken): array
    {
        $resp = Http::withHeaders(['Authorization' => $this->authHeader($accessToken)])
            ->get('https://www.wixapis.com/loyalty-programs/v1/program');

        return $resp->ok() ? ($resp->json() ?? []) : [];
    }

    private function ensureProgramActive(string $accessToken): void
    {
        $prog   = $this->getLoyaltyProgram($accessToken);
        $status = $prog['loyaltyProgram']['status'] ?? $prog['program']['status'] ?? $prog['status'] ?? null;

        if ($status !== 'ACTIVE') {
            $resp = Http::withHeaders([
                'Authorization' => $this->authHeader($accessToken),
                'Content-Type'  => 'application/json',
            ])->post('https://www.wixapis.com/loyalty-programs/v1/program/activate', (object)[]);

            WixHelper::log('Import Loyalty', 'Program activation → ' . $resp->status() . ' | ' . $resp->body() . '.', $resp->ok() ? 'info' : 'warn');
        }
    }

    private function queryAllRewards(string $accessToken, int $limit = 100): array
    {
        $all = []; $cursor = null;

        do {
            $body = ['query' => ['cursorPaging' => ['limit' => max(1, min(100, $limit))]]];
            if ($cursor) $body['query']['cursorPaging']['cursor'] = $cursor;

            $resp = Http::withHeaders([
                'Authorization' => $this->authHeader($accessToken),
                'Content-Type'  => 'application/json',
            ])->post('https://www.wixapis.com/loyalty-rewards/v1/rewards/query', $body);

            if (!$resp->ok()) break;

            $json = $resp->json() ?: [];
            foreach (($json['rewards'] ?? []) as $r) $all[] = $r;

            $cursor = $json['pagingMetadata']['cursors']['next'] ?? null;
        } while (!empty($json['pagingMetadata']['hasNext']) && $cursor);

        // Sort by createdDate oldest-first if present
        usort($all, function ($a, $b) {
            $ta = isset($a['createdDate']) ? strtotime($a['createdDate']) : PHP_INT_MAX;
            $tb = isset($b['createdDate']) ? strtotime($b['createdDate']) : PHP_INT_MAX;
            return $ta <=> $tb;
        });

        return $all;
    }

    // =========================================================
    // HELPERS — Accounts (query + normalize)
    // =========================================================
    private function queryAllAccounts(string $accessToken): array
    {
        $all = []; $page = 0; $cursor = null;

        // Cursor-first (shape with pagingMetadata.hasNext)
        while (true) {
            $page++;

            $body = ['query' => (object)[]];
            if ($cursor) $body['cursor'] = $cursor;

            $resp = Http::withHeaders([
                'Authorization' => $this->authHeader($accessToken),
                'Content-Type'  => 'application/json',
            ])->post('https://www.wixapis.com/loyalty-accounts/v1/accounts/query', $body);

            if (!$resp->ok()) {
                WixHelper::log('Export Loyalty', 'Accounts query failed: ' . $resp->status() . ' | ' . $resp->body() . '.', 'warn');
                break;
            }

            $json = $resp->json() ?: [];
            $items = $json['loyaltyAccounts'] ?? $json['accounts'] ?? [];

            if (!is_array($items) || count($items) === 0) {
                if ($page === 1) {
                    WixHelper::log('Export Loyalty', 'Accounts first page empty; trying paging(limit/offset) fallback.', 'warn');
                }
                break;
            }

            $all = array_merge($all, $items);

            $hasNext    = (bool)($json['pagingMetadata']['hasNext'] ?? false);
            $nextCursor = $json['pagingMetadata']['cursors']['next'] ?? null;

            if (!$hasNext || !$nextCursor) break;
            $cursor = $nextCursor;

            if ($page > 10000) break; // safety
        }

        // Fallback to offset paging if needed
        if (empty($all)) {
            $limit = 200; $offset = 0;
            do {
                $page++;

                $body = ['query' => ['paging' => ['limit' => $limit, 'offset' => $offset]]];

                $resp = Http::withHeaders([
                    'Authorization' => $this->authHeader($accessToken),
                    'Content-Type'  => 'application/json',
                ])->post('https://www.wixapis.com/loyalty-accounts/v1/accounts/query', $body);

                if (!$resp->ok()) break;

                $json = $resp->json() ?: [];
                $items = $json['loyaltyAccounts'] ?? $json['accounts'] ?? [];
                foreach ($items as $i) $all[] = $i;

                $count  = (int)($json['pagingMetadata']['count'] ?? count($items));
                $offset += $count;
            } while (!empty($count));
        }

        return [$all, $page];
    }

    private function extractAccountEmail(array $acc): ?string
    {
        if (!empty($acc['contact']['email'])) return $acc['contact']['email'];
        if (!empty($acc['contact']['primaryEmail']['email'])) return $acc['contact']['primaryEmail']['email'];
        if (!empty($acc['contact']['info']['emails'][0]['email'])) return $acc['contact']['info']['emails'][0]['email'];
        if (!empty($acc['contact']['emails']['items'][0]['email'])) return $acc['contact']['emails']['items'][0]['email'];
        return null;
    }

    private function extractCreatedTs(array $acc): int
    {
        foreach (['createdDate','updatedDate','lastActivityDate'] as $k) {
            if (!empty($acc[$k])) {
                $ts = strtotime($acc[$k]);
                if ($ts !== false) return $ts;
            }
        }
        return PHP_INT_MAX;
    }

    private function prepareAccountsForImport(array $accountsRaw): array
    {
        $prepared = [];
        foreach ($accountsRaw as $acc) {
            if (!is_array($acc)) continue;

            $sourceId  = $acc['id'] ?? null;
            $email     = $this->extractAccountEmail($acc);
            $name      = $acc['contact']['displayName'] ?? $acc['contact']['name'] ?? null;
            $balance   = isset($acc['points']['balance']) && is_numeric($acc['points']['balance']) ? (int)$acc['points']['balance'] : null;
            $createdAt = $this->extractCreatedTs($acc);

            $prepared[] = [
                '__source_id'      => $sourceId,
                '__email'          => $email,
                '__name'           => $name,
                '__points_balance' => $balance,
                '__date'           => $createdAt,
            ];
        }

        usort($prepared, fn($a, $b) => ($a['__date'] <=> $b['__date']));
        return $prepared;
    }

    // =========================================================
    // HELPERS — Contacts index (EMAIL → contactId) & account ops
    // =========================================================
    /**
     * Build a complete map of TARGET contacts:
     *   email(lowercased) => contactId
     * Uses the GET pager (no filters) to avoid 400 "unsupported filter".
     */
    private function indexTargetContactsByEmail(string $accessToken, int $limit = 1000): array
    {
        $map = [];
        $offset = 0;
        $total  = 0;

        do {
            $query = [
                'paging.limit'  => $limit,
                'paging.offset' => $offset,
                'fieldsets'     => 'FULL',
            ];

            $resp = Http::withHeaders([
                'Authorization' => $this->authHeader($accessToken),
                'Content-Type'  => 'application/json',
            ])->get('https://www.wixapis.com/contacts/v4/contacts', $query);

            if (!$resp->ok()) {
                WixHelper::log('Import Loyalty', 'indexTargetContactsByEmail GET failed → ' . $resp->status() . ' | ' . $resp->body() . '.', 'warn');
                break;
            }

            $data = $resp->json();
            $contacts = $data['contacts'] ?? [];
            foreach ($contacts as $c) {
                $cid = $c['id'] ?? null;

                // primaryInfo.email
                $primary = strtolower((string)($c['primaryInfo']['email'] ?? ''));
                if ($cid && $primary !== '') $map[$primary] = $cid;

                // all secondary emails
                foreach ($c['info']['emails']['items'] ?? [] as $it) {
                    $em = strtolower((string)($it['email'] ?? ''));
                    if ($cid && $em !== '') $map[$em] = $cid;
                }
            }

            $count = (int)($data['pagingMetadata']['count'] ?? 0);
            $total = (int)($data['pagingMetadata']['total'] ?? 0);
            $offset += $count;
        } while ($count > 0 && $offset < $total);

        WixHelper::log('Import Loyalty', 'Indexed ' . count($map) . ' contact emails from target.', 'info');
        return $map;
    }

    /**
     * Create loyalty account for TARGET contactId (email-resolved).
     * Tries top-level `{contactId}` then fallback `{account:{contactId}}`.
     */
    private function createAccountInWix(string $accessToken, string $contactId): array
    {
        $contactId = trim($contactId);

        // 1) Top-level body
        $body1 = ['contactId' => $contactId];

        $r1 = Http::withHeaders([
            'Authorization' => $this->authHeader($accessToken),
            'Content-Type'  => 'application/json',
        ])->post('https://www.wixapis.com/loyalty-accounts/v1/accounts', $body1);

        if ($r1->ok()) {
            $json = $r1->json() ?: [];
            $id   = $json['account']['id'] ?? $json['id'] ?? null;
            if ($id) {
                return ['ok' => true, 'id' => $id, 'raw' => $r1->body(), 'json' => $json];
            }
        }

        // 2) Fallback nested
        $body2 = ['account' => ['contactId' => $contactId]];

        $r2 = Http::withHeaders([
            'Authorization' => $this->authHeader($accessToken),
            'Content-Type'  => 'application/json',
        ])->post('https://www.wixapis.com/loyalty-accounts/v1/accounts', $body2);

        $raw  = $r2->body();
        $json = $r2->json() ?: [];
        $id   = $json['account']['id'] ?? $json['id'] ?? null;
        $ok   = $r2->ok() && $id;

        if (!$ok) {
            WixHelper::log('Import Loyalty', 'Create failed: ' . $r2->status() . ' | ' . $raw . '.', 'warn');
        }

        return ['ok' => $ok, 'id' => $id, 'raw' => $raw, 'json' => $json];
    }
    
    /**
     * Set an account’s balance to an exact value using REST Adjust Points.
     * Prefer "balance" (final value) over "amount" (delta) for migrations.
     */
    /**
     * Set an account’s balance to an exact value.
     * Uses adjust-points with { balance, revision } and verifies the result.
     */
    private function setAccountBalance(string $accessToken, string $accountId, int $desiredBalance, ?int $currentBalance = null): void
    {
        $accountId = trim($accountId);

        // --- 1) Read current balance + revision (supports wrapped/unwrapped) ---
        $read = function () use ($accessToken, $accountId): array {
            $resp = Http::withHeaders(['Authorization' => $this->authHeader($accessToken)])
                ->get('https://www.wixapis.com/loyalty-accounts/v1/accounts/' . urlencode($accountId));

            $json = $resp->ok() ? ($resp->json() ?: []) : [];
            $balance  = (int) data_get($json, 'account.points.balance', data_get($json, 'points.balance', 0));
            $revision = (int) data_get($json, 'account.revision',       data_get($json, 'revision', 0));
            return [$balance, $revision, $resp->status(), $json];
        };

        [$current, $revision] = ($currentBalance === null)
            ? $read()
            : [$currentBalance, 0]; // will re-read revision right away if 0

        if ($revision < 1) { // after create it’s usually 1, but read if we don’t have it
            [, $revision] = $read();
        }

        if ($desiredBalance === $current) {
            WixHelper::log('Import Loyalty', 'Balance already ' . $desiredBalance . ' for ' . $this->maskGuid($accountId) . '; no adjust needed.', 'debug');
            return;
        }

        $endpoint = 'https://www.wixapis.com/loyalty-accounts/v1/accounts/' . urlencode($accountId) . '/adjust-points';
        $headers  = [
            'Authorization'   => $this->authHeader($accessToken),
            'Content-Type'    => 'application/json',
            'Idempotency-Key' => bin2hex(random_bytes(16)),
        ];

        // --- 2) Try absolute set first (idempotent for migrations) ---
        $bodyBalance = [
            'balance'  => $desiredBalance,
            'revision' => max(1, (int) $revision),
        ];
        $r1 = Http::withHeaders($headers)->post($endpoint, $bodyBalance);

        $ok = $r1->ok();
        $status = $r1->status();

        // If revision error or other failure → refetch latest revision and retry once
        $needsRetryForRevision = !$ok && in_array($status, [400, 409, 412], true);

        if ($needsRetryForRevision) {
            // Re-read latest revision, then retry absolute set once
            [, $latestRevision] = $read();
            if ($latestRevision >= 1) {
                $headers['Idempotency-Key'] = bin2hex(random_bytes(16));
                $r1b = Http::withHeaders($headers)->post($endpoint, [
                    'balance'  => $desiredBalance,
                    'revision' => (int) $latestRevision,
                ]);
                $ok = $r1b->ok();
                $status = $r1b->status();
            }
        }

        // --- 3) Fallback to delta if absolute still not accepted ---
        if (!$ok) {
            // compute delta from current (re-read to be safe)
            [$nowBalance, $nowRevision] = $read();
            $delta = $desiredBalance - (int) $nowBalance;

            $headers['Idempotency-Key'] = bin2hex(random_bytes(16));
            $r2 = Http::withHeaders($headers)->post($endpoint, [
                'amount'   => $delta,
                'revision' => max(1, (int) $nowRevision),
            ]);

            if (!$r2->ok()) {
                WixHelper::log(
                    'Import Loyalty',
                    'Adjust failed for ' . $this->maskGuid($accountId) . ' → ' . $r2->status() . ' | ' . $r2->body() . '.',
                    'warn'
                );
            }
        }

        // --- 4) Verify final balance (handle eventual consistency + wrapped JSON) ---
        usleep(200 * 1000);
        [$after] = $read();

        if ((int)$after !== (int)$desiredBalance) {
            // One last absolute set with fresh revision
            [, $rev3] = $read();
            $headers['Idempotency-Key'] = bin2hex(random_bytes(16));
            $retry = Http::withHeaders($headers)->post($endpoint, [
                'balance'  => $desiredBalance,
                'revision' => max(1, (int)$rev3),
            ]);

            usleep(150 * 1000);
            [$after2] = $read();

            WixHelper::log(
                'Import Loyalty',
                'Adjust verify for ' . $this->maskGuid($accountId) . ': target=' . $desiredBalance . ', after=' . $after . ', afterRetry=' . $after2 . ', http=' . $retry->status() . '.',
                ((int)$after2 === (int)$desiredBalance) ? 'info' : 'warn'
            );
        } else {
            WixHelper::log('Import Loyalty', 'Adjusted ' . $this->maskGuid($accountId) . ' → balance now ' . $after . '.', 'info');
        }
    }




    /** Simple UUID v4 for Idempotency-Key */
    private function uuidV4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    // =========================================================
    // HELPERS — Target accounts index (duplicate detection)
    // =========================================================
    private function indexTargetLoyaltyAccounts(string $accessToken): array
    {
        [$existing] = $this->queryAllAccounts($accessToken);
        $byContact  = [];
        foreach ($existing as $acc) {
            $cid = $acc['contactId'] ?? ($acc['contact']['id'] ?? null);
            if ($cid) {
                $byContact[$cid] = [
                    'id'      => $acc['id'] ?? null,
                    'balance' => (int)($acc['points']['balance'] ?? 0),
                ];
            }
        }
        return $byContact;
    }

    // =========================================================
    // UTILS
    // =========================================================
    private function authHeader(string $token): string
    {
        return preg_match('/^Bearer\s+/i', $token) ? $token : ('Bearer ' . $token);
    }

    private function isGuid(?string $v): bool
    {
        if (!$v) return false;
        $v = trim($v, "{} \t\n\r\0\x0B");
        return (bool)preg_match('/^[0-9a-fA-F]{8}\-[0-9a-fA-F]{4}\-[0-9a-fA-F]{4}\-[0-9a-fA-F]{4}\-[0-9a-fA-F]{12}$/', $v);
    }

    private function maskGuid(?string $v): string
    {
        if (!$v || strlen($v) < 8) return (string)$v;
        return substr($v, 0, 8) . '…' . substr($v, -4);
    }
}
