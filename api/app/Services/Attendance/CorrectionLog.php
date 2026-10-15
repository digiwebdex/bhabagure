<?php

namespace App\Services\Attendance;

use App\Models\AttendanceCorrection;
use App\Models\AttendanceRule;
use App\Models\Holiday;
use App\Models\Staff;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Days set by hand (docs/phase-7-hr-attendance-bonus-wallet.md §5.1): an in or out time, or "worked" for official duty
 * away from the device. Nothing is overwritten — the original punches stay beside the correction, and a mistaken
 * correction is undone by a reversal row. Nobody corrects their own attendance, except a super admin.
 */
final class CorrectionLog
{
    public const MAX_RANGE_DAYS = 31;

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

        $time = in_array($kind, [AttendanceCorrection::IN, AttendanceCorrection::OUT], true) ? $time : null;

        return DB::transaction(function () use ($staff, $date, $kind, $time, $reason, $by) {
            $correction = AttendanceCorrection::query()->create([
                'staff_id' => $staff->id, 'work_date' => $date, 'kind' => $kind,
                'time' => $time, 'reason' => $reason, 'created_by_staff_id' => $by->id,
            ]);
            $this->audit->record('attendance.corrected', $by, $staff, array_filter(['correction_id' => $correction->id, 'date' => $date, 'kind' => $kind, 'time' => $time, 'reason' => $reason]));

            return $correction;
        });
    }

    /**
     * Sets the status of several days at once (docs/staff-without-access.md §3): Present, Absent, paid or unpaid leave
     * from one date to another, at most 31 days. Holidays and weekly days off in the range are left alone — the status
     * applies to working days. Each day gets its own row, so one can be undone without the others.
     *
     * @return int the number of days set
     *
     * @throws AttendanceRefused
     */
    public function setDays(Staff $staff, string $from, string $until, string $status, string $reason, Staff $by): int
    {
        if ($from === $until) {
            $this->add($staff, $from, $status, null, $reason, $by);

            return 1;
        }
        if ($staff->is($by) && ! $by->isSuperAdmin()) {
            throw new AttendanceRefused('own_attendance');
        }
        $first = CarbonImmutable::parse($from, 'Asia/Dhaka');
        $last = CarbonImmutable::parse($until, 'Asia/Dhaka');
        if ($last->lessThan($first) || $first->diffInDays($last) >= self::MAX_RANGE_DAYS) {
            throw new AttendanceRefused('range_too_long');
        }
        if ($until > now('Asia/Dhaka')->toDateString()) {
            throw new AttendanceRefused('future_day');
        }

        $holidays = Holiday::query()->whereBetween('date', [$from, $until])->pluck('date')->map(fn ($date) => $date->toDateString())->all();
        $dates = [];
        for ($day = $first; $day->lessThanOrEqualTo($last); $day = $day->addDay()) {
            $offDays = AttendanceRule::forMonth($day->format('Y-m'))->weekly_off_days;
            if (! in_array($day->dayOfWeekIso, $offDays, true) && ! in_array($day->toDateString(), $holidays, true)) {
                $dates[] = $day->toDateString();
            }
        }
        if ($dates === []) {
            throw new AttendanceRefused('no_working_days');
        }

        DB::transaction(function () use ($staff, $dates, $status, $reason, $by, $from, $until) {
            $ids = [];
            foreach ($dates as $date) {
                $ids[] = AttendanceCorrection::query()->create([
                    'staff_id' => $staff->id, 'work_date' => $date, 'kind' => $status, 'reason' => $reason, 'created_by_staff_id' => $by->id,
                ])->id;
            }
            $this->audit->record('attendance.corrected', $by, $staff, ['correction_ids' => $ids, 'from' => $from, 'until' => $until, 'kind' => $status, 'reason' => $reason]);
        });

        return count($dates);
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
