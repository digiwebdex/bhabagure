<?php

namespace App\Support;

use App\Models\TourLandingPage;
use Carbon\CarbonImmutable;

/**
 * A staff preview of a landing page as saved, draft or not (docs/tour-landing-pages.md §6): a token for one page that
 * expires after 30 minutes, signed with the app key. It travels in the link's #hash and then in a request header or an
 * HttpOnly cookie — never in a path or query, so no server, application or analytics log records it.
 */
final class LandingPreviewToken
{
    public const MINUTES = 30;

    /** @return array{token: string, expiresAt: CarbonImmutable} */
    public static function issue(TourLandingPage $page): array
    {
        $expiresAt = CarbonImmutable::now()->addMinutes(self::MINUTES);
        $payload = "{$page->id}.{$expiresAt->getTimestamp()}";

        return ['token' => self::encode($payload).'.'.self::encode(self::sign($payload)), 'expiresAt' => $expiresAt];
    }

    /** The page the token is for, or null when it is malformed, tampered with or expired. */
    public static function pageId(?string $token): ?int
    {
        if ($token === null || strlen($token) > 200 || substr_count($token, '.') !== 1) {
            return null;
        }
        [$payload, $signature] = array_map(self::decode(...), explode('.', $token));
        if ($payload === null || $signature === null || ! hash_equals(self::sign($payload), $signature)) {
            return null;
        }
        if (preg_match('/^(\d{1,10})\.(\d{1,12})$/', $payload, $match) !== 1 || (int) $match[2] <= CarbonImmutable::now()->getTimestamp()) {
            return null;
        }

        return (int) $match[1];
    }

    private static function sign(string $payload): string
    {
        return hash_hmac('sha256', "tour-landing-preview|{$payload}", (string) config('app.key'), true);
    }

    private static function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function decode(string $value): ?string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
