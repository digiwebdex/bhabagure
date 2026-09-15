<?php

namespace App\Enums;

/**
 * The hotel categories the client quotes (2026-09-15): basic or 3-star, 4-star, 5-star. The value is the star count.
 * Hotel quotation requests use it now; the per-package price grid (docs/phase-8-visa-quotes-pricing-downloads.md §2.1)
 * uses the same three.
 */
enum HotelCategory: string
{
    case ThreeStar = '3';
    case FourStar = '4';
    case FiveStar = '5';

    public function label(string $locale): string
    {
        return match ($this) {
            self::ThreeStar => $locale === 'en' ? 'Basic / 3-star' : 'বেসিক / ৩ তারকা',
            self::FourStar => $locale === 'en' ? '4-star' : '৪ তারকা',
            self::FiveStar => $locale === 'en' ? '5-star' : '৫ তারকা',
        };
    }
}
