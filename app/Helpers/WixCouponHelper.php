<?php

namespace App\Helpers;

use Illuminate\Support\Facades\Http;

class WixCouponHelper
{
    private static function withRetry(callable $fn, int $maxAttempts = 3, int $baseSleepMs = 500)
    {
        $attempt = 0;
        do {
            $attempt++;
            try {
                $resp = $fn();
                $code = is_object($resp) && method_exists($resp, 'status') ? $resp->status() : null;

                if ($code !== null && $code >= 200 && $code < 300) return $resp;

                if ($code !== null && (in_array($code, [429]) || ($code >= 500 && $code < 600))) {
                    // small jitter
                    usleep(($baseSleepMs + random_int(0, 400)) * 1000);
                    continue;
                }
                return $resp; // non-retriable
            } catch (\Throwable $e) {
                if ($attempt >= $maxAttempts) throw $e;
                usleep(($baseSleepMs + random_int(0, 400)) * 1000);
            }
        } while ($attempt < $maxAttempts);

        return null;
    }

    /** Pull all coupons with cursor paging (fallback to basic query). */
    public static function listAllCoupons(string $token, int $limit = 100): array
    {
        $all = [];
        $cursor = null;

        do {
            $payload = ['query' => ['cursorPaging' => ['limit' => $limit]]];
            if ($cursor) $payload['query']['cursorPaging']['cursor'] = $cursor;

            $resp = self::withRetry(function () use ($token, $payload) {
                return Http::withHeaders([
                    'Authorization' => $token,
                    'Content-Type'  => 'application/json'
                ])->post('https://www.wixapis.com/stores/v2/coupons/query', $payload);
            });

            if (!$resp) {
                WixHelper::log('Migrate Coupons', 'listAllCoupons: null response', 'error');
                return ['coupons' => $all];
            }
            if (!$resp->ok()) {
                WixHelper::log('Migrate Coupons', "listAllCoupons: HTTP {$resp->status()} | {$resp->body()}", 'error');
                if (empty($all)) {
                    // fallback single-shot
                    $fallback = self::withRetry(function () use ($token) {
                        return Http::withHeaders([
                            'Authorization' => $token,
                            'Content-Type'  => 'application/json'
                        ])->post('https://www.wixapis.com/stores/v2/coupons/query', ['query' => new \stdClass()]);
                    });
                    if ($fallback && $fallback->ok()) {
                        $got = count($fallback->json('coupons') ?? []);
                        WixHelper::log('Migrate Coupons', "listAllCoupons fallback ok, count={$got}", 'warn');
                        return ['coupons' => ($fallback->json('coupons') ?? [])];
                    }
                }
                return ['coupons' => $all];
            }

            $json   = $resp->json() ?: [];
            $page   = $json['coupons'] ?? [];
            $all    = array_merge($all, $page);
            $cursor = $json['paging']['nextCursor'] ?? ($json['nextCursor'] ?? null);

            WixHelper::log('Migrate Coupons', "listAllCoupons page: got=" . count($page) . " total=" . count($all), 'debug');

            if (!$cursor || count($page) < $limit) break;
        } while ($cursor);

        return ['coupons' => $all];
    }

    /** Find coupon by code in target (dedupe). */
    public static function findByCode(string $token, string $code): ?array
    {
        $payload = [
            'query' => [
                'filter' => ['specification.code' => ['$eq' => $code]],
                'cursorPaging' => ['limit' => 1],
            ]
        ];

        $resp = self::withRetry(function () use ($token, $payload) {
            return Http::withHeaders([
                'Authorization' => $token,
                'Content-Type'  => 'application/json'
            ])->post('https://www.wixapis.com/stores/v2/coupons/query', $payload);
        });

        if ($resp && $resp->ok()) {
            $list = $resp->json('coupons') ?? [];
            $hit  = $list[0] ?? null;
            if ($hit) {
                WixHelper::log('Migrate Coupons', "findByCode '{$code}': found id=" . ($hit['id'] ?? 'n/a'), 'debug');
            } else {
                WixHelper::log('Migrate Coupons', "findByCode '{$code}': not found", 'debug');
            }
            return $hit;
        }

        WixHelper::log('Migrate Coupons', "findByCode '{$code}': HTTP " . ($resp?->status() ?? 'null'), 'warn');
        return null;
    }

    /** Create a coupon. Returns ['ok'=>true,'id'=>...] or ['ok'=>false,'status'=>..,'error'=>..] */
    public static function createCoupon(string $token, array $spec): array
    {
        $payload = ['specification' => $spec];

        $resp = self::withRetry(function () use ($token, $payload) {
            return Http::withHeaders([
                'Authorization' => $token,
                'Content-Type'  => 'application/json'
            ])->post('https://www.wixapis.com/stores/v2/coupons', $payload);
        });

        if ($resp && $resp->ok()) {
            $id = $resp->json('id');
            if ($id) {
                WixHelper::log('Migrate Coupons', "createCoupon ok → id={$id}", 'debug');
                return ['ok' => true, 'id' => $id];
            }
            WixHelper::log('Migrate Coupons', "createCoupon ok but missing id", 'warn');
            return ['ok' => false, 'status' => $resp->status(), 'error' => 'No id in response'];
        }

        $status = $resp?->status() ?? null;
        $body   = $resp?->body() ?? 'Unknown error';
        WixHelper::log('Migrate Coupons', "createCoupon failed: status={$status} body={$body}", in_array($status, [400,409]) ? 'warn' : 'error');

        return ['ok' => false, 'status' => $status, 'error' => $body];
    }

    /** Normalize + validate required bits on specification. Ensures startTime (ISO-8601). */
    public static function normalizeSpec(array $spec): array
    {
        if (empty($spec['startTime'])) {
            $spec['startTime'] = gmdate('c'); // ISO-8601 UTC
            WixHelper::log('Migrate Coupons', "normalizeSpec: startTime missing → set to now", 'debug');
        }
        return $spec;
    }

    /**
     * Map scope entity IDs from source to target using your migration maps.
     * Supports 'group' (single) and 'groups' (array of groups) shapes.
     * If an entity can't be mapped, returns ['ok'=>false,'reason'=>...] to allow skipping.
     */
    public static function mapScopeEntities(array $spec, array $collectionMap, array $productMap): array
    {
        if (empty($spec['scope'])) {
            return ['ok' => true, 'spec' => $spec];
        }

        $mapOne = function (&$group) use ($collectionMap, $productMap) {
            if (!is_array($group)) return true;
            if (empty($group['entityId'])) return true; // some scopes don't carry entityId

            $srcId = $group['entityId'];

            if (isset($collectionMap[$srcId])) {
                $group['entityId'] = $collectionMap[$srcId];
                return true;
            }
            if (isset($productMap[$srcId])) {
                $group['entityId'] = $productMap[$srcId];
                return true;
            }
            return false; // not mappable
        };

        // single group
        if (!empty($spec['scope']['group'])) {
            $grp =& $spec['scope']['group'];
            if (!$mapOne($grp)) {
                WixHelper::log('Migrate Coupons', 'mapScopeEntities: unmapped scope entityId (group)', 'warn');
                return ['ok' => false, 'reason' => 'unmapped scope entityId (group)'];
            }
        }

        // multiple groups
        if (!empty($spec['scope']['groups']) && is_array($spec['scope']['groups'])) {
            $kept = 0;
            foreach ($spec['scope']['groups'] as $idx => &$grp) {
                if (!$mapOne($grp)) {
                    unset($spec['scope']['groups'][$idx]);
                } else {
                    $kept++;
                }
            }
            $spec['scope']['groups'] = array_values($spec['scope']['groups']);
            if ($kept === 0) {
                WixHelper::log('Migrate Coupons', 'mapScopeEntities: all scope groups unmapped', 'warn');
                return ['ok' => false, 'reason' => 'all scope groups unmapped'];
            }
        }

        return ['ok' => true, 'spec' => $spec];
    }
}
