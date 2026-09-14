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
        $now ??= now();
        $details = $inquiry->details ?? [];
        $open = $inquiry->status === Inquiry::OPEN;
        $ageHours = (int) floor($inquiry->created_at->diffInMinutes($now, true) / 60);
        $manage = $viewer->can('air_inquiries.manage');

        return [
            'id' => $inquiry->id,
            'name' => $inquiry->name,
            'phone' => $inquiry->phone,
            'email' => $inquiry->email,
            'locale' => $inquiry->locale,
            'from' => $details['from'] ?? null,
            'to' => $details['to'] ?? null,
            'depart_on' => $details['departOn'] ?? null,
            'return_on' => $details['returnOn'] ?? null,
            'cabin_class' => $details['cabinClass'] ?? null,
            'passengers' => $inquiry->pax,
            'status' => $inquiry->status,
            'created_at' => $inquiry->created_at->toIso8601String(),
            'age_hours' => $ageHours,
            // The same rule as Inquiry::scopeStale, which the badge counts.
            'stale' => $open && $inquiry->created_at->lt($now->copy()->subHours(Inquiry::STALE_AFTER_HOURS)),
            'quoted_at' => $inquiry->quoted_at?->toIso8601String(),
            'quoted_by' => $inquiry->relationLoaded('quotedBy') && $inquiry->quotedBy ? ['id' => $inquiry->quotedBy->id, 'name' => $inquiry->quotedBy->name] : null,
            'assigned_staff' => $inquiry->relationLoaded('assignedStaff') && $inquiry->assignedStaff
                ? ['id' => $inquiry->assignedStaff->id, 'name' => $inquiry->assignedStaff->name] : null,
            'customer_id' => $inquiry->customer_id,
            'actions' => [
                'claim' => $manage && $open && $inquiry->assigned_staff_id === null,
                'mark_quoted' => $manage && $open,
                'undo_quoted' => ! $open && $manage && ($inquiry->quoted_by_staff_id === $viewer->id || $viewer->can('records.assign')),
                'assign' => $viewer->can('records.assign'),
            ],
        ];
    }
}
