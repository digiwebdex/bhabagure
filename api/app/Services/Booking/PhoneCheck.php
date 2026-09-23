<?php

namespace App\Services\Booking;

use App\Models\SiteSetting;

/**
 * Whether a website booking needs a code sent to the lead traveller's mobile before it is saved
 * (docs/booking-phone-verification.md). Admin → Site settings → Website booking switches it; off by default, and left off
 * until SMS reaches customers (decided 2026-09-24). Office bookings never need one.
 */
final class PhoneCheck
{
    /** The site setting: `{ verifyPhone: bool }`. */
    public const SETTING = 'booking';

    public static function required(): bool
    {
        return (bool) (SiteSetting::get(self::SETTING, [])['verifyPhone'] ?? false);
    }
}
