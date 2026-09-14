<?php

namespace App\Services\Attendance;

use App\Models\AttendanceCorrection;
use App\Models\Staff;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Days set by hand (docs/phase-7-hr-attendance-bonus-wallet.md §5.1): an in or out time, or "worked" for official duty
 * away from the device. Nothing is overwritten — the original punches stay beside the correction, and a mistaken
 * correction is undone by a reversal row. Nobody corrects their own attendance, except a super admin.
 */
final class CorrectionLog
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @throws AttendanceRefused */
    public function add(Staff $staff, string $date, string $kind, ?string $time, string $reason, Staff $by): AttendanceCorrection
    {
        if ($staff->is($by) && ! $by->isSuperAdmin()) {
            throw new AttendanceRefused('own_attendance');
        }
        if ($date > now('Asia/Dhaka')->toDateString()) {
            throw new AttendanceRefused('future_day');
        }

        return DB::transaction(function () use ($staff, $date, $kind, $time, $reason, $by) {
            $correction = AttendanceCorrection::query()->create([
                'staff_id' => $staff->id, 'work_date' => $date, 'kind' => $kind,
                'time' => $kind === AttendanceCorrection::WORKED ? null : $time, 'reason' => $reason, 'created_by_staff_id' => $by->id,
            ]);
            $this->audit->record('attendance.corrected', $by, $staff, array_filter(['correction_id' => $correction->id, 'date' => $date, 'kind' => $kind, 'time' => $time, 'reason' => $reason]));

            return $correction;
        });
    }

    /** @throws AttendanceRefused */
    public function reverse(AttendanceCorrection $correction, string $reason, Staff $by): AttendanceCorrection
    {
        return DB::transaction(function () use ($correction, $reason, $by) {
            $locked = AttendanceCorrection::query()->whereKey($correction->id)->lockForUpdate()->firstOrFail();
            if ($locked->kind === AttendanceCorrection::REVERSAL || AttendanceCorrection::query()->where('reverses_id', $locked->id)->exists()) {
                throw new AttendanceRefused('already_reversed');
            }
            if ($locked->staff_id === $by->id && ! $by->isSuperAdmin()) {
                throw new AttendanceRefused('own_attendance');
            }
            $reversal = AttendanceCorrection::query()->create([
                'staff_id' => $locked->staff_id, 'work_date' => $locked->work_date->toDateString(), 'kind' => AttendanceCorrection::REVERSAL,
                'reason' => $reason, 'reverses_id' => $locked->id, 'created_by_staff_id' => $by->id,
            ]);
            $this->audit->record('attendance.correction_reversed', $by, $locked->staff, ['correction_id' => $locked->id, 'reason' => $reason]);

            return $reversal;
        });
    }
}
