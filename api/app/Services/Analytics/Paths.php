<?php

namespace App\Services\Analytics;

/**
 * A page's address as analytics keeps it (docs/visitor-analytics.md): the path only, without the query or the
 * language prefix (bn and en count as one page), private parts replaced by a placeholder, and the pages never counted
 * left out.
 */
final class Paths
{
    /** Never counted: the customer portal (private) and anything that isn't a public page. */
    private const NEVER = ['/portal', '/api', '/_next', '/admin'];

    /** Parts of a path that belong to one customer: kept as the page's kind, never the value. */
    private const PRIVATE = [
        '#^/booking/[^/]+$#' => '/booking/:reference',
        '#^/newsletter/unsubscribe/[^/]+$#' => '/newsletter/unsubscribe/:token',
    ];

    /** @param  list<string>  $excluded  Settings → excluded path prefixes */
    public static function normalize(?string $path, array $excluded = []): ?string
    {
        if (! is_string($path) || ! str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return null;
        }
        $path = (string) (parse_url('https://x'.$path, PHP_URL_PATH) ?: '/');
        $path = rawurldecode($path);
        // The English site is the same page under /en (next-intl's "as-needed" prefix).
        if ($path === '/en' || str_starts_with($path, '/en/')) {
            $path = substr($path, 3) ?: '/';
        }
        $path = '/'.trim(preg_replace('#/+#', '/', $path) ?? '/', '/');
        foreach ([...self::NEVER, ...$excluded] as $prefix) {
            $prefix = rtrim($prefix, '/');
            if ($prefix !== '' && ($path === $prefix || str_starts_with($path, $prefix.'/'))) {
                return null;
            }
        }
        foreach (self::PRIVATE as $pattern => $placeholder) {
            if (preg_match($pattern, $path)) {
                return $placeholder;
            }
        }

        return mb_substr($path, 0, 255);
    }

    /** What a page is about, for the tours report: "package:<slug>" or "visa:<slug>"; null for other pages. */
    public static function subject(string $path): ?string
    {
        return preg_match('#^/(packages|visa)/([a-z0-9-]{1,100})$#', $path, $match) ? ($match[1] === 'packages' ? 'package' : 'visa').':'.$match[2] : null;
    }
}
