<?php

namespace App\Helpers;

use Illuminate\Support\Facades\Http;

class WixDiscountRuleHelper
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

    /**
     * List ALL discount rules
     */
    public static function listAllRules(string $token, int $limit = 100): array
    {
        $all = [];
        $cursor = null;
        $pageNo = 0;

        do {
            $query = [
                'query' => [
                    'sort'         => [['fieldName' => 'name', 'order' => 'ASC']],
                    'cursorPaging' => ['limit' => $limit],
                ]
            ];
            if ($cursor) {
                $query['query']['cursorPaging']['cursor'] = $cursor;
            }

            $resp = self::withRetry(function () use ($token, $query) {
                return Http::withHeaders([
                    'Authorization' => $token,
                    'Content-Type'  => 'application/json'
                ])->post('https://www.wixapis.com/ecom/v1/discount-rules/query', $query);
            });

            $pageNo++;

            if (!$resp) {
                WixHelper::log('Migrate Discount Rules', "listAllRules: null response on page {$pageNo}", 'error');
                return ['discountRules' => $all];
            }
            if (!$resp->ok()) {
                WixHelper::log('Migrate Discount Rules', "listAllRules: HTTP {$resp->status()} on page {$pageNo} | {$resp->body()}", 'error');
                return ['discountRules' => $all];
            }

            $json  = $resp->json() ?: [];
            $page  = $json['discountRules'] ?? [];
            $all   = array_merge($all, $page);

            WixHelper::log('Migrate Discount Rules', "listAllRules page {$pageNo}: got=" . count($page) . " total=" . count($all), 'debug');

            // Detect next cursor
            $paging = $json['paging'] ?? [];
            $cursor = $paging['nextCursor'] ?? ($json['nextCursor'] ?? null);

            // Fallback: if no cursor is supplied, stop when page size < limit
            if (!$cursor && count($page) < $limit) {
                break;
            }
        } while ($cursor);

        return ['discountRules' => $all];
    }

    /**
     * Create a discount rule in Wix.
     */
    public static function createRule(string $token, array $rule): array
    {
        $payload = ['discountRule' => $rule];

        $resp = self::withRetry(function () use ($token, $payload) {
            return Http::withHeaders([
                'Authorization' => $token,
                'Content-Type'  => 'application/json'
            ])->post('https://www.wixapis.com/ecom/v1/discount-rules', $payload);
        });

        if ($resp && $resp->ok()) {
            $id = $resp->json('discountRule.id') ?? ($resp->json('discountRule')['id'] ?? null);
            if ($id) {
                WixHelper::log('Migrate Discount Rules', "createRule ok → id={$id}", 'debug');
                return ['ok' => true, 'id' => $id];
            }
            WixHelper::log('Migrate Discount Rules', "createRule ok but missing discountRule.id", 'warn');
            return ['ok' => false, 'error' => 'No discountRule.id in response'];
        }

        $status = $resp?->status() ?? null;
        $body   = $resp?->body() ?? 'Unknown error';
        WixHelper::log('Migrate Discount Rules', "createRule failed: status={$status} body={$body}", in_array($status, [400,409]) ? 'warn' : 'error');

        return ['ok' => false, 'error' => $body];
    }
}
