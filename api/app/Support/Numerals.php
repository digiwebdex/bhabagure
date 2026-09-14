<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * PHP twin of @bhabaghure/format, for text the API renders itself (invoice PDFs, messages). Held to
 * packages/format/fixtures.json by tests/Unit/NumeralsTest, so the invoice prints ৳ ১,৫৩,০০০ exactly as the admin does.
 * Grouping is en-IN; Bangla mode uses Bengali digits. Identifiers (invoice numbers, phones) never pass through here.
 */
final class Numerals
{
    private const BENGALI_DIGITS = ['০', '১', '২', '৩', '৪', '৫', '৬', '৭', '৮', '৯'];

    private const MINUS = '−';

    private const CURRENCY_PREFIX = ['bn' => '৳ ', 'en' => 'BDT '];

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

    public static function bdt(int|float|string $value, string $locale, int|string $decimals = 'auto'): string
    {
        [$negative, $digits] = self::grouped($value, $decimals);

        return ($negative ? self::MINUS : '').self::CURRENCY_PREFIX[$locale].self::localizeDigits($digits, $locale);
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
        if (is_string($value)) {
            if (trim($value) === '' || ! is_numeric(trim($value))) {
                throw new InvalidArgumentException("Not a finite number: \"{$value}\"");
            }
            $value = (float) trim($value);
        }
        if (! is_finite((float) $value)) {
            throw new InvalidArgumentException('Not a finite number');
        }

        $places = $decimals === 'auto' ? (floor((float) $value) == $value ? 0 : 2) : (int) $decimals;
        $fixed = number_format(abs((float) $value), $places, '.', '');
        [$whole, $fraction] = array_pad(explode('.', $fixed), 2, null);

        $grouped = strlen($whole) <= 3 ? $whole
            : preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', substr($whole, 0, -3)).','.substr($whole, -3);

        return [(float) $value < 0 && (float) $fixed != 0, $grouped.($fraction !== null ? ".{$fraction}" : '')];
    }
}
