<?php

namespace App\Services\Attendance;

use App\Models\AttendanceCorrection;
use App\Models\AttendanceDeviceUser;
use App\Models\AttendancePunch;
use App\Models\AttendanceRule;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\Staff;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Each staff member's days in a month, computed — never stored as editable state — from punches, corrections, approved
 * leave, holidays, weekly off days and employment dates (docs/phase-7-hr-attendance-bonus-wallet.md §5.1, §6). All in
 * office time (Asia/Dhaka). The salary run (§6) freezes what this returns when a month is finalised.
 *
 * In is the first punch; out is the last, unless it is within SAME_ARRIVAL_MINUTES of the first (a second tap on arrival).
 * Late: in after duty start plus grace. Early: out before duty end. Both on one day is one late-or-early day.
 */
final class DailyAttendance
{
    public const SAME_ARRIVAL_MINUTES = 10;

    public const FULL = 'full';

    public const LATE = 'late';

    public const EARLY = 'early';

    public const LATE_EARLY = 'late_early';

    public const SINGLE_PUNCH = 'single_punch';

    public const LEAVE = 'leave';

    public const ABSENT = 'absent';

    public const OFF = 'off';

    public const HOLIDAY = 'holiday';

    public const WORKED_OFF = 'worked_off';

    public const NOT_EMPLOYED = 'not_employed';

    /** Today, once someone has punched in and not yet out. */
    public const IN_PROGRESS = 'in_progress';

    /** Days still to come, and today before a first punch. */
    public const UPCOMING = 'upcoming';

    /**
     * @param  iterable<Staff>  $staff  with `profile` loaded
     * @return array<int, array{days: list<array<string, mixed>>, totals: array<string, int|float>}> keyed by staff id
     */
    public function month(string $month, iterable $staff): array
    {
        $first = CarbonImmutable::parse("{$month}-01", 'Asia/Dhaka');
        $from = $first->toDateString();
        $to = $first->endOfMonth()->toDateString();
        $today = now('Asia/Dhaka')->toDateString();
        $rules = AttendanceRule::forMonth($month);
        $staff = collect($staff);
        $ids = $staff->pluck('id')->all();

        $holidays = Holiday::query()->whereBetween('date', [$from, $to])->get()->keyBy(fn (Holiday $holiday) => $holiday->date->toDateString());
        $punches = $this->punches($ids, $from, $to);
        $corrections = AttendanceCorrection::query()->inForce()->whereIn('staff_id', $ids)->whereBetween('work_date', [$from, $to])->orderBy('id')->get()
            ->groupBy(fn (AttendanceCorrection $correction) => $correction->staff_id.'|'.$correction->work_date->toDateString());
        $leave = LeaveRequest::query()->where('status', LeaveRequest::APPROVED)->whereIn('staff_id', $ids)->overlapping($from, $to)->get()->groupBy('staff_id');

        $result = [];
        foreach ($staff as $person) {
            $days = [];
            $joined = $person->profile?->joined_on?->toDateString();
            $left = $person->profile?->left_on?->toDateString();

            for ($day = $first; $day->toDateString() <= $to; $day = $day->addDay()) {
                $date = $day->toDateString();
                $holiday = $holidays->get($date);
                $kind = $holiday !== null ? self::HOLIDAY : (in_array($day->dayOfWeekIso, $rules->weekly_off_days, true) ? self::OFF : 'working');
                $times = $punches[$person->id][$date] ?? [];
                $fixes = $corrections->get($person->id.'|'.$date, collect());
                $onLeave = ($leave->get($person->id) ?? collect())->first(fn (LeaveRequest $request) => $request->starts_on->toDateString() <= $date && $request->ends_on->toDateString() >= $date);

                $in = $fixes->where('kind', AttendanceCorrection::IN)->last()?->time ?? ($times[0] ?? null);
                $out = $fixes->where('kind', AttendanceCorrection::OUT)->last()?->time;
                if ($out === null && $in !== null && $times !== [] && self::minutes(end($times)) - self::minutes($in) >= self::SAME_ARRIVAL_MINUTES) {
                    $out = end($times);
                }
                $worked = $fixes->contains('kind', AttendanceCorrection::WORKED);

                $status = match (true) {
                    ($joined !== null && $date < $joined) || ($left !== null && $date > $left) => self::NOT_EMPLOYED,
                    $date > $today => self::UPCOMING,
                    $kind !== 'working' => $in !== null || $worked ? self::WORKED_OFF : $kind,
                    $worked => self::FULL,
                    $onLeave !== null => self::LEAVE,
                    $in === null => $date === $today ? self::UPCOMING : self::ABSENT,
                    $out === null => $date === $today ? self::IN_PROGRESS : self::SINGLE_PUNCH,
                    default => self::judge($in, $out, $rules),
                };

                $days[] = [
                    'date' => $date,
                    'weekday' => $day->dayOfWeekIso,
                    'kind' => $kind,
                    'holiday' => $holiday ? ['en' => $holiday->name_en, 'bn' => $holiday->name_bn] : null,
                    'status' => $status,
                    'in' => $in === null ? null : substr($in, 0, 5),
                    'out' => $out === null ? null : substr($out, 0, 5),
                    'punches' => array_map(fn (string $time) => substr($time, 0, 5), $times),
                    'corrected' => $fixes->isNotEmpty(),
                    'leave' => $status === self::LEAVE ? ['id' => $onLeave->id, 'paid' => $onLeave->paid] : null,
                    'minutes' => $in !== null && $out !== null ? max(0, self::minutes($out) - self::minutes($in)) : 0,
                ];
            }

            $result[$person->id] = ['days' => $days, 'totals' => self::totals($days, $rules)];
        }

        return $result;
    }

    /**
     * Counts for the month, and the three figures salary uses: reduced (late-or-early) days, absent days and working days
     * outside employment. A single-punch day counts as the rules say.
     *
     * @param  list<array<string, mixed>>  $days
     * @return array<string, int|float>
     */
    public static function totals(array $days, AttendanceRule $rules): array
    {
        $count = fn (string $status) => count(array_filter($days, fn (array $day) => $day['status'] === $status));
        $single = $count(self::SINGLE_PUNCH);

        return [
            'working_days' => count(array_filter($days, fn (array $day) => $day['kind'] === 'working' && $day['status'] !== self::NOT_EMPLOYED)),
            'full' => $count(self::FULL),
            'late' => $count(self::LATE),
            'early' => $count(self::EARLY),
            'late_early' => $count(self::LATE_EARLY),
            'single_punch' => $single,
            'leave_paid' => count(array_filter($days, fn (array $day) => $day['status'] === self::LEAVE && $day['leave']['paid'])),
            'leave_unpaid' => count(array_filter($days, fn (array $day) => $day['status'] === self::LEAVE && ! $day['leave']['paid'])),
            'absent' => $count(self::ABSENT),
            'off' => $count(self::OFF),
            'holiday' => $count(self::HOLIDAY),
            'worked_off' => $count(self::WORKED_OFF),
            'upcoming' => $count(self::UPCOMING) + $count(self::IN_PROGRESS),
            'not_employed_working_days' => count(array_filter($days, fn (array $day) => $day['kind'] === 'working' && $day['status'] === self::NOT_EMPLOYED)),
            'reduced_days' => $count(self::LATE) + $count(self::EARLY) + $count(self::LATE_EARLY) + ($rules->single_punch_counts_as === 'late_early' ? $single : 0),
            'absent_days' => $count(self::ABSENT) + ($rules->single_punch_counts_as === 'absent' ? $single : 0),
            'hours' => round(array_sum(array_column($days, 'minutes')) / 60, 1),
        ];
    }

    private static function judge(string $in, string $out, AttendanceRule $rules): string
    {
        $late = self::minutes($in) > self::minutes($rules->duty_start) + $rules->grace_minutes;
        $early = self::minutes($out) < self::minutes($rules->duty_end);

        return match (true) {
            $late && $early => self::LATE_EARLY,
            $late => self::LATE,
            $early => self::EARLY,
            default => self::FULL,
        };
    }

    /** 'HH:MM' or 'HH:MM:SS' → minutes since midnight; seconds are dropped, so 11:00:59 is 11:00. */
    public static function minutes(string $time): int
    {
        return (int) substr($time, 0, 2) * 60 + (int) substr($time, 3, 2);
    }

    /**
     * Punch times per staff member and date, through the device-user mapping.
     *
     * @param  list<int>  $staffIds
     * @return array<int, array<string, list<string>>>
     */
    private function punches(array $staffIds, string $from, string $to): array
    {
        $mappings = AttendanceDeviceUser::query()->whereIn('staff_id', $staffIds)->whereNull('ignored_at')->get(['attendance_device_id', 'device_user_id', 'staff_id']);
        if ($mappings->isEmpty()) {
            return [];
        }
        $owner = $mappings->mapWithKeys(fn (AttendanceDeviceUser $user) => [$user->attendance_device_id.'|'.$user->device_user_id => $user->staff_id]);

        $result = [];
        AttendancePunch::query()->whereBetween('work_date', [$from, $to])
            ->where(function (Builder $query) use ($mappings) {
                foreach ($mappings->groupBy('attendance_device_id') as $deviceId => $users) {
                    $query->orWhere(fn (Builder $device) => $device->where('attendance_device_id', $deviceId)->whereIn('device_user_id', $users->pluck('device_user_id')->all()));
                }
            })
            ->orderBy('punched_at')->get(['attendance_device_id', 'device_user_id', 'punched_at', 'work_date'])
            ->each(function (AttendancePunch $punch) use (&$result, $owner) {
                $staffId = $owner[$punch->attendance_device_id.'|'.$punch->device_user_id];
                $result[$staffId][(string) $punch->work_date][] = substr((string) $punch->punched_at, 11, 8);
            });

        return $result;
    }
}
