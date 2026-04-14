<?php

namespace App\Http\Controllers;

use App\Helpers\WixHelper;
use App\Models\WixBackInStockMigration;
use App\Models\WixProductMigration;
use App\Models\WixStore;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class WixBackInStockController extends Controller
{
    public function migrateAuto(Request $request)
    {
        $request->validate([
            'from_store' => 'required|string',
            'to_store'   => 'required|string|different:from_store',
        ]);

        $fromStoreId = $request->from_store;
        $toStoreId   = $request->to_store;

        WixHelper::log('BIS IMPORT', "Auto migrate {$fromStoreId} → {$toStoreId}", 'info');

        $fromToken = WixHelper::getAccessToken($fromStoreId);
        $toToken   = WixHelper::getAccessToken($toStoreId);

        if (!$fromToken || !$toToken) {
            return back()->with('error', 'Missing Wix token.');
        }

        $rows = $this->fetchBackInStock($fromToken);
        if (!is_array($rows)) {
            return back()->with('error', 'Failed to fetch BIS data.');
        }

        // Oldest-first so the destination store ends up in the same chronological order.
        $createdAtMillis = function (array $item): int {
            foreach (['createdDate', '_createdDate', 'dateCreated', 'createdAt'] as $k) {
                if (!empty($item[$k])) {
                    $v = $item[$k];
                    if (is_numeric($v)) return (int)$v;
                    if (is_string($v)) { $ts = strtotime($v); if ($ts !== false) return $ts * 1000; }
                }
            }
            return PHP_INT_MAX;
        };
        usort($rows, fn($a, $b) => $createdAtMillis($a) <=> $createdAtMillis($b));

        $created  = 0;
        $existing = 0;
        $skipped  = 0;
        $failed   = 0;

        foreach ($rows as $row) {
            $payload = $this->buildCreatePayload(
                $row,
                $toToken,
                $fromStoreId,
                $toStoreId
            );

            if (!$payload) {
                $skipped++;
                continue;
            }

            $result = $this->createBackInStock($toToken, $payload);
            $http   = $result['http_status'];
            $body   = $result['body'] ?? [];

            $code = data_get($body, 'details.applicationError.code');
            $auth = data_get($body, 'details.failed-client.options.authority');

            // REAL CREATE
            if ($http === 200 || $http === 201) {
                $created++;
                continue;
            }

            // ALREADY EXISTS
            if ($code === 'BACK_IN_STOCK_NOTIFICATION_REQUEST_ALREADY_EXISTS') {
                $existing++;
                WixHelper::log('BIS IMPORT', 'Already exists – no action needed', 'info');
                continue;
            }

            // CONTACT SERVICE FAILURE => SKIP
            if (
                $http === 403 &&
                $auth === 'com.wixpress.contacts.contacts-allocator-proxy'
            ) {
                $skipped++;
                WixHelper::log('BIS IMPORT', 'Contacts allocator error – skipped', 'warn');
                continue;
            }

            // REAL FAILURE
            $failed++;
            WixHelper::log('BIS IMPORT', 'Create failed', 'error');
        }

        return back()->with(
            'success',
            "Created={$created}, Existing={$existing}, Skipped={$skipped}, Failed={$failed}"
        );
    }

    public function export(WixStore $store, Request $request)
    {
        $storeId = $store->instance_id;
        $token   = WixHelper::getAccessToken($storeId);

        if (!$token) {
            return back()->with('error', 'Access denied.');
        }

        $sortOrder = strtoupper((string) $request->input('sort_order', 'ASC'));
        if (!in_array($sortOrder, ['ASC', 'DESC'], true)) {
            $sortOrder = 'ASC';
        }

        $limitRaw = $request->input('limit');
        $limit    = (is_numeric($limitRaw) && (int) $limitRaw > 0) ? (int) $limitRaw : null;

        WixHelper::log('BIS EXPORT', "Start: store={$storeId} sort={$sortOrder} limit=".($limit ?: 'all'), 'info');

        // When a limit is set, ask Wix to do the sort+paging server-side.
        // Otherwise fetch all rows and sort client-side below.
        $queryOptions = [];
        if ($limit !== null) {
            $queryOptions['limit']      = $limit;
            $queryOptions['sort_order'] = $sortOrder;
        }

        $rows = $this->fetchBackInStock($token, $queryOptions);
        if (!is_array($rows)) {
            return back()->with('error', 'Failed to fetch BIS data.');
        }

        // Output is ALWAYS oldest-first regardless of how we fetched, so imports
        // replay in the same chronological order as the source store.
        $createdAtMillis = function (array $item): int {
            foreach (['createdDate', '_createdDate', 'dateCreated', 'createdAt'] as $k) {
                if (!empty($item[$k])) {
                    $v = $item[$k];
                    if (is_numeric($v)) return (int)$v;
                    if (is_string($v)) { $ts = strtotime($v); if ($ts !== false) return $ts * 1000; }
                }
            }
            return PHP_INT_MAX;
        };
        usort($rows, fn($a, $b) => $createdAtMillis($a) <=> $createdAtMillis($b));

        WixHelper::log('BIS EXPORT', "Exporting ".count($rows)." row(s) (sort={$sortOrder}, limit=".($limit ?: 'all').")", 'info');

        foreach ($rows as &$row) {
            $productId = data_get($row, 'catalogReference.catalogItemId');
            if (!$productId) {
                continue;
            }

            $product = $this->fetchProductReader($token, $productId);
            if (!$product) {
                continue;
            }

            $row['_product'] = [
                'name'  => data_get($product, 'name'),
                'price' => data_get($product, 'priceData.price'),
                'url'   => data_get($product, 'productPageUrl'),
                'image' => [
                    'url' => data_get($product, 'media.mainMedia.image.url'),
                    'id'  => data_get($product, 'media.mainMedia.id'),
                ],
            ];
        }

        return response()->streamDownload(
            fn () => print json_encode([
                'meta' => [
                    'from_store_id' => $storeId,
                    'generated_at'  => now()->toIso8601String(),
                ],
                'back_in_stock_requests' => $rows,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            'back_in_stock_requests.json',
            ['Content-Type' => 'application/json']
        );
    }

    public function import(Request $request, WixStore $store)
    {
        $toStoreId = $store->instance_id;
        $token     = WixHelper::getAccessToken($toStoreId);

        if (!$token) {
            return back()->with('error', 'Token missing.');
        }

        $decoded = json_decode(
            file_get_contents($request->file('back_in_stock_json')->getRealPath()),
            true
        );

        $rows = $decoded['back_in_stock_requests'] ?? null;
        if (!is_array($rows)) {
            return back()->with('error', 'Invalid JSON.');
        }

        // Force oldest-first regardless of the file's own ordering so the destination
        // store ends up in the same chronological sequence as the source.
        $createdAtMillis = function (array $item): int {
            foreach (['createdDate', '_createdDate', 'dateCreated', 'createdAt'] as $k) {
                if (!empty($item[$k])) {
                    $v = $item[$k];
                    if (is_numeric($v)) return (int)$v;
                    if (is_string($v)) { $ts = strtotime($v); if ($ts !== false) return $ts * 1000; }
                }
            }
            return PHP_INT_MAX;
        };
        usort($rows, fn($a, $b) => $createdAtMillis($a) <=> $createdAtMillis($b));

        WixHelper::log('BIS IMPORT', "Manual import start → {$toStoreId} (".count($rows)." row(s), sorted oldest-first)", 'info');

        $userId = Auth::id() ?: 1;

        $created       = 0;
        $existing      = 0;
        $skipped       = 0;
        $failed        = 0;
        $alreadyDone   = 0;

        foreach ($rows as $row) {
            $sourceRequestId = data_get($row, 'id')
                ?? data_get($row, 'request.id')
                ?? data_get($row, 'requestId');
            $sourceProductId = data_get($row, 'catalogReference.catalogItemId');
            $sourceVariantId = data_get($row, 'catalogReference.options.variantId');
            $sourceEmail     = data_get($row, 'email');

            // Skip rows already successfully migrated to this destination.
            if ($sourceRequestId) {
                $existingRow = WixBackInStockMigration::query()
                    ->where('user_id', $userId)
                    ->where('to_store_id', $toStoreId)
                    ->where('source_request_id', $sourceRequestId)
                    ->where('status', 'success')
                    ->first();

                if ($existingRow) {
                    $alreadyDone++;
                    WixHelper::log('BIS IMPORT', "Skip (already migrated successfully): source_request_id={$sourceRequestId}", 'debug');
                    continue;
                }
            }

            $migration = WixProductMigration::query()
                ->whereNotNull('destination_product_id')
                ->where('to_store_id', $toStoreId)
                ->where('source_product_id', $sourceProductId)
                ->where('status', 'success')
                ->first();

            if (!$migration) {
                WixHelper::log('BIS IMPORT', "No migration row for {$sourceProductId}", 'warn');
                $skipped++;
                $this->upsertBisMigration($userId, null, $toStoreId, $sourceRequestId, $sourceEmail, $sourceProductId, $sourceVariantId, null, null, 'skipped', 'No product migration row');
                continue;
            }

            $fromStoreId = $migration->from_store_id;

            $payload = $this->buildCreatePayload(
                $row,
                $token,
                $fromStoreId,
                $toStoreId
            );

            if (!$payload) {
                $skipped++;
                $this->upsertBisMigration($userId, $fromStoreId, $toStoreId, $sourceRequestId, $sourceEmail, $sourceProductId, $sourceVariantId, $migration->destination_product_id, null, 'skipped', 'Could not build payload');
                continue;
            }

            $result = $this->createBackInStock($token, $payload);
            $http   = $result['http_status'];
            $body   = $result['body'] ?? [];

            $code = data_get($body, 'details.applicationError.code');
            $auth = data_get($body, 'details.failed-client.options.authority');

            // REAL CREATE
            if ($http === 200 || $http === 201) {
                $created++;
                $destinationRequestId = data_get($body, 'request.id') ?? data_get($body, 'id');
                $this->upsertBisMigration($userId, $fromStoreId, $toStoreId, $sourceRequestId, $sourceEmail, $sourceProductId, $sourceVariantId, $migration->destination_product_id, $destinationRequestId, 'success', null);
                continue;
            }

            // ALREADY EXISTS
            if ($code === 'BACK_IN_STOCK_NOTIFICATION_REQUEST_ALREADY_EXISTS') {
                $existing++;
                WixHelper::log('BIS IMPORT', 'Already exists – no action needed', 'info');
                $this->upsertBisMigration($userId, $fromStoreId, $toStoreId, $sourceRequestId, $sourceEmail, $sourceProductId, $sourceVariantId, $migration->destination_product_id, null, 'success', 'Already existed on destination');
                continue;
            }

            // CONTACT SERVICE FAILURE
            if (
                $http === 403 &&
                $auth === 'com.wixpress.contacts.contacts-allocator-proxy'
            ) {
                $skipped++;
                WixHelper::log('BIS IMPORT', 'Contacts allocator error – skipped', 'warn');
                $this->upsertBisMigration($userId, $fromStoreId, $toStoreId, $sourceRequestId, $sourceEmail, $sourceProductId, $sourceVariantId, $migration->destination_product_id, null, 'skipped', 'Contacts allocator error');
                continue;
            }

            // REAL FAILURE
            $failed++;
            WixHelper::log('BIS IMPORT', 'Create failed: ' . json_encode($body), 'error');
            $this->upsertBisMigration($userId, $fromStoreId, $toStoreId, $sourceRequestId, $sourceEmail, $sourceProductId, $sourceVariantId, $migration->destination_product_id, null, 'failed', json_encode($body));
        }

        return back()->with(
            'success',
            "Created={$created}, Existing={$existing}, AlreadyDone={$alreadyDone}, Skipped={$skipped}, Failed={$failed}"
        );
    }

    private function upsertBisMigration(
        int $userId,
        ?string $fromStoreId,
        string $toStoreId,
        ?string $sourceRequestId,
        ?string $sourceEmail,
        ?string $sourceProductId,
        ?string $sourceVariantId,
        ?string $destinationProductId,
        ?string $destinationRequestId,
        string $status,
        ?string $errorMessage
    ): void {
        if (!$sourceRequestId) {
            return;
        }

        try {
            WixBackInStockMigration::updateOrCreate(
                [
                    'user_id'           => $userId,
                    'to_store_id'       => $toStoreId,
                    'source_request_id' => $sourceRequestId,
                ],
                [
                    'from_store_id'          => $fromStoreId,
                    'source_email'           => $sourceEmail,
                    'source_product_id'      => $sourceProductId,
                    'source_variant_id'      => $sourceVariantId,
                    'destination_product_id' => $destinationProductId,
                    'destination_request_id' => $destinationRequestId,
                    'status'                 => $status,
                    'error_message'          => $errorMessage,
                ]
            );
        } catch (\Throwable $e) {
            WixHelper::log('BIS IMPORT', "DB upsert failed for source_request_id={$sourceRequestId}: ".$e->getMessage(), 'error');
        }
    }

    private function fetchBackInStock(string $token, array $queryOptions = []): ?array
    {
        $endpoint = 'https://www.wixapis.com/back-in-stock-service/v1/back-in-stock-notification-requests/query';

        $authHeaders = [
            'Authorization' => str_starts_with($token, 'Bearer') ? $token : "Bearer {$token}",
            'Content-Type'  => 'application/json',
        ];

        // If a limit is specified, use a single server-side query with sort + paging
        // (matches the Wix docs example: sort by createdDate ASC/DESC, paging.limit).
        if (!empty($queryOptions['limit'])) {
            $limit = max(1, min(100, (int) $queryOptions['limit']));
            $order = strtoupper((string) ($queryOptions['sort_order'] ?? 'ASC')) === 'DESC' ? 'DESC' : 'ASC';

            $body = [
                'query' => [
                    'sort' => [
                        ['fieldName' => 'createdDate', 'order' => $order],
                    ],
                    'paging' => [
                        'limit' => $limit,
                    ],
                ],
            ];

            $resp = Http::withHeaders($authHeaders)->post($endpoint, $body);

            if (!$resp->ok()) {
                WixHelper::log('BIS', 'Query failed: status='.$resp->status().' body='.$resp->body(), 'error');
                return null;
            }

            $json = $resp->json() ?: [];
            $rows = $json['requests'] ?? [];
            if (!is_array($rows)) $rows = [];

            WixHelper::log('BIS', "Server-side query returned ".count($rows)." row(s) (sort={$order}, limit={$limit})", 'debug');
            return $rows;
        }

        // No limit → paginate everything via offset-based `paging` (BIS endpoint
        // uses this format per Wix docs; cursorPaging returns only page 1 here).
        $pageLimit = 100;
        $all       = [];
        $offset    = 0;
        $pages     = 0;
        $maxPages  = 1000; // hard safety ceiling (100k rows)

        do {
            $body = [
                'query' => [
                    'sort' => [
                        ['fieldName' => 'createdDate', 'order' => 'ASC'],
                    ],
                    'paging' => [
                        'limit'  => $pageLimit,
                        'offset' => $offset,
                    ],
                ],
            ];

            $resp = Http::withHeaders($authHeaders)->post($endpoint, $body);

            if (!$resp->ok()) {
                WixHelper::log('BIS', 'Query failed: status='.$resp->status().' body='.$resp->body(), 'error');
                return null;
            }

            $json      = $resp->json() ?: [];
            $batch     = $json['requests'] ?? [];
            if (!is_array($batch)) $batch = [];
            $batchSize = count($batch);

            $all = array_merge($all, $batch);

            $offset += $batchSize;
            $pages++;

            if ($batchSize < $pageLimit) break;
        } while ($pages < $maxPages);

        WixHelper::log('BIS', "Paginated fetch complete: pages={$pages} total=".count($all), 'debug');

        return $all;
    }

    private function getPublishedSiteBaseUrl(string $token, string $storeId): ?string
    {
        $cacheKey = "wix:published-site-url:{$storeId}";

        return Cache::remember($cacheKey, now()->addHours(6), function () use ($token) {
            $resp = Http::withHeaders([
                'Authorization' => str_starts_with($token, 'Bearer') ? $token : "Bearer {$token}",
                'Content-Type'  => 'application/json',
            ])->get('https://www.wixapis.com/urls-server/v2/published-site-urls');

            if (!$resp->ok()) {
                return null;
            }

            $urls = $resp->json('urls', []);
            foreach ($urls as $u) {
                if (!empty($u['primary']) && !empty($u['url'])) {
                    return rtrim((string) $u['url'], '/');
                }
            }

            foreach ($urls as $u) {
                if (!empty($u['url'])) {
                    return rtrim((string) $u['url'], '/');
                }
            }

            return null;
        });
    }

    private function buildCreatePayload(
        array $src,
        string $toToken,
        string $fromStoreId,
        string $toStoreId
    ): ?array {
        $email = data_get($src, 'email');
        $cat   = data_get($src, 'catalogReference');

        if (!$email || !is_array($cat)) {
            WixHelper::log('BIS IMPORT', 'Missing email/catalogReference', 'warn');
            return null;
        }

        $sourceProductId = data_get($cat, 'catalogItemId');
        $appId           = data_get($cat, 'appId');

        if (!$sourceProductId || !$appId) {
            WixHelper::log('BIS IMPORT', 'Missing catalogItemId/appId', 'warn');
            return null;
        }

        $migration = WixProductMigration::query()
            ->where('from_store_id', $fromStoreId)
            ->where('to_store_id', $toStoreId)
            ->where('source_product_id', $sourceProductId)
            ->whereNotNull('destination_product_id')
            ->whereIn('status', ['success', 'completed'])
            ->first();

        if (!$migration) {
            WixHelper::log('BIS IMPORT', "No migration row for source_product_id={$sourceProductId}", 'warn');
            return null;
        }

        $destinationProductId = $migration->destination_product_id;

        $srcOptions  = data_get($cat, 'options', []);
        $variantId   = is_array($srcOptions) ? ($srcOptions['variantId'] ?? null) : null;
        $zeroVariant = '00000000-0000-0000-0000-000000000000';

        $optionsForCreate = new \stdClass();
        if ($variantId && $variantId !== $zeroVariant) {
            WixHelper::log('BIS IMPORT', "Variant mapping not supported (src variantId={$variantId})", 'warn');
            $optionsForCreate = new \stdClass();
        }

        $itemDetails = null;
        $itemUrl     = null;

        $v3 = $this->fetchProductV3($toToken, $destinationProductId);

        $extractPrice = function (?array $p): ?string {
            if (!$p) return null;
            $candidates = [
                data_get($p, 'priceData.price'),
                data_get($p, 'priceData.formatted.price'),
                data_get($p, 'price.price'),
                data_get($p, 'price.amount'),
                data_get($p, 'price'),
            ];
            foreach ($candidates as $v) {
                if ($v === null || $v === '') continue;
                if (is_numeric($v)) return number_format((float) $v, 2, '.', '');
                if (is_string($v)) return $v;
            }
            return null;
        };

        $extractImage = function (?array $p): ?array {
            if (!$p) return null;

            $url = data_get($p, 'media.mainMedia.image.url')
                ?? data_get($p, 'media.mainMedia.thumbnail.url')
                ?? data_get($p, 'media.main.image.url')
                ?? data_get($p, 'media.main.image')
                ?? data_get($p, 'media.mainMedia.url');

            $id = data_get($p, 'media.mainMedia.id')
                ?? data_get($p, 'media.main.id')
                ?? data_get($p, 'media.mainMedia.image.id');

            if (!$url && !$id) return null;

            return [
                'url' => $url,
                'id'  => $id,
            ];
        };

        if ($v3) {
            $name  = data_get($v3, 'name');
            $price = $extractPrice($v3);

            if ($name || $price) {
                $itemDetails = [
                    'name'  => $name ?: 'Product',
                    'price' => $price ?: '0.00',
                ];

                if ($img = $extractImage($v3)) {
                    $itemDetails['image'] = $img;
                }
            }

            $siteBaseUrl = $this->getPublishedSiteBaseUrl($toToken, $toStoreId);
            $slug        = data_get($v3, 'slug');

            if ($siteBaseUrl && $slug) {
                $itemUrl = "{$siteBaseUrl}/product-page/{$slug}";
            }
        }

        if (!$itemDetails && !empty($src['_product']) && is_array($src['_product'])) {
            $snap = $src['_product'];

            $snapPrice = $snap['price'] ?? null;
            if (is_numeric($snapPrice)) {
                $snapPrice = number_format((float) $snapPrice, 2, '.', '');
            } elseif (!is_string($snapPrice)) {
                $snapPrice = null;
            }

            $itemDetails = [
                'name'  => $snap['name'] ?? 'Product',
                'price' => $snapPrice ?: '0.00',
            ];

            if (!empty($snap['image']) && is_array($snap['image'])) {
                $itemDetails['image'] = [
                    'url' => $snap['image']['url'] ?? null,
                    'id'  => $snap['image']['id'] ?? null,
                ];
            }

            $snapUrl = $snap['url'] ?? null;
            if (is_array($snapUrl)) {
                $base = $snapUrl['base'] ?? '';
                $path = $snapUrl['path'] ?? '';
                $itemUrl = $base && $path ? rtrim($base, '/') . '/' . ltrim($path, '/') : null;
            } elseif (is_string($snapUrl)) {
                $itemUrl = $snapUrl;
            }
        }

        if (!$itemDetails) {
            WixHelper::log('BIS IMPORT', "No product details available for source_product_id={$sourceProductId}", 'warn');
            return null;
        }

        $payload = [
            'itemDetails' => $itemDetails,
            'request' => [
                'email' => $email,
                'catalogReference' => [
                    'catalogItemId' => $destinationProductId,
                    'appId'         => $appId,
                    'options'       => $optionsForCreate,
                ],
            ],
        ];

        if ($itemUrl) {
            $payload['request']['itemUrl'] = $itemUrl;
        }

        WixHelper::log('BIS IMPORT', 'Final BIS payload: ' . json_encode($payload), 'debug');
        return $payload;
    }

    private function fetchProductReader(string $token, string $productId): ?array
    {
        $resp = Http::withHeaders([
            'Authorization' => str_starts_with($token, 'Bearer') ? $token : "Bearer {$token}",
            'Content-Type'  => 'application/json',
        ])->get("https://www.wixapis.com/stores-reader/v1/products/{$productId}");

        return $resp->ok() ? ($resp->json()['product'] ?? null) : null;
    }

    private function fetchProductV3(string $token, string $productId): ?array
    {
        $resp = Http::withHeaders([
            'Authorization' => str_starts_with($token, 'Bearer') ? $token : "Bearer {$token}",
            'Content-Type'  => 'application/json',
        ])->get("https://www.wixapis.com/stores/v3/products/{$productId}");

        return $resp->ok() ? ($resp->json()['product'] ?? null) : null;
    }

    private function createBackInStock(string $token, array $payload): array
    {
        $resp = Http::withHeaders([
                'Authorization' => str_starts_with($token, 'Bearer') ? $token : "Bearer {$token}",
                'Content-Type'  => 'application/json',
            ])
            ->timeout(20)
            ->retry(3, 400, function ($e) {
                return $e instanceof ConnectionException;
            }, throw: false)
            ->post(
                'https://www.wixapis.com/back-in-stock-service/v1/back-in-stock-notification-requests',
                $payload
            );

        $body = $resp->json();

        // WixHelper::log(
        //     'BIS IMPORT',
        //     'Create response status=' . $resp->status() . ' body=' . json_encode($body),
        //     $resp->ok() ? 'debug' : 'error'
        // );

        // =============Request ID Block============
        // $requestId = data_get($body, 'request.id')
        //     ?? data_get($body, 'requestId')
        //     ?? data_get($body, 'id');
        //
        // WixHelper::log(
        //     'BIS IMPORT',
        //     'Create response status=' . $resp->status()
        //         . ' request_id=' . ($requestId ?: 'n/a')
        //         . ' body=' . json_encode($body),
        //     $resp->ok() ? 'debug' : 'error'
        // );
        // =============Request ID Block============

        // =============Request ID Block============
        $requestId = $resp->header('x-wix-request-id')
            ?? $resp->header('X-Wix-Request-Id')
            ?? $resp->header('x-request-id')
            ?? $resp->header('X-Request-Id')
            ?? data_get($body, 'details.wix-response-context-bin')
            ?? data_get($body, 'request.id')
            ?? data_get($body, 'requestId')
            ?? data_get($body, 'id');

        WixHelper::log(
            'BIS IMPORT',
            'Create response status=' . $resp->status()
                . ' request_id=' . ($requestId ?: 'n/a')
                . ' body=' . json_encode($body),
            $resp->ok() ? 'debug' : 'error'
        );
        // =============Request ID Block============

        // return [
        //     'http_status' => $resp->status(),
        //     'ok'          => $resp->ok(),
        //     'body'        => $body,
        // ];

        // =============Request ID Block============
        // return [
        //     'http_status' => $resp->status(),
        //     'ok'          => $resp->ok(),
        //     'body'        => $body,
        //     'request_id'  => $requestId,
        // ];
        // =============Request ID Block============

        // =============Request ID Block============
        return [
            'http_status' => $resp->status(),
            'ok'          => $resp->ok(),
            'body'        => $body,
            'request_id'  => $requestId,
        ];
        // =============Request ID Block============
    }

    public function deleteFromJson(Request $request, WixStore $store)
    {
        $toStoreId = $store->instance_id;
        $token     = WixHelper::getAccessToken($toStoreId);

        if (!$token) {
            return back()->with('error', 'Token missing.');
        }

        if (!$request->hasFile('back_in_stock_json')) {
            return back()->with('error', 'No file uploaded.');
        }

        $decoded = json_decode(
            file_get_contents($request->file('back_in_stock_json')->getRealPath()),
            true
        );

        $rows = data_get($decoded, 'back_in_stock_requests');
        if (!is_array($rows)) {
            return back()->with('error', 'Invalid JSON.');
        }

        WixHelper::log('BIS DELETE', "Manual delete start → {$toStoreId} (".count($rows)." row(s))", 'info');

        $deleted  = 0;
        $notFound = 0;
        $skipped  = 0;
        $failed   = 0;

        foreach ($rows as $row) {
            $id = data_get($row, 'id') ?? data_get($row, 'request.id') ?? data_get($row, 'requestId');

            if (!$id) {
                $skipped++;
                WixHelper::log('BIS DELETE', 'Row missing id, skipped', 'warn');
                continue;
            }

            $result = $this->deleteBackInStock($token, $id);
            $http   = $result['http_status'];

            if ($http === 200 || $http === 204) {
                $deleted++;
                continue;
            }

            if ($http === 404) {
                $notFound++;
                WixHelper::log('BIS DELETE', "Not found on target (already deleted?): {$id}", 'info');
                continue;
            }

            $failed++;
            WixHelper::log('BIS DELETE', "Delete failed for {$id}: status={$http} body=".json_encode($result['body'] ?? []), 'error');
        }

        WixHelper::log('BIS DELETE', "Done. deleted={$deleted}, notFound={$notFound}, skipped={$skipped}, failed={$failed}", $failed ? 'warn' : 'success');

        return back()->with(
            $failed ? 'warning' : 'success',
            "Deleted={$deleted}, NotFound={$notFound}, Skipped={$skipped}, Failed={$failed}"
        );
    }

    private function deleteBackInStock(string $token, string $id): array
    {
        $resp = Http::withHeaders([
                'Authorization' => str_starts_with($token, 'Bearer') ? $token : "Bearer {$token}",
                'Content-Type'  => 'application/json',
            ])
            ->timeout(20)
            ->retry(3, 400, function ($e) {
                return $e instanceof ConnectionException;
            }, throw: false)
            ->delete(
                "https://www.wixapis.com/back-in-stock-service/v1/back-in-stock-notification-requests/{$id}"
            );

        return [
            'http_status' => $resp->status(),
            'ok'          => $resp->ok(),
            'body'        => $resp->json(),
        ];
    }
}