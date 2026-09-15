<?php

namespace App\Enums;

enum InquiryType: string
{
    case Contact = 'contact';
    case AirQuote = 'air_quote';
    /** The website's hotel quotation request (docs/phase-8-visa-quotes-pricing-downloads.md §4.B). */
    case HotelQuote = 'hotel_quote';

    /** The permission prefix of the admin queue that works this kind: `<prefix>.view`, `<prefix>.manage`. Null: no queue. */
    public function queuePermission(): ?string
    {
        return match ($this) {
            self::AirQuote => 'air_inquiries',
            self::HotelQuote => 'hotel_inquiries',
            self::Contact => null,
        };
    }

    /** @return list<self> the kinds worked from a queue */
    public static function queued(): array
    {
        return [self::AirQuote, self::HotelQuote];
    }
}
