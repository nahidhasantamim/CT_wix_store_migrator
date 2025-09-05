<?php

namespace App\Helpers;

use Illuminate\Support\Facades\Http;

class WixContactHelper
{
    /**
     * Retry wrapper with jitter for transient failures (429/5xx).
     */
    private static function withRetry(callable $fn, int $maxAttempts = 3, int $baseSleepMs = 500)
    {
        $attempt = 0;
        do {
            $attempt++;
            try {
                $resp = $fn();
                $code = is_object($resp) && method_exists($resp, 'status') ? $resp->status() : null;

                if ($code !== null && ($code >= 200 && $code < 300)) {
                    return $resp;
                }
                if ($code !== null && (in_array($code, [429]) || ($code >= 500 && $code < 600))) {
                    usleep(($baseSleepMs + random_int(0, 400)) * 1000);
                    continue;
                }
                return $resp;
            } catch (\Throwable $e) {
                if ($attempt >= $maxAttempts) throw $e;
                usleep(($baseSleepMs + random_int(0, 400)) * 1000);
            }
        } while ($attempt < $maxAttempts);

        return null;
    }

    /**
     * List ALL contacts with FULL fieldset (paginates).
     * Returns ['contacts'=> [...]]
     */
    public static function listAllContacts(string $token, int $limit = 1000): array
    {
        $contacts = [];
        $offset   = 0;
        do {
            $query = [
                'paging.limit'  => $limit,
                'paging.offset' => $offset,
                'fieldsets'     => 'FULL'
            ];

            $resp = self::withRetry(function () use ($token, $query) {
                return Http::withHeaders([
                    'Authorization' => $token,
                    'Content-Type'  => 'application/json'
                ])->get('https://www.wixapis.com/contacts/v4/contacts', $query);
            });

            if (!$resp || !$resp->ok()) break;

            $data  = $resp->json() ?: [];
            $page  = $data['contacts'] ?? [];
            $contacts = array_merge($contacts, $page);

            $count = $data['pagingMetadata']['count'] ?? 0;
            $total = $data['pagingMetadata']['total'] ?? 0;
            $offset += $count;

            if ($count === 0 || $offset >= $total) break;
        } while (true);

        return ['contacts' => $contacts];
    }

    /** Find a contact by email in target store. */
    public static function findContactByEmail(string $token, string $email): ?array
    {
        $query = [
            "query" => [
                "filter" => [
                    "info.emails.items.email" => ["\$eq" => $email]
                ],
                "paging" => ["limit" => 1]
            ]
        ];
        $resp = self::withRetry(function () use ($token, $query) {
            return Http::withHeaders([
                'Authorization' => $token,
                'Content-Type'  => 'application/json'
            ])->post('https://www.wixapis.com/contacts/v4/contacts/query', $query);
        });

        if ($resp && $resp->ok() && !empty($resp->json('contacts'))) {
            return $resp->json('contacts')[0];
        }
        return null;
    }

    /** Create a contact. $info is the sanitized 'info' array. */
    public static function createContact(string $token, array $info, bool $allowDuplicates = true): array
    {
        $payload = [
            'info'             => (object)$info,
            'allowDuplicates'  => $allowDuplicates
        ];

        $resp = self::withRetry(function () use ($token, $payload) {
            return Http::withHeaders([
                'Authorization' => $token,
                'Content-Type'  => 'application/json'
            ])->post('https://www.wixapis.com/contacts/v4/contacts', $payload);
        });

        return $resp ? ($resp->json() ?: []) : [];
    }

    /** Ensure a label exists by displayName; return ['key'=>...] or []. */
    public static function ensureLabel(string $token, string $displayName): array
    {
        $resp = self::withRetry(function () use ($token, $displayName) {
            return Http::withHeaders([
                'Authorization' => $token,
                'Content-Type'  => 'application/json'
            ])->post('https://www.wixapis.com/contacts/v4/labels', [
                'displayName' => $displayName
            ]);
        });

        return ($resp && $resp->ok()) ? ($resp->json() ?: []) : [];
    }

    /** Get extended-field defs (contacts). Returns map key => ['displayName','dataType'] */
    public static function listContactExtendedFields(string $token): array
    {
        $fields = [];
        $offset = 0;
        $limit  = 100;
        do {
            $query = ['paging.limit' => $limit, 'paging.offset' => $offset];
            $resp  = self::withRetry(function () use ($token, $query) {
                return Http::withHeaders([
                    'Authorization' => $token,
                    'Content-Type'  => 'application/json'
                ])->get('https://www.wixapis.com/contacts/v4/extended-fields', $query);
            });
            if (!$resp || !$resp->ok()) break;

            $data = $resp->json() ?: [];
            foreach ($data['extendedFields'] ?? [] as $f) {
                $fields[$f['key']] = [
                    'displayName' => $f['displayName'] ?? null,
                    'dataType'    => $f['dataType'] ?? null,
                ];
            }
            $count  = $data['pagingMetadata']['count'] ?? 0;
            $offset += $count;
            if ($count === 0) break;
        } while (true);

        return $fields;
    }

    /** Create a contact extended field; returns key or null. */
    public static function createContactExtendedField(string $token, string $displayName, string $dataType = 'TEXT'): ?string
    {
        $payload = ['displayName' => $displayName, 'dataType' => $dataType ?: 'TEXT'];
        $resp = self::withRetry(function () use ($token, $payload) {
            return Http::withHeaders([
                'Authorization' => $token,
                'Content-Type'  => 'application/json'
            ])->post('https://www.wixapis.com/contacts/v4/extended-fields', $payload);
        });

        if ($resp && $resp->ok()) {
            $json = $resp->json();
            return $json['key'] ?? null;
        }
        return null;
    }

    /** Small utility: deep clean empty arrays/nulls. */
    public static function cleanEmpty(array $arr): array
    {
        foreach ($arr as $k => $v) {
            if (is_array($v)) {
                $arr[$k] = self::cleanEmpty($v);
                if ($arr[$k] === [] || $arr[$k] === null) unset($arr[$k]);
            } elseif ($v === [] || $v === null) {
                unset($arr[$k]);
            }
        }
        return $arr;
    }

    // -------- Attachments (optional) --------

    public static function listContactAttachments(string $token, string $contactId): array
    {
        $attachments = [];
        $limit = 100; $offset = 0;
        do {
            $query = ['paging.limit' => $limit, 'paging.offset' => $offset];
            $resp  = self::withRetry(function () use ($token, $contactId, $query) {
                return Http::withHeaders(['Authorization' => $token])
                    ->get("https://www.wixapis.com/contacts/v4/attachments/$contactId", $query);
            });
            if (!$resp || !$resp->ok()) break;
            $data = $resp->json() ?: [];
            $attachments = array_merge($attachments, $data['attachments'] ?? []);
            $count  = $data['pagingMetadata']['count'] ?? 0;
            $offset += $count;
            if ($count === 0) break;
        } while (true);
        return $attachments;
    }

    public static function downloadContactAttachment(string $token, string $contactId, string $attachmentId): ?array
    {
        $resp = self::withRetry(function () use ($token, $contactId, $attachmentId) {
            return Http::withHeaders(['Authorization' => $token])
                ->get("https://www.wixapis.com/contacts/v4/attachments/$contactId/$attachmentId");
        });
        if ($resp && $resp->ok()) {
            return [
                'filename' => $resp->header('content-disposition'),
                'mimeType' => $resp->header('content-type'),
                'content'  => $resp->body(),
            ];
        }
        return null;
    }

    public static function generateAttachmentUploadUrl(string $token, string $contactId, string $fileName, string $mimeType): ?array
    {
        $resp = self::withRetry(function () use ($token, $contactId, $fileName, $mimeType) {
            return Http::withHeaders([
                'Authorization' => $token,
                'Content-Type'  => 'application/json'
            ])->post("https://www.wixapis.com/contacts/v4/attachments/$contactId/upload-url", [
                'fileName' => $fileName,
                'mimeType' => $mimeType,
            ]);
        });

        return ($resp && $resp->ok()) ? ($resp->json() ?: null) : null;
    }
}
