<?php

namespace App\Http\Resources;

use App\Models\Inquiry;
use App\Models\Staff;
use Illuminate\Support\Carbon;

/** A hotel quotation request in the Hotel requests queue (docs/phase-8-visa-quotes-pricing-downloads.md §4.B). */
final class AdminHotelInquiry
{
    /** @return array<string, mixed> */
    public static function row(Inquiry $inquiry, Staff $viewer, ?Carbon $now = null): array
    {
        $details = $inquiry->details ?? [];

        return AdminQuoteRequest::common($inquiry, $viewer, $now) + [
            'location' => $details['location'] ?? null,
            'check_in' => $details['checkIn'] ?? null,
            'check_out' => $details['checkOut'] ?? null,
            'hotel_category' => $details['hotelCategory'] ?? null,
            'guests' => $inquiry->pax,
            'note' => $details['note'] ?? null,
        ];
    }
}
