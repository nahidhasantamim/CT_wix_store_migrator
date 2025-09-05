<?php

namespace App\Helpers;

use Illuminate\Support\Facades\Http;

class WixMemberHelper
{
    private static function withRetry(callable $fn, int $maxAttempts = 3, int $baseSleepMs = 500)
    {
        $attempt = 0;
        do {
            $attempt++;
            try {
                $resp = $fn();
                $code = is_object($resp) && method_exists($resp, 'status') ? $resp->status() : null;
                if ($code !== null && ($code >= 200 && $code < 300)) return $resp;
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

    public static function findMemberByContactId(string $token, string $contactId): ?array
    {
        $payload = [
            'query' => [
                'filter' => ['contactId' => ['$eq' => $contactId]],
                'paging' => ['limit' => 1]
            ]
        ];
        $resp = self::withRetry(function () use ($token, $payload) {
            return Http::withHeaders([
                'Authorization' => $token, 'Content-Type' => 'application/json'
            ])->post('https://www.wixapis.com/members/v1/members/query', $payload);
        });
        if ($resp && $resp->ok() && !empty($resp->json('members'))) {
            return $resp->json('members')[0];
        }
        return null;
    }

    public static function findMemberByEmail(string $token, string $email): ?array
    {
        if (!$email) return null;
        $payload = [
            'query' => [
                'filter' => ['loginEmail' => ['$eq' => $email]],
                'paging' => ['limit' => 1]
            ]
        ];
        $resp = self::withRetry(function () use ($token, $payload) {
            return Http::withHeaders([
                'Authorization' => $token, 'Content-Type' => 'application/json'
            ])->post('https://www.wixapis.com/members/v1/members/query', $payload);
        });
        if ($resp && $resp->ok() && !empty($resp->json('members'))) {
            return $resp->json('members')[0];
        }
        return null;
    }

    public static function createMember(string $token, array $memberBody): array
    {
        $resp = self::withRetry(function () use ($token, $memberBody) {
            return Http::withHeaders([
                'Authorization' => $token, 'Content-Type' => 'application/json'
            ])->post('https://www.wixapis.com/members/v1/members', ['member' => $memberBody]);
        });
        return $resp ? ($resp->json() ?: []) : [];
    }

    public static function getMemberAbout(string $token, string $memberId): ?array
    {
        $resp = self::withRetry(function () use ($token, $memberId) {
            return Http::withHeaders([
                'Authorization' => $token, 'Content-Type' => 'application/json'
            ])->get("https://www.wixapis.com/members/v2/abouts/member/$memberId");
        });
        if ($resp && $resp->ok()) {
            return $resp->json('memberAbout') ?? null;
        }
        return null;
    }

    public static function upsertMemberAbout(string $token, string $memberId, array $about): void
    {
        $existing = self::getMemberAbout($token, $memberId);
        $payload  = ['memberAbout' => ['memberId' => $memberId, 'content' => $about['content'], 'revision' => $about['revision'] ?? '0']];
        if ($existing) {
            self::withRetry(function () use ($token, $existing, $payload) {
                return Http::withHeaders([
                    'Authorization' => $token, 'Content-Type' => 'application/json'
                ])->patch("https://www.wixapis.com/members/v2/abouts/{$existing['id']}", $payload);
            });
        } else {
            self::withRetry(function () use ($token, $payload) {
                return Http::withHeaders([
                    'Authorization' => $token, 'Content-Type' => 'application/json'
                ])->post("https://www.wixapis.com/members/v2/abouts", $payload);
            });
        }
    }

    public static function getMemberBadges(string $token, string $memberId): array
    {
        $resp = self::withRetry(function () use ($token, $memberId) {
            return Http::withHeaders([
                'Authorization' => $token, 'Content-Type' => 'application/json'
            ])->get("https://www.wixapis.com/members/v1/members/$memberId/badges");
        });
        return ($resp && $resp->ok()) ? ($resp->json()['badges'] ?? []) : [];
    }

    public static function assignBadge(string $token, string $memberId, string $badgeKey): void
    {
        self::withRetry(function () use ($token, $memberId, $badgeKey) {
            return Http::withHeaders([
                'Authorization' => $token, 'Content-Type' => 'application/json'
            ])->post("https://www.wixapis.com/members/v1/members/$memberId/badges", ['badgeKey' => $badgeKey]);
        });
    }

    public static function getFollowersOrFollowing(string $token, string $memberId, string $type = 'followers'): array
    {
        $endpoint = $type === 'followers'
            ? "https://www.wixapis.com/members/v1/members/$memberId/followers"
            : "https://www.wixapis.com/members/v1/members/$memberId/following";
        $resp = self::withRetry(function () use ($token, $endpoint) {
            return Http::withHeaders([
                'Authorization' => $token, 'Content-Type' => 'application/json'
            ])->get($endpoint);
        });
        return ($resp && $resp->ok()) ? ($resp->json()['members'] ?? []) : [];
    }

    public static function followMember(string $token, string $memberId, string $targetMemberId): void
    {
        self::withRetry(function () use ($token, $memberId, $targetMemberId) {
            return Http::withHeaders([
                'Authorization' => $token, 'Content-Type' => 'application/json'
            ])->post("https://www.wixapis.com/members/v1/members/$memberId/following", [
                'memberId' => $targetMemberId
            ]);
        });
    }

    public static function getActivityCounters(string $token, string $memberId): array
    {
        $resp = self::withRetry(function () use ($token, $memberId) {
            return Http::withHeaders([
                'Authorization' => $token, 'Content-Type' => 'application/json'
            ])->get("https://www.wixapis.com/members/v1/members/$memberId/activity-counters");
        });
        return $resp && $resp->ok() ? ($resp->json() ?: []) : [];
    }
}
