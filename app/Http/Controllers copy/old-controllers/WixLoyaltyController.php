<?php

namespace App\Http\Controllers;

use App\Helpers\WixHelper;
use App\Models\WixLoyaltyMigration;
use App\Models\WixStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WixLoyaltyController extends Controller
{
    // =========================================================
    // Export Loyalty Accounts — append-only pending rows
    // =========================================================
    public function export(WixStore $store)
    {
        $userId      = Auth::id() ?: 1;
        $fromStoreId = $store->instance_id;

        WixHelper::log('Export Loyalty', "Export started for store: {$store->store_name} ({$fromStoreId})", 'info');

        $accessToken = WixHelper::getAccessToken($fromStoreId);
        if (!$accessToken) {
            WixHelper::log('Export Loyalty', "Failed: Could not get access token for instanceId: $fromStoreId", 'error');
            return response()->json(['error' => 'You are not authorized to access.'], 401);
        }

        // Snapshot program (useful for auditing)
        $program = $this->getLoyaltyProgram($accessToken);

        // Pull all accounts (paged)
        $accounts = $this->getLoyaltyAccountsFromWix($accessToken, 200);
        if (isset($accounts['error'])) {
            WixHelper::log('Export Loyalty', "API error: ".$accounts['raw'], 'error');
            return response()->json(['error' => $accounts['error']], 500);
        }

        // Append-only pending rows
        $pendingSaved = 0;
        foreach ($accounts as $acc) {
            $email    = $this->extractEmailFromAccount($acc);
            $starting = (int)($acc['points']['balance'] ?? 0);

            WixLoyaltyMigration::create([
                'user_id'                => $userId,
                'from_store_id'          => $fromStoreId,
                'to_store_id'            => null,
                'contact_email'          => $email,
                'source_account_id'      => $acc['id'] ?? null,
                'destination_account_id' => null,
                'starting_balance'       => $starting,
                'status'                 => 'pending',
                'error_message'          => null,
            ]);
            $pendingSaved++;
        }

        $payload = [
            'from_store_id' => $fromStoreId,
            'program'       => $program,
            'accounts'      => $accounts,
        ];

        WixHelper::log(
            'Export Loyalty',
            "Exported ".count($accounts)." account(s); saved {$pendingSaved} pending row(s).",
            'success'
        );

        return response()->streamDownload(function () use ($payload) {
            echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }, 'loyalty-accounts.json', ['Content-Type' => 'application/json']);
    }

    // =========================================================
    // Import Loyalty Accounts — oldest-first + fast path (bulk)
    // =========================================================
    public function import(Request $request, WixStore $store)
    {
        $userId    = Auth::id() ?: 1;
        $toStoreId = $store->instance_id;

        WixHelper::log('Import Loyalty', "Import started for store: {$store->store_name} ({$toStoreId})", 'info');

        $accessToken = WixHelper::getAccessToken($toStoreId);
        if (!$accessToken) {
            WixHelper::log('Import Loyalty', "Failed: Could not get access token.", 'error');
            return back()->with('error', 'Could not get Wix access token.');
        }

        if (!$request->hasFile('loyalty_json')) {
            WixHelper::log('Import Loyalty', "No file uploaded.", 'error');
            return back()->with('error', 'No file uploaded.');
        }

        $file    = $request->file('loyalty_json');
        $json    = file_get_contents($file->getRealPath());
        $decoded = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE || !isset($decoded['from_store_id'], $decoded['accounts']) || !is_array($decoded['accounts'])) {
            WixHelper::log('Import Loyalty', "Invalid JSON structure.", 'error');
            return back()->with('error', 'Invalid JSON structure. Required keys: from_store_id and accounts.');
        }

        $fromStoreId = $decoded['from_store_id'];
        $accounts    = $decoded['accounts'];

        // --- Oldest → newest (robust resolver)
        $createdAtMillis = function (array $item): int {
            foreach (['createdDate','dateCreated','createdAt','creationDate','date_created'] as $k) {
                if (array_key_exists($k, $item)) {
                    $v = $item[$k];
                    if (is_numeric($v)) return (int)$v;
                    if (is_string($v)) { $ts = strtotime($v); if ($ts !== false) return $ts * 1000; }
                }
            }
            foreach ([['audit','createdDate'], ['metadata','createdDate']] as $path) {
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
        usort($accounts, fn($a, $b) => $createdAtMillis($a) <=> $createdAtMillis($b));

        // Ensure program is ACTIVE before creating accounts
        $this->ensureProgramActive($accessToken);

        // -------- SPEEDUPS: preload contacts & accounts --------
        // Collect all emails from incoming payload
        $allEmails = [];
        foreach ($accounts as $a) {
            $em = $this->extractEmailFromAccount($a);
            if ($em) $allEmails[] = strtolower($em);
        }

        // 1) Bulk resolve emails -> contactIds on target
        $emailToContactId = $this->bulkFindContactsByEmail($accessToken, $allEmails);

        // 2) Preload existing loyalty accounts on target (contactId => id,balance)
        $acctByContactId  = $this->indexTargetLoyaltyAccounts($accessToken);

        // -------- Import loop (uses caches, minimal HTTP per item) --------
        $imported            = 0;
        $skippedDuplicates   = 0;
        $errors              = [];

        // Claim/resolve helpers
        $claimPendingRow = function (?string $email) use ($userId, $fromStoreId) {
            return DB::transaction(function () use ($userId, $fromStoreId, $email) {
                $row = null;
                if ($email) {
                    $row = WixLoyaltyMigration::where('user_id', $userId)
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
                    $row = WixLoyaltyMigration::where('user_id', $userId)
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
                $existing = WixLoyaltyMigration::where('user_id', $userId)
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

        foreach ($accounts as $acc) {
            $email            = strtolower((string)$this->extractEmailFromAccount($acc));
            $startingBalance  = (int)($acc['points']['balance'] ?? 0);

            $claimed   = $claimPendingRow($email);
            $targetRow = $resolveTargetRow($claimed, $email);

            $targetContactId = $emailToContactId[$email] ?? null;
            if (!$targetContactId) {
                $msg = "Target contact not found by email ".($email ?: '(none)').".";
                if ($targetRow) {
                    $targetRow->update([
                        'to_store_id'     => $toStoreId,
                        'status'          => 'failed',
                        'error_message'   => $msg,
                        'contact_email'   => $email ?: $targetRow->contact_email,
                        'starting_balance'=> $startingBalance,
                    ]);
                }
                $errors[] = $msg;
                WixHelper::log('Import Loyalty', $msg, 'warn');
                continue;
            }

            // Duplicate check using preloaded index
            if (isset($acctByContactId[$targetContactId]) && !empty($acctByContactId[$targetContactId]['id'])) {
                $existingAccId = $acctByContactId[$targetContactId]['id'];
                $existingBal   = (int)$acctByContactId[$targetContactId]['balance'];

                // Sync only when different
                if ($existingBal !== $startingBalance) {
                    $this->setAccountBalance($accessToken, $existingAccId, $startingBalance, $existingBal);
                    $acctByContactId[$targetContactId]['balance'] = $startingBalance;
                    WixHelper::log('Import Loyalty', "Aligned balance for {$email} from {$existingBal} → {$startingBalance}", 'info');
                }

                $skippedDuplicates++;
                if ($targetRow) {
                    $targetRow->update([
                        'to_store_id'                => $toStoreId,
                        'destination_account_id'     => $existingAccId,
                        'status'                     => 'skipped',
                        'error_message'              => 'Duplicate: loyalty account already exists in target.',
                        'contact_email'              => $email ?: $targetRow->contact_email,
                        'starting_balance'           => $startingBalance,
                    ]);
                }
                WixHelper::log('Import Loyalty', "Skipped duplicate for {$email} (account {$existingAccId})", 'warn');
                continue;
            }

            // Create new account, then set balance if needed
            $createResp   = $this->createLoyaltyAccountInWix($accessToken, $targetContactId);
            $newAccountId = $createResp['id'] ?? null;

            if ($newAccountId) {
                if ($startingBalance !== 0) {
                    // current is 0 for new accounts
                    $this->setAccountBalance($accessToken, $newAccountId, $startingBalance, 0);
                }

                // Update local index so following rows benefit
                $acctByContactId[$targetContactId] = ['id' => $newAccountId, 'balance' => $startingBalance];

                $imported++;
                if ($targetRow) {
                    $targetRow->update([
                        'to_store_id'                => $toStoreId,
                        'destination_account_id'     => $newAccountId,
                        'status'                     => 'success',
                        'error_message'              => null,
                        'contact_email'              => $email ?: $targetRow->contact_email,
                        'starting_balance'           => $startingBalance,
                    ]);
                }
                WixHelper::log('Import Loyalty', "Imported loyalty account for {$email} (ID: {$newAccountId}) with balance {$startingBalance}.", 'success');
            } else {
                $errMsg = json_encode(['contactId' => $targetContactId, 'response' => $createResp]);
                if ($targetRow) {
                    $targetRow->update([
                        'to_store_id'            => $toStoreId,
                        'destination_account_id' => null,
                        'status'                 => 'failed',
                        'error_message'          => $errMsg,
                        'contact_email'          => $email ?: $targetRow->contact_email,
                        'starting_balance'       => $startingBalance,
                    ]);
                }
                $errors[] = $errMsg;
                WixHelper::log('Import Loyalty', "Failed to create account: {$errMsg}", 'error');
            }
        }

        $suffix = " Imported: {$imported}. Duplicates skipped: {$skippedDuplicates}."
                . (count($errors) ? " Some errors: ".implode("; ", $errors) : '');

        if ($imported > 0) {
            WixHelper::log('Import Loyalty', "Import finished.".$suffix, count($errors) ? 'warning' : 'success');
            return back()->with('success', 'Loyalty accounts import finished.'.$suffix);
        } else {
            WixHelper::log('Import Loyalty', "No accounts imported.".$suffix, 'error');
            return back()->with('error', 'No loyalty accounts imported.'.$suffix);
        }
    }

    // =========================================================
    // Utilities (Loyalty + Contacts)
    // =========================================================

    private function getLoyaltyProgram(string $accessToken): array
    {
        $resp = Http::withHeaders([
            'Authorization' => $accessToken,
        ])->get('https://www.wixapis.com/loyalty-programs/v1/program');

        return $resp->ok() ? ($resp->json() ?? []) : [];
    }

    private function ensureProgramActive(string $accessToken): void
    {
        $prog   = $this->getLoyaltyProgram($accessToken);
        $status = $prog['program']['status'] ?? $prog['status'] ?? null;
        if ($status !== 'ACTIVE') {
            $resp = Http::withHeaders([
                'Authorization' => $accessToken,
                'Content-Type'  => 'application/json',
            ])->post('https://www.wixapis.com/loyalty-programs/v1/program/activate', []);
            WixHelper::log('Import Loyalty', "Program activation → {$resp->status()} | ".$resp->body(), $resp->ok() ? 'info' : 'warn');
        }
    }

    /**
     * Page through all loyalty accounts.
     * Returns a flat array of accounts.
     */
    private function getLoyaltyAccountsFromWix(string $accessToken, int $limit = 200): array
    {
        $all = [];
        $offset = 0;

        do {
            $body = [
                'query' => [
                    'paging' => ['limit' => $limit, 'offset' => $offset],
                ],
            ];

            $resp = Http::withHeaders([
                'Authorization' => $accessToken,
                'Content-Type'  => 'application/json',
            ])->post('https://www.wixapis.com/loyalty-accounts/v1/accounts/query', $body);

            if (!$resp->ok()) {
                return ['error' => 'Failed to fetch loyalty accounts from Wix.', 'raw' => $resp->body()];
            }

            $data = $resp->json();
            foreach ($data['accounts'] ?? [] as $acc) $all[] = $acc;

            $count  = $data['pagingMetadata']['count'] ?? 0;
            $offset += $count;
        } while ($count > 0);

        return $all;
    }

    /**
     * Bulk contacts lookup by email (chunks of 50).
     * Returns: email(lowercased) => contactId
     */
    private function bulkFindContactsByEmail(string $accessToken, array $emails): array
    {
        $emails = array_values(array_unique(array_filter($emails)));
        $chunkSize = 50;
        $map = [];

        foreach (array_chunk($emails, $chunkSize) as $chunk) {
            if (empty($chunk)) continue;

            $or = [];
            foreach ($chunk as $em) {
                $or[] = ["info.emails.items.email" => ["\$eq" => $em]];
            }

            $body = [
                "query" => [
                    "filter" => ["\$or" => $or],
                    "paging" => ["limit" => max(1, count($chunk))]
                ]
            ];

            $resp = Http::withHeaders([
                'Authorization' => $accessToken,
                'Content-Type'  => 'application/json'
            ])->post('https://www.wixapis.com/contacts/v4/contacts/query', $body);

            if ($resp->ok()) {
                foreach ($resp->json('contacts') ?? [] as $c) {
                    $cid   = $c['id'] ?? null;
                    $items = $c['info']['emails']['items'] ?? [];
                    foreach ($items as $it) {
                        if (!empty($it['email']) && $cid) {
                            $map[strtolower($it['email'])] = $cid;
                        }
                    }
                }
            } else {
                WixHelper::log('Import Loyalty', 'bulkFindContactsByEmail failed chunk → '.$resp->status().' | '.$resp->body(), 'warn');
            }
        }
        return $map;
    }

    /**
     * Build a map of all existing loyalty accounts on the target.
     * Returns: contactId => ['id' => accountId, 'balance' => int]
     */
    private function indexTargetLoyaltyAccounts(string $accessToken): array
    {
        $existing = $this->getLoyaltyAccountsFromWix($accessToken, 200);
        $byContact = [];
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

    private function extractEmailFromAccount(array $account): ?string
    {
        $paths = [
            ['contact', 'primaryInfo', 'email'],
            ['contact', 'info', 'emails', 'items', 0, 'email'],
            ['contact', 'emails', 'items', 0, 'email'],
        ];
        foreach ($paths as $path) {
            $cur = $account;
            foreach ($path as $p) {
                if (is_array($cur) && array_key_exists($p, $cur)) {
                    $cur = $cur[$p];
                } else {
                    $cur = null; break;
                }
            }
            if (is_string($cur) && strlen($cur)) return $cur;
        }
        return null;
    }

    private function createLoyaltyAccountInWix(string $accessToken, string $contactId): array
    {
        $body = [
            'account' => [
                'contactId' => $contactId,
            ],
        ];

        $resp = Http::withHeaders([
            'Authorization' => $accessToken,
            'Content-Type'  => 'application/json',
        ])->post('https://www.wixapis.com/loyalty-accounts/v1/accounts', $body);

        WixHelper::log('Import Loyalty', "createAccount → {$resp->status()} | ".$resp->body(), $resp->ok() ? 'debug' : 'warn');

        return $resp->json() ?? [];
    }

    /**
     * Adjust account balance to desired value using delta.
     * If $currentBalance is provided, we avoid an extra GET.
     */
    private function setAccountBalance(string $accessToken, string $accountId, int $desiredBalance, ?int $currentBalance = null): void
    {
        $current = $currentBalance;

        if ($current === null) {
            $get = Http::withHeaders(['Authorization' => $accessToken])
                ->get("https://www.wixapis.com/loyalty-accounts/v1/accounts/{$accountId}");
            $current = $get->ok() ? (int)($get->json('points.balance') ?? 0) : 0;
        }

        $delta = $desiredBalance - $current;
        if ($delta === 0) {
            return;
        }

        $adj = Http::withHeaders([
            'Authorization' => $accessToken,
            'Content-Type'  => 'application/json',
        ])->post("https://www.wixapis.com/loyalty-accounts/v1/accounts/{$accountId}/adjust-points", [
            'reason' => 'DATA_MIGRATION',
            'amount' => $delta, // positive or negative
        ]);

        WixHelper::log(
            'Import Loyalty',
            "Adjust balance by {$delta} for account {$accountId} → {$adj->status()} | ".$adj->body(),
            $adj->ok() ? 'info' : 'warn'
        );
    }
}
