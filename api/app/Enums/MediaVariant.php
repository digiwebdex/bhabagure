<?php

namespace App\Enums;

/**
 * WebP sizes generated for every upload. Widths are maxima: an image is never enlarged.
 * card: package and blog cards (~400 CSS px at 2x). detail: the modal gallery and full pages.
 */
enum MediaVariant: string
{
    case Thumb = 'thumb';
    case Card = 'card';
    case Detail = 'detail';
    case Full = 'full';

    public function maxWidth(): int
    {
        return match ($this) {
            self::Thumb => 400,
            self::Card => 800,
            self::Detail => 1600,
            self::Full => 2400,
        };
    }
}
