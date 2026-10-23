<?php

namespace App\Services\Analytics;

/**
 * The browser behind a visit, in broad families only (docs/visitor-analytics.md): device category, browser and
 * operating system — never a version string or anything that could single a person out. Bots, crawlers, link
 * previews and scripts are recognised by their user agent and not counted at all.
 */
final class UserAgent
{
    /** Substrings (lower case) that mark automated traffic. Add to it here; the docs list the reasons. */
    private const BOT_MARKERS = [
        'bot', 'crawl', 'spider', 'slurp', 'mediapartners', 'facebookexternalhit', 'facebookcatalog', 'whatsapp', 'telegram',
        'skypeuripreview', 'preview', 'lighthouse', 'pagespeed', 'gtmetrix', 'pingdom', 'uptime', 'monitor', 'headless',
        'phantomjs', 'puppeteer', 'playwright', 'selenium', 'python', 'curl', 'wget', 'httpclient', 'okhttp', 'go-http',
        'java/', 'libwww', 'axios', 'node-fetch', 'scrapy', 'feed', 'validator', 'archive.org',
    ];

    /** @return array{bot: bool, device: string, browser: string, os: string} */
    public static function classify(?string $agent, bool $allowHeadless = false): array
    {
        $ua = mb_strtolower(trim((string) $agent));
        $markers = $allowHeadless ? array_diff(self::BOT_MARKERS, ['headless', 'playwright']) : self::BOT_MARKERS;
        $bot = $ua === '' || mb_strlen($ua) < 20;
        foreach ($markers as $marker) {
            if (! $bot && str_contains($ua, $marker)) {
                $bot = true;
            }
        }

        return ['bot' => $bot, 'device' => self::device($ua), 'browser' => self::browser($ua), 'os' => self::os($ua)];
    }

    private static function device(string $ua): string
    {
        return match (true) {
            str_contains($ua, 'ipad') || str_contains($ua, 'tablet') || (str_contains($ua, 'android') && ! str_contains($ua, 'mobile')) => 'tablet',
            str_contains($ua, 'mobi') || str_contains($ua, 'iphone') || str_contains($ua, 'android') => 'mobile',
            default => 'desktop',
        };
    }

    private static function browser(string $ua): string
    {
        return match (true) {
            str_contains($ua, 'edg/') || str_contains($ua, 'edga/') || str_contains($ua, 'edgios/') => 'Edge',
            str_contains($ua, 'samsungbrowser') => 'Samsung Internet',
            str_contains($ua, 'opr/') || str_contains($ua, 'opera') => 'Opera',
            str_contains($ua, 'ucbrowser') => 'UC Browser',
            str_contains($ua, 'fban') || str_contains($ua, 'fbav') => 'Facebook app',
            str_contains($ua, 'instagram') => 'Instagram app',
            str_contains($ua, 'firefox') || str_contains($ua, 'fxios') => 'Firefox',
            str_contains($ua, 'chrome') || str_contains($ua, 'crios') => 'Chrome',
            str_contains($ua, 'safari') => 'Safari',
            default => 'Other',
        };
    }

    private static function os(string $ua): string
    {
        return match (true) {
            str_contains($ua, 'android') => 'Android',
            str_contains($ua, 'iphone') || str_contains($ua, 'ipad') || str_contains($ua, 'ipod') => 'iOS',
            str_contains($ua, 'windows') => 'Windows',
            str_contains($ua, 'mac os') || str_contains($ua, 'macintosh') => 'macOS',
            str_contains($ua, 'cros') => 'ChromeOS',
            str_contains($ua, 'linux') => 'Linux',
            default => 'Other',
        };
    }
}
