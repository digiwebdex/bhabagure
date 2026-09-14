<?php

namespace App\Services\Attendance;

use App\Models\LeaveRequest;
use App\Models\LeaveRequestEvent;
use App\Models\Staff;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Leave requests (docs/phase-7-hr-attendance-bonus-wallet.md §5.1). Staff file their own; attendance.manage records
 * leave for someone, approves it — paid, or unpaid — or rejects it with a reason, and can revoke approved leave. Nobody
 * decides their own request. Each step is an append-only event on the request, and decisions are in the audit log.
 */
final class LeaveDesk
{
    /** One request covers at most this many calendar days; longer leave is filed in parts. */
    public const MAX_DAYS = 60;

    public function __construct(private readonly AuditLogger $audit) {}

    /** @throws AttendanceRefused */
    public function file(Staff $staff, string $startsOn, string $endsOn, string $reason, Staff $by): LeaveRequest
    {
        $overlap = LeaveRequest::query()->where('staff_id', $staff->id)->whereIn('status', [LeaveRequest::PENDING, LeaveRequest::APPROVED])
            ->overlapping($startsOn, $endsOn)->exists();
        if ($overlap) {
            throw new AttendanceRefused('leave_overlaps');
        }

        return DB::transaction(function () use ($staff, $startsOn, $endsOn, $reason, $by) {
            $request = LeaveRequest::query()->create([
                'staff_id' => $staff->id, 'starts_on' => $startsOn, 'ends_on' => $endsOn, 'reason' => $reason,
                'status' => LeaveRequest::PENDING, 'filed_by_staff_id' => $by->id,
            ]);
            // Who filed it is the event's actor: the person themselves, or whoever recorded it for them.
            $this->event($request, 'filed', $by, null);

            return $request;
        });
    }

    /** @throws AttendanceRefused */
    public function approve(LeaveRequest $request, bool $paid, ?string $note, Staff $by): LeaveRequest
    {
        return $this->decide($request, LeaveRequest::APPROVED, $by, $note, ['paid' => $paid]);
    }

    /** @throws AttendanceRefused */
    public function reject(LeaveRequest $request, string $note, Staff $by): LeaveRequest
    {
        return $this->decide($request, LeaveRequest::REJECTED, $by, $note);
    }

    /** The owner withdraws a request nobody has decided yet. @throws AttendanceRefused */
    public function cancel(LeaveRequest $request, Staff $by): LeaveRequest
    {
        return DB::transaction(function () use ($request, $by) {
            $locked = LeaveRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($locked->staff_id !== $by->id) {
                throw new AttendanceRefused('not_your_leave');
            }
            if ($locked->status !== LeaveRequest::PENDING) {
                throw new AttendanceRefused('leave_decided');
            }
            $locked->forceFill(['status' => LeaveRequest::CANCELLED])->save();
            $this->event($locked, 'cancelled', $by, null);

            return $locked;
        });
    }

    /** Approved leave taken back, with a reason: those days count as worked or absent again. @throws AttendanceRefused */
    public function revoke(LeaveRequest $request, string $note, Staff $by): LeaveRequest
    {
        return DB::transaction(function () use ($request, $note, $by) {
            $locked = LeaveRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== LeaveRequest::APPROVED) {
                throw new AttendanceRefused('leave_not_approved');
            }
            $this->ensureNotOwn($locked, $by);
            $locked->forceFill(['status' => LeaveRequest::REVOKED, 'decided_by_staff_id' => $by->id, 'decided_at' => now(), 'decision_note' => $note])->save();
            $this->event($locked, 'revoked', $by, $note);
            $this->audit->record('leave.revoked', $by, $locked->staff, ['leave_request_id' => $locked->id, 'note' => $note]);

            return $locked;
        });
    }

    /**
     * @param  array<string, mixed>  $extra
     *
     * @throws AttendanceRefused
     */
    private function decide(LeaveRequest $request, string $status, Staff $by, ?string $note, array $extra = []): LeaveRequest
    {
        return DB::transaction(function () use ($request, $status, $by, $note, $extra) {
            $locked = LeaveRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== LeaveRequest::PENDING) {
                throw new AttendanceRefused('leave_decided');
            }
            $this->ensureNotOwn($locked, $by);
            $locked->forceFill(['status' => $status, 'decided_by_staff_id' => $by->id, 'decided_at' => now(), 'decision_note' => $note] + $extra)->save();
            $action = $status === LeaveRequest::APPROVED ? 'approved' : 'rejected';
            $this->event($locked, $action, $by, $note);
            $this->audit->record("leave.{$action}", $by, $locked->staff, array_filter(['leave_request_id' => $locked->id, 'paid' => $extra['paid'] ?? null, 'note' => $note], fn ($value) => $value !== null));

            return $locked;
        });
    }

    /** @throws AttendanceRefused */
    private function ensureNotOwn(LeaveRequest $request, Staff $by): void
    {
        if ($request->staff_id === $by->id && ! $by->isSuperAdmin()) {
            throw new AttendanceRefused('own_leave');
        }
    }

    private function event(LeaveRequest $request, string $action, Staff $by, ?string $note): void
    {
        LeaveRequestEvent::query()->create(['leave_request_id' => $request->id, 'action' => $action, 'actor_staff_id' => $by->id, 'note' => $note === null ? null : mb_substr($note, 0, 300)]);
    }
}
