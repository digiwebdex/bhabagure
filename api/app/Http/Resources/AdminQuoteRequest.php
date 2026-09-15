<?php

namespace App\Http\Resources;

use App\Models\Inquiry;
use App\Models\Staff;
use Illuminate\Support\Carbon;

/**
 * What every quotation-request queue shows about a row — Air ticketing and Hotel requests: who asked, how long it has
 * waited, who owns it, the replies sent and what the viewer may do. Each queue adds its own request fields.
 */
final class AdminQuoteRequest
{
    /** @return array<string, mixed> */
    public static function common(Inquiry $inquiry, Staff $viewer, ?Carbon $now = null): array
    {
        $now ??= now();
        $open = $inquiry->status === Inquiry::OPEN;
        $ageHours = (int) floor($inquiry->created_at->diffInMinutes($now, true) / 60);
        $manage = $viewer->can("{$inquiry->type->queuePermission()}.manage");

        return [
            'id' => $inquiry->id,
            'type' => $inquiry->type->value,
            'name' => $inquiry->name,
            'phone' => $inquiry->phone,
            'email' => $inquiry->email,
            'locale' => $inquiry->locale,
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
            'replies_count' => (int) ($inquiry->replies_count ?? 0),
            'last_reply_at' => $inquiry->replies_max_created_at ? Carbon::parse($inquiry->replies_max_created_at)->toIso8601String() : null,
            'actions' => [
                'claim' => $manage && $open && $inquiry->assigned_staff_id === null,
                'mark_quoted' => $manage && $open,
                'undo_quoted' => ! $open && $manage && ($inquiry->quoted_by_staff_id === $viewer->id || $viewer->can('records.assign')),
                'assign' => $viewer->can('records.assign'),
                'reply' => $manage && $viewer->can('notifications.send'),
            ],
        ];
    }
}
