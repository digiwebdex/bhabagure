<?php

namespace App\Support\Admin;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Inquiry;
use App\Models\Staff;
use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * Every sidebar badge (docs/phase-5-admin-core.md §3.1, §0). A badge is never a constant: it is the total of its list
 * endpoint under a filter, counted with the same visibility scope and the same `filtered` scope the list uses. The
 * admin opens the list with exactly that filter when the badge is clicked, so the badge and the list cannot disagree.
 * Tests\Feature\NavCountsContractTest holds `count === GET <path>?<filter>.meta.total` for every role.
 */
final class NavBadges
{
    /**
     * @return array<string, array{permission: list<string>, path: string, filter: array<string, string>, query: Closure(Staff): Builder}>
     */
    public static function registry(): array
    {
        return [
            // Inquiries the staff member can see — the same number the Inquiry chip on the Bookings list shows.
            'bookings' => [
                'permission' => ['bookings.view_all', 'bookings.view_own'],
                'path' => '/api/v1/admin/bookings',
                'filter' => ['status' => BookingStatus::Inquiry->value],
                'query' => fn (Staff $staff) => Booking::query()->visibleTo($staff)->filtered(['status' => BookingStatus::Inquiry->value], $staff),
            ],
            // Open air-ticket enquiries waiting more than 24 hours — the rows the queue flags.
            'air_inquiries' => [
                'permission' => ['air_inquiries.view'],
                'path' => '/api/v1/admin/air-inquiries',
                'filter' => ['state' => 'open', 'stale' => '1'],
                'query' => fn (Staff $staff) => Inquiry::query()->airQuotes()->visibleTo($staff)->filtered(['state' => 'open', 'stale' => '1'], $staff),
            ],
        ];
    }

    /** @return array<string, array{count: int, filter: array<string, string>}> only the badges this staff member may see */
    public static function for(Staff $staff): array
    {
        $counts = [];
        foreach (self::registry() as $key => $badge) {
            if (! collect($badge['permission'])->contains(fn (string $permission) => $staff->can($permission))) {
                continue;
            }
            $counts[$key] = ['count' => ($badge['query'])($staff)->count(), 'filter' => $badge['filter']];
        }

        return $counts;
    }
}
