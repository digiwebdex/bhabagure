<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * PHP twin of @bhabaghure/format, for text the API renders itself (invoice PDFs, messages). Held to
 * packages/format/fixtures.json by tests/Unit/NumeralsTest, so the invoice prints ৳ ১,৫৩,০০০ exactly as the admin does.
 * Grouping is en-IN; Bangla mode uses Bengali digits; "৳" in both languages. Identifiers (invoice numbers, phones)
 * never pass through here. Only the SMS channel asks for currency 'code' ("BDT"): "৳" would make an SMS Unicode.
 */
final class Numerals
{
    private const BENGALI_DIGITS = ['০', '১', '২', '৩', '৪', '৫', '৬', '৭', '৮', '৯'];

    private const MINUS = '−';

    private const CURRENCY_PREFIX = ['symbol' => '৳ ', 'code' => 'BDT '];

    private const LAKH = 100000;

    private const CRORE = 10000000;

    private const SCALE_SUFFIX = [
        'bn' => ['lakh' => ' লাখ', 'crore' => ' কোটি'],
        'en' => ['lakh' => 'L', 'crore' => 'Cr'],
    ];

    private const MONTHS = [
        'bn' => ['জানুয়ারি', 'ফেব্রুয়ারি', 'মার্চ', 'এপ্রিল', 'মে', 'জুন', 'জুলাই', 'আগস্ট', 'সেপ্টেম্বর', 'অক্টোবর', 'নভেম্বর', 'ডিসেম্বর'],
        'en' => ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
    ];

    public static function localizeDigits(string $text, string $locale): string
    {
        return $locale === 'bn'
            ? str_replace(['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'], self::BENGALI_DIGITS, $text)
            : str_replace(self::BENGALI_DIGITS, ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'], $text);
    }

    public static function number(int|float|string $value, string $locale, int|string $decimals = 'auto'): string
    {
        [$negative, $digits] = self::grouped($value, $decimals);

        return ($negative ? self::MINUS : '').self::localizeDigits($digits, $locale);
    }

    /** @param 'symbol'|'code' $currency */
    public static function bdt(int|float|string $value, string $locale, int|string $decimals = 'auto', string $currency = 'symbol'): string
    {
        [$negative, $digits] = self::grouped($value, $decimals);

        return ($negative ? self::MINUS : '').self::CURRENCY_PREFIX[$currency].self::localizeDigits($digits, $locale);
    }

    /**
     * "৳ 14.2L" / "৳ ১৪.২ লাখ", "৳ 2.4Cr" / "৳ ২.৪ কোটি"; below one lakh the whole amount in taka. Same integer rounding
     * as formatBdtCompact, so both twins agree at every boundary.
     *
     * @param  'symbol'|'code'  $currency
     */
    public static function bdtCompact(int|float|string $value, string $locale, string $currency = 'symbol'): string
    {
        $n = self::finite($value);
        $taka = (int) round(abs($n));
        $prefix = ($n < 0 && $taka !== 0 ? self::MINUS : '').self::CURRENCY_PREFIX[$currency];

        if ($taka < self::LAKH) {
            return $prefix.self::localizeDigits(self::groupIndian((string) $taka), $locale);
        }
        $lakhTenths = intdiv($taka + intdiv(self::LAKH, 20), intdiv(self::LAKH, 10));
        if ($lakhTenths < 1000) {
            return $prefix.self::tenths($lakhTenths, $locale).self::SCALE_SUFFIX[$locale]['lakh'];
        }
        $croreTenths = intdiv($taka + intdiv(self::CRORE, 20), intdiv(self::CRORE, 10));

        return $prefix.self::tenths($croreTenths, $locale).self::SCALE_SUFFIX[$locale]['crore'];
    }

    private static function tenths(int $count, string $locale): string
    {
        return self::localizeDigits(self::groupIndian((string) intdiv($count, 10)).'.'.($count % 10), $locale);
    }

    private static function groupIndian(string $whole): string
    {
        return strlen($whole) <= 3 ? $whole
            : preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', substr($whole, 0, -3)).','.substr($whole, -3);
    }

    /** 12 → "১২%" · 2.5 → "2.5%" · 1.85 → "1.85%": up to two decimals, never trailing zeros. */
    public static function percent(int|float $value, string $locale): string
    {
        $places = floor($value) == $value ? 0 : (floor(round($value * 100) / 10) == round($value * 100) / 10 ? 1 : 2);

        return self::number($value, $locale, $places).'%';
    }

    /** "2026-09-10" → "১০ সেপ্টেম্বর ২০২৬" / "10 September 2026". */
    public static function date(string $isoDate, string $locale): string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $isoDate, $m) !== 1 || (int) $m[2] < 1 || (int) $m[2] > 12 || (int) $m[3] < 1 || (int) $m[3] > 31) {
            throw new InvalidArgumentException("Expected a YYYY-MM-DD date, got \"{$isoDate}\"");
        }

        return self::localizeDigits(((int) $m[3]).' '.self::MONTHS[$locale][(int) $m[2] - 1].' '.$m[1], $locale);
    }

    /** @return array{0: bool, 1: string} */
    private static function grouped(int|float|string $value, int|string $decimals): array
    {
        $value = self::finite($value);
        $places = $decimals === 'auto' ? (floor($value) == $value ? 0 : 2) : (int) $decimals;
        $fixed = number_format(abs($value), $places, '.', '');
        [$whole, $fraction] = array_pad(explode('.', $fixed), 2, null);

        return [$value < 0 && (float) $fixed != 0, self::groupIndian($whole).($fraction !== null ? ".{$fraction}" : '')];
    }

    private static function finite(int|float|string $value): float
    {
        if (is_string($value)) {
            if (trim($value) === '' || ! is_numeric(trim($value))) {
                throw new InvalidArgumentException("Not a finite number: \"{$value}\"");
            }
            $value = (float) trim($value);
        }
        if (! is_finite((float) $value)) {
            throw new InvalidArgumentException('Not a finite number');
        }

        return (float) $value;
    }
}
