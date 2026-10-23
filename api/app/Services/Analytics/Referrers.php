<?php

namespace App\Services\Analytics;

/**
 * Where a visit came from (docs/visitor-analytics.md): a campaign only when the link carried utm_source; otherwise a
 * search engine, a social network, another site, or direct (no referrer, or one of our own pages). Only the
 * referring site's domain is kept, never the full address.
 */
final class Referrers
{
    private const SEARCH = ['google.', 'bing.com', 'yahoo.', 'duckduckgo.com', 'yandex.', 'baidu.com', 'ecosia.org', 'search.brave.com', 'startpage.com'];

    private const SOCIAL = ['facebook.com', 'fb.com', 'fb.me', 'messenger.com', 'instagram.com', 'l.instagram.com', 'youtube.com', 'youtu.be', 'tiktok.com',
        'linkedin.com', 'lnkd.in', 't.co', 'twitter.com', 'x.com', 'pinterest.', 'reddit.com', 'whatsapp.com', 'wa.me', 'telegram.org', 't.me', 'threads.net'];

    /** Our own hosts: a referrer from these is a page of ours, so the visit counts as direct. */
    private const OWN = ['bhabaghure.com.bd'];

    /**
     * @param  array{source?: ?string, medium?: ?string, campaign?: ?string}  $utm
     * @return array{source: string, domain: ?string}
     */
    public static function classify(?string $referrer, array $utm): array
    {
        $host = is_string($referrer) ? mb_strtolower((string) parse_url($referrer, PHP_URL_HOST)) : '';
        $host = preg_replace('/^www\./', '', $host) ?? '';
        $own = $host === '' || collect(self::OWN)->contains(fn (string $own) => $host === $own || str_ends_with($host, '.'.$own));
        $domain = $own ? null : mb_substr($host, 0, 120);

        if (filled($utm['source'] ?? null)) {
            return ['source' => 'campaign', 'domain' => $domain];
        }
        if ($domain === null) {
            return ['source' => 'direct', 'domain' => null];
        }

        return ['source' => self::listed($domain, self::SEARCH) ? 'search' : (self::listed($domain, self::SOCIAL) ? 'social' : 'referral'), 'domain' => $domain];
    }

    /**
     * Whether a domain is one of the list's: "x.com" matches x.com and its subdomains; "google." (ending in a dot)
     * matches Google in any country, google.com.bd or www.google.co.uk.
     *
     * @param  list<string>  $list
     */
    private static function listed(string $domain, array $list): bool
    {
        foreach ($list as $entry) {
            $hit = str_ends_with($entry, '.')
                ? str_starts_with($domain, $entry) || str_contains($domain, '.'.$entry)
                : $domain === $entry || str_ends_with($domain, '.'.$entry);
            if ($hit) {
                return true;
            }
        }

        return false;
    }
}
