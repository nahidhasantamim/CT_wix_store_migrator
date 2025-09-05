<?php

namespace App\Helpers;

use Illuminate\Support\Facades\Http;

class WixMediaHelper
{
    /**
     * Simple retry wrapper with jitter for transient failures (429/5xx).
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

                // Retry on 429 or 5xx
                if ($code !== null && (in_array($code, [429]) || ($code >= 500 && $code < 600))) {
                    usleep(($baseSleepMs + random_int(0, 400)) * 1000);
                    continue;
                }

                return $resp; // non-retriable or null
            } catch (\Throwable $e) {
                if ($attempt >= $maxAttempts) {
                    throw $e;
                }
                usleep(($baseSleepMs + random_int(0, 400)) * 1000);
            }
        } while ($attempt < $maxAttempts);

        return null;
    }

    public static function listAllFolders(string $token): ?array
    {
        $resp = self::withRetry(function () use ($token) {
            return Http::withHeaders(['Authorization' => $token])
                ->get("https://www.wixapis.com/site-media/v1/folders");
        });

        if (!$resp) {
            WixHelper::log('Migrate Media', 'Folders API: null response', 'error');
            return null;
        }
        if (!$resp->ok()) {
            WixHelper::log('Migrate Media', 'Folders API error: ' . $resp->status() . ' | ' . $resp->body(), 'error');
            return null;
        }

        $folders = $resp->json('folders') ?? [];
        WixHelper::log('Migrate Media', 'Folders API: ok, count=' . count($folders), 'debug');
        return $folders;
    }

    public static function listAllFilesByFolder(string $token, string $folderId): array
    {
        $all    = [];
        $limit  = 100;
        $cursor = null;
        $page   = 0;

        do {
            $query = [
                'parentFolderId' => $folderId,
                'paging.limit'   => $limit,
            ];
            if ($cursor) {
                $query['paging.cursor'] = $cursor;
            }

            $resp = self::withRetry(function () use ($token, $query) {
                return Http::withHeaders([
                    'Authorization' => $token,
                    'Content-Type'  => 'application/json'
                ])->get("https://www.wixapis.com/site-media/v1/files", $query);
            });

            $page++;

            if (!$resp) {
                WixHelper::log('Migrate Media', "Files API: null response for folder {$folderId} (page {$page})", 'error');
                throw new \RuntimeException("Files API null response");
            }
            if (!$resp->ok()) {
                WixHelper::log('Migrate Media', "Files API error for folder {$folderId} (page {$page}): {$resp->status()} | {$resp->body()}", 'error');
                throw new \RuntimeException("Files API error: " . ($resp->body() ?: 'unknown'));
            }

            $files = $resp->json('files') ?? [];
            $all   = array_merge($all, $files);

            WixHelper::log('Migrate Media', "Files API ok: folder {$folderId} page {$page} count=" . count($files) . " total=" . count($all), 'debug');

            $paging = $resp->json('paging') ?? [];
            $cursor = $paging['nextCursor'] ?? null;

            if (!$cursor && count($files) < $limit) {
                break;
            }
        } while ($cursor);

        return $all;
    }

    public static function ensureFolder(string $token, string $displayName, string $parentFolderId = 'media-root'): array
    {
        static $cache = [];

        if (isset($cache[$parentFolderId][$displayName])) {
            return ['ok' => true, 'id' => $cache[$parentFolderId][$displayName]];
        }

        // Check existing
        $existing = Http::withHeaders(['Authorization' => $token])
            ->get("https://www.wixapis.com/site-media/v1/folders");

        if ($existing->ok()) {
            $folders = $existing->json('folders') ?? [];
            foreach ($folders as $f) {
                if (($f['displayName'] ?? '') === $displayName && ($f['parentFolderId'] ?? 'media-root') === $parentFolderId) {
                    $cache[$parentFolderId][$displayName] = $f['id'];
                    WixHelper::log('Migrate Media', "ensureFolder: found existing '{$displayName}' → {$f['id']}", 'debug');
                    return ['ok' => true, 'id' => $f['id']];
                }
            }
        }

        // Create
        $resp = self::withRetry(function () use ($token, $displayName, $parentFolderId) {
            return Http::withHeaders([
                'Authorization' => $token,
                'Content-Type'  => 'application/json',
            ])->post('https://www.wixapis.com/site-media/v1/folders', [
                'displayName'    => $displayName,
                'parentFolderId' => $parentFolderId,
            ]);
        });

        if ($resp && $resp->ok()) {
            $id = $resp->json('folder.id') ?? ($resp->json('folder')['id'] ?? null);
            if ($id) {
                $cache[$parentFolderId][$displayName] = $id;
                WixHelper::log('Migrate Media', "ensureFolder: created '{$displayName}' → {$id}", 'info');
                return ['ok' => true, 'id' => $id];
            }
            WixHelper::log('Migrate Media', "ensureFolder: create ok but no folder.id for '{$displayName}'", 'warn');
            return ['ok' => false, 'error' => 'No folder.id in response'];
        }

        WixHelper::log('Migrate Media', "ensureFolder: create failed for '{$displayName}': " . ($resp?->body() ?? 'Unknown error'), 'error');
        return ['ok' => false, 'error' => $resp?->body() ?? 'Unknown error'];
}

    public static function importFile(string $token, string $url, string $displayName, string $parentFolderId, ?string $mimeType = null): array
    {
        $payload = [
            'url'            => $url,
            'displayName'    => $displayName,
            'parentFolderId' => $parentFolderId,
            'private'        => false,
        ];
        if ($mimeType) {
            $payload['mimeType'] = $mimeType;
        }

        $resp = self::withRetry(function () use ($token, $payload) {
            return Http::withHeaders([
                'Authorization' => $token,
                'Content-Type'  => 'application/json',
            ])->post('https://www.wixapis.com/site-media/v1/files/import', $payload);
        });

        if ($resp && $resp->ok()) {
            $id = $resp->json('file.id') ?? ($resp->json('file')['id'] ?? null);
            if ($id) {
                // Debug-level to avoid noisy logs for bulk imports
                WixHelper::log('Migrate Media', "importFile ok: '{$payload['displayName']}' → {$id}", 'debug');
                return ['ok' => true, 'id' => $id];
            }
            WixHelper::log('Migrate Media', "importFile ok but missing file.id: '{$payload['displayName']}'", 'warn');
            return ['ok' => false, 'error' => 'No file.id in response'];
        }

        WixHelper::log('Migrate Media', "importFile failed: '{$payload['displayName']}' | " . ($resp?->status() ?? 'null') . ' | ' . ($resp?->body() ?? 'Unknown error'), 'error');
        return ['ok' => false, 'error' => $resp?->body() ?? 'Unknown error'];
    }

}
