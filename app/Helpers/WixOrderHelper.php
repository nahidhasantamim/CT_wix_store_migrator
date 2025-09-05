<?php

namespace App\Helpers;

use Illuminate\Support\Facades\Http;

class WixOrderHelper
{
    /**
     * Simple retry with jitter for 429/5xx.
     */
    private static function withRetry(callable $fn, int $maxAttempts = 3, int $baseSleepMs = 500)
    {
        $attempt = 0;
        do {
            $attempt++;
            try {
                $resp = $fn();
                $code = is_object($resp) && method_exists($resp, 'status') ? $resp->status() : null;

                if ($code !== null && $code >= 200 && $code < 300) {
                    return $resp;
                }
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
     * List ALL order IDs via paging.
     * Returns ['ids' => [...]] or ['error' => '...'].
     */
    public static function listAllOrderIds(string $token, int $limit = 100): array
    {
        $ids = [];
        $offset = 0;

        do {
            $body = [
                'query' => [
                    'sort'   => '[{"dateCreated":"asc"}]',
                    'paging' => ['limit' => $limit, 'offset' => $offset],
                ]
            ];

            $resp = self::withRetry(function () use ($token, $body) {
                return Http::withHeaders([
                    'Authorization' => $token,
                    'Content-Type'  => 'application/json'
                ])->post('https://www.wixapis.com/stores/v2/orders/query', $body);
            });

            if (!$resp || !$resp->ok()) {
                WixHelper::log('Migrate Orders', 'Order IDs query failed: ' . ($resp?->body() ?? 'null'), 'error');
                return ['error' => 'Failed to fetch order IDs'];
            }

            $data = $resp->json() ?: [];
            $page = $data['orders'] ?? [];
            foreach ($page as $o) {
                if (!empty($o['id'])) $ids[] = $o['id'];
            }

            $count  = count($page);
            $offset += $count;

            if ($count > 0) {
                WixHelper::log('Migrate Orders', "Fetched {$count} order IDs (total so far: ".count($ids).")", 'debug');
            }
        } while ($count > 0);

        return ['ids' => $ids];
    }

    /**
     * Get full order with transactions + fulfillments.
     * Returns ['order'=>array, 'payments'=>[], 'fulfillments'=>[]] or null.
     */
    public static function getFullOrder(string $token, string $orderId): ?array
    {
        // Order
        $orderResp = self::withRetry(function () use ($token, $orderId) {
            return Http::withHeaders([
                'Authorization' => $token, 'Content-Type' => 'application/json'
            ])->get("https://www.wixapis.com/ecom/v1/orders/{$orderId}");
        });
        if (!$orderResp || !$orderResp->ok()) {
            WixHelper::log('Migrate Orders', "Order fetch failed for {$orderId}: " . ($orderResp?->body() ?? 'null'), 'error');
            return null;
        }
        $order = $orderResp->json('order');
        if (!$order) return null;

        // Transactions
        $txResp = self::withRetry(function () use ($token, $orderId) {
            return Http::withHeaders([
                'Authorization' => $token, 'Content-Type' => 'application/json'
            ])->get("https://www.wixapis.com/ecom/v1/payments/orders/{$orderId}");
        });
        $payments = $txResp && $txResp->ok()
            ? ($txResp->json('orderTransactions')['payments'] ?? [])
            : [];

        // Fulfillments
        $fulfillResp = self::withRetry(function () use ($token, $orderId) {
            return Http::withHeaders([
                'Authorization' => $token, 'Content-Type' => 'application/json'
            ])->get("https://www.wixapis.com/ecom/v1/fulfillments/orders/{$orderId}");
        });
        $fulfillments = $fulfillResp && $fulfillResp->ok()
            ? ($fulfillResp->json('orderWithFulfillments')['fulfillments'] ?? [])
            : [];

        return [
            'order'        => $order,
            'payments'     => $payments,
            'fulfillments' => $fulfillments,
        ];
    }

    /**
     * Create order in target.
     * Returns ['ok'=>true,'id'=>...] or ['ok'=>false,'status'=>..,'error'=>..]
     */
    public static function createOrder(string $token, array $order): array
    {
        $resp = self::withRetry(function () use ($token, $order) {
            return Http::withHeaders([
                'Authorization' => $token, 'Content-Type' => 'application/json'
            ])->post('https://www.wixapis.com/ecom/v1/orders', ['order' => $order]);
        });

        if ($resp && $resp->ok()) {
            $id = $resp->json('order.id') ?? ($resp->json('order')['id'] ?? null);
            return $id ? ['ok' => true, 'id' => $id] : ['ok' => false, 'status' => $resp->status(), 'error' => 'No order.id in response'];
        }
        return ['ok' => false, 'status' => ($resp?->status() ?? null), 'error' => $resp?->body() ?? 'Unknown error'];
    }

    /**
     * Create a fulfillment on target order.
     * Returns ['ok'=>true] or ['ok'=>false,'error'=>..]
     */
    public static function createFulfillment(string $token, string $orderId, array $fulfillment): array
    {
        $payload = [
            'fulfillment' => [
                'lineItems'    => $fulfillment['lineItems']    ?? [],
                'trackingInfo' => $fulfillment['trackingInfo'] ?? null,
                'status'       => $fulfillment['status']       ?? 'Fulfilled',
                'completed'    => $fulfillment['completed']    ?? true,
            ]
        ];
        $resp = self::withRetry(function () use ($token, $orderId, $payload) {
            return Http::withHeaders([
                'Authorization' => $token, 'Content-Type' => 'application/json'
            ])->post("https://www.wixapis.com/ecom/v1/fulfillments/orders/{$orderId}/create-fulfillment", $payload);
        });

        if ($resp && $resp->ok()) {
            return ['ok' => true];
        }
        return ['ok' => false, 'error' => $resp?->body() ?? 'Unknown error'];
    }

    /**
     * Add payments to target order.
     * $transactions is the array from the source payments list.
     * Returns ['ok'=>true] or ['ok'=>false,'error'=>..]
     */
    public static function addPayments(string $token, string $orderId, array $transactions): array
    {
        $payments = [];
        foreach ($transactions as $payment) {
            $p = [
                'amount'         => $payment['amount'] ?? ['amount' => '0.00'],
                'refundDisabled' => $payment['refundDisabled'] ?? false,
            ];
            if (!empty($payment['regularPaymentDetails'])) {
                $p['regularPaymentDetails'] = $payment['regularPaymentDetails'];
            } elseif (!empty($payment['giftcardPaymentDetails'])) {
                $p['giftcardPaymentDetails'] = $payment['giftcardPaymentDetails'];
            }
            $payments[] = $p;
        }

        $resp = self::withRetry(function () use ($token, $orderId, $payments) {
            return Http::withHeaders([
                'Authorization' => $token, 'Content-Type' => 'application/json'
            ])->post("https://www.wixapis.com/ecom/v1/payments/orders/{$orderId}/add-payment", [
                'payments' => $payments
            ]);
        });

        if ($resp && $resp->ok()) {
            return ['ok' => true];
        }
        return ['ok' => false, 'error' => $resp?->body() ?? 'Unknown error'];
    }
}
