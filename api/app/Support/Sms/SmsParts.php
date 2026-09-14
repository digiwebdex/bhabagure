<?php

namespace App\Support\Sms;

use IntlBreakIterator;

/**
 * How many SMS parts a text costs (3GPP TS 23.038). Text that fits the GSM 7-bit alphabet goes as 160 septets, or 153
 * per part once split; anything else — Bangla, ৳, emoji — goes as UCS-2: 70 code units, or 67 per part. Characters in
 * the GSM extension table take two septets, emoji take two UTF-16 units, and neither is ever split across parts, so
 * parts are counted by packing rather than by dividing.
 *
 * Counted on the exact string that is sent. Where gateways differ, the count errs high: a Bangla conjunct or an emoji
 * sequence may be kept whole by the gateway (so `parts` is the larger of the unit and grapheme packings), and GSM 0x09
 * (ç in Unicode's table, Ç in Twilio's) is treated as outside the alphabet. Fixtures: tests/Fixtures/sms-parts.json.
 */
final class SmsParts
{
    private const GSM7_BASIC = "@£\$¥èéùìò\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";

    /** Escaped characters: two septets each. */
    private const GSM7_EXTENDED = "\f^{}\\[~]|€";

    /**
     * @return array{encoding: 'gsm7'|'ucs2', units: int, parts: int, parts_by_units: int, parts_grapheme_safe: int, per_part: int}
     */
    public static function count(string $text): array
    {
        $basic = array_flip(mb_str_split(self::GSM7_BASIC));
        $extended = array_flip(mb_str_split(self::GSM7_EXTENDED));
        $chars = mb_str_split($text);
        $gsm = true;
        foreach ($chars as $char) {
            if (! isset($basic[$char]) && ! isset($extended[$char])) {
                $gsm = false;
                break;
            }
        }

        $size = fn (string $char) => $gsm ? (isset($extended[$char]) ? 2 : 1) : (mb_ord($char) > 0xFFFF ? 2 : 1);
        [$single, $perPart] = $gsm ? [160, 153] : [70, 67];
        $sizes = array_map($size, $chars);
        $units = array_sum($sizes);

        if ($units === 0) {
            return ['encoding' => $gsm ? 'gsm7' : 'ucs2', 'units' => 0, 'parts' => 0, 'parts_by_units' => 0, 'parts_grapheme_safe' => 0, 'per_part' => $single];
        }
        if ($units <= $single) {
            return ['encoding' => $gsm ? 'gsm7' : 'ucs2', 'units' => $units, 'parts' => 1, 'parts_by_units' => 1, 'parts_grapheme_safe' => 1, 'per_part' => $single];
        }

        $byUnits = self::pack($sizes, $perPart);
        $clusterSizes = array_map(fn (string $cluster) => array_sum(array_map($size, mb_str_split($cluster))), self::graphemes($text));
        $graphemeSafe = self::pack($clusterSizes, $perPart);

        return [
            'encoding' => $gsm ? 'gsm7' : 'ucs2',
            'units' => $units,
            'parts' => max($byUnits, $graphemeSafe),
            'parts_by_units' => $byUnits,
            'parts_grapheme_safe' => $graphemeSafe,
            'per_part' => $perPart,
        ];
    }

    /** @param list<int> $sizes */
    private static function pack(array $sizes, int $capacity): int
    {
        $parts = 1;
        $used = 0;
        foreach ($sizes as $size) {
            if ($size > $capacity) {
                // One cluster longer than a part (a very long emoji sequence): it spans whole parts of its own.
                $parts += ($used > 0 ? 1 : 0) + intdiv($size - 1, $capacity);
                $used = $size % $capacity ?: $capacity;

                continue;
            }
            if ($used + $size > $capacity) {
                $parts++;
                $used = 0;
            }
            $used += $size;
        }

        return $parts;
    }

    /** @return list<string> user-perceived characters (Bangla conjuncts, emoji sequences) */
    private static function graphemes(string $text): array
    {
        $iterator = IntlBreakIterator::createCharacterInstance('bn');
        $iterator->setText($text);
        $clusters = [];
        $start = $iterator->first();
        for ($end = $iterator->next(); $end !== IntlBreakIterator::DONE; $start = $end, $end = $iterator->next()) {
            $clusters[] = substr($text, $start, $end - $start);
        }

        return $clusters;
    }
}
