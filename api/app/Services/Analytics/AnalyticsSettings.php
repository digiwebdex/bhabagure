<?php

namespace App\Services\Analytics;

use App\Models\SiteSetting;

/**
 * Visitor analytics settings (docs/visitor-analytics.md), Admin → Visitor analytics → Settings, super admin only.
 * Stored as the `analytics` site setting; until saved, the defaults below.
 */
final class AnalyticsSettings
{
    public const SETTING = 'analytics';

    /** @return array{enabled: bool, retentionMonths: int, activeMinutes: int, sessionMinutes: int, excludedPaths: list<string>} */
    public static function defaults(): array
    {
        return [
            'enabled' => true,
            // The client's choice (2026-10-24): a year and a month, so a month can be compared with the same one last year.
            'retentionMonths' => 13,
            // "Live": seen in the last five minutes (the website beats every 30 seconds while the tab is visible).
            'activeMinutes' => 5,
            // A tab idle this long starts a new session.
            'sessionMinutes' => 30,
            // Path prefixes never counted (the portal and the admin are never tracked anyway).
            'excludedPaths' => [],
        ];
    }

    /** @return array{enabled: bool, retentionMonths: int, activeMinutes: int, sessionMinutes: int, excludedPaths: list<string>} */
    public static function get(): array
    {
        $saved = SiteSetting::get(self::SETTING);

        return is_array($saved) ? self::normalize($saved) : self::defaults();
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array{enabled: bool, retentionMonths: int, activeMinutes: int, sessionMinutes: int, excludedPaths: list<string>}
     */
    public static function normalize(array $value): array
    {
        $defaults = self::defaults();
        $paths = array_values(array_unique(array_filter(array_map(
            fn ($path) => is_string($path) && str_starts_with(trim($path), '/') ? mb_substr(trim($path), 0, 120) : null,
            is_array($value['excludedPaths'] ?? null) ? $value['excludedPaths'] : [],
        ))));

        return [
            'enabled' => (bool) ($value['enabled'] ?? $defaults['enabled']),
            'retentionMonths' => max(1, min(36, (int) ($value['retentionMonths'] ?? $defaults['retentionMonths']))),
            'activeMinutes' => max(1, min(30, (int) ($value['activeMinutes'] ?? $defaults['activeMinutes']))),
            'sessionMinutes' => max(5, min(240, (int) ($value['sessionMinutes'] ?? $defaults['sessionMinutes']))),
            'excludedPaths' => array_slice($paths, 0, 30),
        ];
    }
}
