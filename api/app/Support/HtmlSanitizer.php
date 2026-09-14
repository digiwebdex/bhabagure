<?php

namespace App\Support;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer as SymfonySanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Rich text is sanitised when it is saved, against a fixed allow-list (docs/phase-2-website.md §4).
 * The website renders stored HTML as-is, so nothing else may reach the database.
 */
final class HtmlSanitizer
{
    private const ELEMENTS = ['p', 'h2', 'h3', 'strong', 'em', 'a', 'ul', 'ol', 'li', 'blockquote', 'br'];

    private static ?SymfonySanitizer $sanitizer = null;

    public static function clean(?string $html): ?string
    {
        if ($html === null) {
            return null;
        }

        $clean = trim(self::sanitizer()->sanitize($html));

        return $clean === '' ? null : $clean;
    }

    /** Reading time from the visible text: about 200 words a minute, at least one minute. */
    public static function readingMinutes(?string ...$bodies): int
    {
        $words = 0;
        foreach ($bodies as $body) {
            $text = trim(html_entity_decode(strip_tags((string) $body), ENT_QUOTES | ENT_HTML5));
            $words = max($words, $text === '' ? 0 : count(preg_split('/\s+/u', $text) ?: []));
        }

        return max(1, (int) ceil($words / 200));
    }

    private static function sanitizer(): SymfonySanitizer
    {
        if (self::$sanitizer !== null) {
            return self::$sanitizer;
        }

        $config = (new HtmlSanitizerConfig)
            ->allowLinkSchemes(['https', 'http', 'mailto', 'tel'])
            ->allowRelativeLinks()
            ->forceAttribute('a', 'rel', 'noopener noreferrer')
            ->withMaxInputLength(200_000);

        foreach (self::ELEMENTS as $element) {
            $config = $config->allowElement($element, $element === 'a' ? ['href'] : []);
        }

        return self::$sanitizer = new SymfonySanitizer($config);
    }
}
