<?php

namespace App\Support\Admin;

use App\Enums\BookingStatus;
use App\Http\Controllers\Api\V1\Admin\DocumentReviewController;
use App\Http\Controllers\Api\V1\Admin\LeaveRequestController;
use App\Http\Controllers\Api\V1\Admin\StaffDocumentController;
use App\Http\Controllers\Api\V1\Admin\SupportTicketController;
use App\Models\Booking;
use App\Models\Inquiry;
use App\Models\LeaveRequest;
use App\Models\Quotation;
use App\Models\Staff;
use App\Models\StaffDocument;
use App\Models\SupportTicket;
use App\Models\TravellerDocument;
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
            // Sent quotations running out within 48 hours (Dhaka) — the "Expiring soon" KPI shows the same.
            'quotations' => [
                'permission' => ['quotations.view_all', 'quotations.view_own'],
                'path' => '/api/v1/admin/quotations',
                'filter' => ['status' => 'expiring'],
                'query' => fn (Staff $staff) => Quotation::query()->visibleTo($staff)->filtered(['status' => 'expiring'], $staff),
            ],
            // Passport scans and photos from customers waiting for review — the Documents queue's default list.
            'documents' => [
                'permission' => ['bookings.view_all', 'bookings.view_own'],
                'path' => '/api/v1/admin/document-reviews',
                'filter' => ['status' => TravellerDocument::UPLOADED],
                'query' => fn (Staff $staff) => DocumentReviewController::queue($staff),
            ],
            // Open air-ticket enquiries waiting more than 24 hours — the rows the queue flags.
            'air_inquiries' => [
                'permission' => ['air_inquiries.view'],
                'path' => '/api/v1/admin/air-inquiries',
                'filter' => ['state' => 'open', 'stale' => '1'],
                'query' => fn (Staff $staff) => Inquiry::query()->airQuotes()->visibleTo($staff)->filtered(['state' => 'open', 'stale' => '1'], $staff),
            ],
            // Portal support tickets waiting for staff longer than the 24 hours the portal promises. One shared queue.
            'support' => [
                'permission' => ['support.manage'],
                'path' => '/api/v1/admin/support-tickets',
                'filter' => ['status' => SupportTicket::OPEN, 'overdue' => '1'],
                'query' => fn (Staff $staff) => SupportTicketController::filtered(['status' => SupportTicket::OPEN, 'overdue' => '1']),
            ],
            // Leave requests nobody has decided yet — the design's badge on Attendance & salary (Phase 7 §5.1).
            'leave_requests' => [
                'permission' => ['attendance.manage'],
                'path' => '/api/v1/admin/leave-requests',
                'filter' => ['status' => LeaveRequest::PENDING],
                'query' => fn (Staff $staff) => LeaveRequestController::filtered(['status' => LeaveRequest::PENDING]),
            ],
            // Staff documents expired or expiring within 30 days, for staff who aren't suspended (Phase 7 §4.2).
            'staff_documents' => [
                'permission' => ['staff_documents.view'],
                'path' => '/api/v1/admin/staff-documents',
                'filter' => ['status' => StaffDocument::ATTENTION],
                'query' => fn (Staff $staff) => StaffDocumentController::filtered(['status' => StaffDocument::ATTENTION]),
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
