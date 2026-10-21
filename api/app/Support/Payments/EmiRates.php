<?php

namespace App\Support\Payments;

use App\Models\SiteSetting;

/**
 * EMI rates for the booking form's preview (docs/emi-preview.md): per rate group, the charge in percent for each tenure,
 * from SSLCommerz's printed chart, edited in Admin → Site settings → EMI rates. Information only: nothing here changes
 * a booking's amount or how it is paid.
 */
final class EmiRates
{
    public const SETTING = 'emi';

    /** The tenures on the chart, in months. */
    public const TENURES = [3, 6, 9, 12, 18, 24, 30, 36];

    /** The rate groups on the chart, in the order the website shows them: the lowest rates first. */
    public const GROUPS = ['all_banks', 'standard_chartered', 'city_bank'];

    /**
     * SSLCommerz's chart as supplied (2026-10-22): card and EMI charges included. Standard Chartered has no 30-month
     * EMI (null — not a 0 % charge). "For All (35) Banks" is a rate group whose member banks aren't listed yet, so its
     * eligibility is shown as to be confirmed.
     *
     * @return array{enabled: bool, minAmount: int, groups: list<array{key: string, name: array{bn: string, en: string}, enabled: bool, eligibilityPending: bool, rates: array<string, float|null>}>}
     */
    public static function defaults(): array
    {
        $rates = fn (array $percents) => array_combine(array_map('strval', self::TENURES), $percents);

        return [
            'enabled' => true,
            'minAmount' => 5000,
            'groups' => [
                ['key' => 'all_banks', 'name' => ['bn' => 'অন্যান্য ব্যাংক (৩৫টি)', 'en' => 'Other banks (35)'], 'enabled' => true, 'eligibilityPending' => true,
                    'rates' => $rates([5.13, 6.75, 8.99, 11.33, 15.03, 20.38, 21.80, 26.26])],
                ['key' => 'standard_chartered', 'name' => ['bn' => 'স্ট্যান্ডার্ড চার্টার্ড', 'en' => 'Standard Chartered'], 'enabled' => true, 'eligibilityPending' => false,
                    'rates' => $rates([5.67, 7.86, 10.74, 13.77, 17.65, 23.25, null, 31.07])],
                ['key' => 'city_bank', 'name' => ['bn' => 'দ্য সিটি ব্যাংক', 'en' => 'The City Bank'], 'enabled' => true, 'eligibilityPending' => false,
                    'rates' => $rates([5.65, 7.27, 9.51, 11.85, 15.55, 20.90, 22.32, 26.78])],
            ],
        ];
    }

    /**
     * The saved setting, or the chart until one is saved; in today's shape, every group and tenure present.
     *
     * @return array{enabled: bool, minAmount: int, groups: list<array{key: string, name: array{bn: string, en: string}, enabled: bool, eligibilityPending: bool, rates: array<string, float|null>}>}
     */
    public static function settings(): array
    {
        $saved = SiteSetting::get(self::SETTING);

        return is_array($saved) ? self::normalize($saved) : self::defaults();
    }

    /**
     * Only the known groups and tenures, in order: a rate is a number of percent, or null where the tenure isn't
     * offered. A group missing from `$value` keeps the chart's figures.
     *
     * @param  array<string, mixed>  $value
     * @return array{enabled: bool, minAmount: int, groups: list<array{key: string, name: array{bn: string, en: string}, enabled: bool, eligibilityPending: bool, rates: array<string, float|null>}>}
     */
    public static function normalize(array $value): array
    {
        $given = collect(is_array($value['groups'] ?? null) ? $value['groups'] : [])->filter(fn ($group) => is_array($group))->keyBy('key');
        $groups = [];
        foreach (self::defaults()['groups'] as $default) {
            $group = $given->get($default['key']);
            if ($group === null) {
                $groups[] = $default;

                continue;
            }
            $rates = [];
            foreach (self::TENURES as $months) {
                $rate = $group['rates'][(string) $months] ?? null;
                $rates[(string) $months] = is_numeric($rate) ? round((float) $rate, 2) : null;
            }
            $groups[] = [
                'key' => $default['key'],
                'name' => [
                    'bn' => trim((string) ($group['name']['bn'] ?? '')) ?: $default['name']['bn'],
                    'en' => trim((string) ($group['name']['en'] ?? '')) ?: $default['name']['en'],
                ],
                'enabled' => (bool) ($group['enabled'] ?? true),
                'eligibilityPending' => (bool) ($group['eligibilityPending'] ?? false),
                'rates' => $rates,
            ];
        }

        return [
            'enabled' => (bool) ($value['enabled'] ?? true),
            'minAmount' => max(0, (int) ($value['minAmount'] ?? 5000)),
            'groups' => $groups,
        ];
    }

    /**
     * What the website's preview needs (GET /public/pricing): null while EMI is switched off; otherwise the minimum
     * amount and the groups that are on, each with only the tenures it offers.
     *
     * @return array{minAmount: int, groups: list<array{key: string, name: array{bn: string, en: string}, eligibilityPending: bool, tenures: list<array{months: int, percent: float}>}>}|null
     */
    public static function forWebsite(): ?array
    {
        $settings = self::settings();
        if (! $settings['enabled']) {
            return null;
        }
        $groups = [];
        foreach ($settings['groups'] as $group) {
            $tenures = [];
            foreach ($group['rates'] as $months => $percent) {
                if ($percent !== null) {
                    $tenures[] = ['months' => (int) $months, 'percent' => $percent];
                }
            }
            if ($group['enabled'] && $tenures !== []) {
                $groups[] = ['key' => $group['key'], 'name' => $group['name'], 'eligibilityPending' => $group['eligibilityPending'], 'tenures' => $tenures];
            }
        }

        return $groups === [] ? null : ['minAmount' => $settings['minAmount'], 'groups' => $groups];
    }
}
