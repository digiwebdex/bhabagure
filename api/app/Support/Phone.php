<?php

namespace App\Support;

/** Bangladeshi mobile numbers in the stored form `8801XXXXXXXXX` — the form WaSenderAPI needs. */
final class Phone
{
    private const BANGLA_DIGITS = ['০', '১', '২', '৩', '৪', '৫', '৬', '৭', '৮', '৯'];

    /** Mirrors normalizeBdMobile() in web/src/lib/validators.ts. Returns null when it isn't a BD mobile. */
    public static function normalizeBdMobile(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $digits = str_replace(self::BANGLA_DIGITS, ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'], $value);
        $digits = preg_replace('/[\s\-()]/u', '', $digits) ?? '';

        return preg_match('/^(?:\+?88)?(01[3-9]\d{8})$/', $digits, $match) === 1 ? '88'.$match[1] : null;
    }

    /**
     * A WhatsApp number as staff type it, as digits with the country code (docs/admin-inbox.md §8): a Bangladeshi mobile
     * in any of its forms (01711-000000, +880 1711…), or another country's written with its + or 00 code
     * (+977 981-2345678). Null for anything else: without a code a foreign number can't be told from a typo.
     * Mirrored by whatsAppNumber() in admin/src/features/inbox/phone.ts.
     */
    public static function normalizeWhatsApp(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $digits = str_replace(self::BANGLA_DIGITS, ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'], $value);
        $digits = preg_replace('/[\s\-().]/u', '', $digits) ?? '';

        return self::normalizeBdMobile($digits) ?? (preg_match('/^(?:\+|00)([1-9]\d{7,14})$/', $digits, $match) === 1 ? $match[1] : null);
    }
}
