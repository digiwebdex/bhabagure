<?php

namespace App\Http\Resources;

use App\Models\Inquiry;
use App\Models\Staff;
use Illuminate\Support\Carbon;

/** An air-ticket enquiry in the Air ticketing queue (docs/phase-5-admin-core.md §4.7). */
final class AdminAirInquiry
{
    /** @return array<string, mixed> */
    public static function row(Inquiry $inquiry, Staff $viewer, ?Carbon $now = null): array
    {
        $details = $inquiry->details ?? [];

        return AdminQuoteRequest::common($inquiry, $viewer, $now) + [
            'from' => $details['from'] ?? null,
            'to' => $details['to'] ?? null,
            'depart_on' => $details['departOn'] ?? null,
            'return_on' => $details['returnOn'] ?? null,
            'cabin_class' => $details['cabinClass'] ?? null,
            'passengers' => $inquiry->pax,
        ];
    }
}
