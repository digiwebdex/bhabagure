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
}
