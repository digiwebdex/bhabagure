<?php

namespace App\Services\Attendance;

use App\Models\AttendanceRule;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\Staff;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The holiday calendar (docs/holiday-calendar.md): the year's holidays as every staff member sees them, kept by
 * holidays.manage — the super admin by default. A holiday of several days is one date range, stored a day per row so
 * attendance and salary read it as before; holidays never overlap. Every change is audited.
 */
final class HolidayCalendar
{
    /** The longest one holiday can be, so a slip of the year can't turn months into holidays. */
    public const MAX_DAYS = 15;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * The year's holidays, a range once, by date. A range across New Year shows in both years, whole.
     *
     * @return list<array{id: string, starts_on: string, ends_on: string, days: int, name_en: string, name_bn: string}>
     */
    public static function year(int $year): array
    {
        $groups = Holiday::query()->whereYear('date', $year)->distinct()->pluck('group_id');

        return self::groups(Holiday::query()->whereIn('group_id', $groups)->orderBy('date')->get());
    }

    /**
     * Everything the calendar page shows one person for a year: the holidays, the weekly days off month by month, and
     * their own leave (nobody else's).
     *
     * @return array<string, mixed>
     */
    public static function view(int $year, Staff $me): array
    {
        $from = "{$year}-01-01";
        $to = "{$year}-12-31";

        return [
            'year' => $year,
            'holidays' => self::year($year),
            'holiday_days' => Holiday::query()->whereBetween('date', [$from, $to])->count(),
            // ISO weekdays (1 Monday … 7 Sunday) per month, as the attendance rules in force that month have them.
            'weekly_off_days' => array_map(fn (int $month) => AttendanceRule::forMonth(sprintf('%d-%02d', $year, $month))->weekly_off_days, range(1, 12)),
            'my_leave' => LeaveRequest::query()->where('staff_id', $me->id)->whereIn('status', [LeaveRequest::APPROVED, LeaveRequest::PENDING])
                ->overlapping($from, $to)->orderBy('starts_on')->get()
                ->map(fn (LeaveRequest $leave) => [
                    'starts_on' => $leave->starts_on->toDateString(),
                    'ends_on' => $leave->ends_on->toDateString(),
                    'status' => $leave->status,
                    'paid' => $leave->paid,
                ])->all(),
            'can_manage' => $me->can('holidays.manage'),
        ];
    }

    /**
     * A new holiday, or a change to one ($groupId): its days are replaced by the new range.
     *
     * @return array{id: string, starts_on: string, ends_on: string, days: int, name_en: string, name_bn: string}
     *
     * @throws AttendanceRefused
     */
    public function save(?string $groupId, string $from, string $to, string $nameEn, string $nameBn, Staff $by): array
    {
        $dates = self::dates($from, $to);
        $group = $groupId ?? (string) Str::uuid();

        return DB::transaction(function () use ($groupId, $group, $dates, $nameEn, $nameBn, $by) {
            if (Holiday::query()->whereIn('date', $dates)->where('group_id', '!=', $group)->exists()) {
                throw new AttendanceRefused('holiday_overlap');
            }
            $before = $groupId !== null ? self::groups(Holiday::query()->where('group_id', $groupId)->orderBy('date')->get())[0] ?? null : null;

            Holiday::query()->where('group_id', $group)->delete();
            try {
                foreach ($dates as $date) {
                    Holiday::query()->create(['group_id' => $group, 'date' => $date, 'name_en' => $nameEn, 'name_bn' => $nameBn, 'created_by_staff_id' => $by->id]);
                }
            } catch (UniqueConstraintViolationException) {
                // Someone saved a holiday on one of these days at the same moment.
                throw new AttendanceRefused('holiday_overlap');
            }
            $after = self::groups(Holiday::query()->where('group_id', $group)->orderBy('date')->get())[0];
            $this->audit->record('attendance.holiday_saved', $by, null, ['before' => $before, 'after' => $after]);

            return $after;
        });
    }

    public function remove(string $groupId, Staff $by): void
    {
        DB::transaction(function () use ($groupId, $by) {
            $holiday = self::groups(Holiday::query()->where('group_id', $groupId)->orderBy('date')->get())[0] ?? null;
            if ($holiday === null) {
                return;
            }
            Holiday::query()->where('group_id', $groupId)->delete();
            $this->audit->record('attendance.holiday_removed', $by, null, ['holiday' => $holiday]);
        });
    }

    /**
     * Copies chosen holidays of one year to the same days of another (29 February becomes the 28th). One that would fall
     * on a day already a holiday is skipped and named.
     *
     * @param  list<string>  $groupIds
     * @return array{copied: int, skipped: list<string>}
     */
    public function copy(int $fromYear, int $toYear, array $groupIds, Staff $by): array
    {
        $copied = 0;
        $skipped = [];
        foreach (self::year($fromYear) as $holiday) {
            if (! in_array($holiday['id'], $groupIds, true) || (int) substr($holiday['starts_on'], 0, 4) !== $fromYear) {
                continue;
            }
            $shift = $toYear - $fromYear;
            try {
                $this->save(null, CarbonImmutable::parse($holiday['starts_on'])->addYearsNoOverflow($shift)->toDateString(),
                    CarbonImmutable::parse($holiday['ends_on'])->addYearsNoOverflow($shift)->toDateString(), $holiday['name_en'], $holiday['name_bn'], $by);
                $copied++;
            } catch (AttendanceRefused) {
                $skipped[] = $holiday['name_en'];
            }
        }

        return ['copied' => $copied, 'skipped' => $skipped];
    }

    /**
     * Each day from $from to $to.
     *
     * @return list<string>
     *
     * @throws AttendanceRefused
     */
    private static function dates(string $from, string $to): array
    {
        $dates = array_map(fn ($day) => $day->toDateString(), iterator_to_array(CarbonPeriod::create($from, $to), false));
        if ($dates === [] || count($dates) > self::MAX_DAYS) {
            throw new AttendanceRefused('holiday_too_long');
        }

        return $dates;
    }

    /**
     * Days grouped into holidays.
     *
     * @param  Collection<int, Holiday>  $rows  by date
     * @return list<array{id: string, starts_on: string, ends_on: string, days: int, name_en: string, name_bn: string}>
     */
    private static function groups(Collection $rows): array
    {
        return $rows->groupBy('group_id')->map(fn (Collection $days, string $group) => [
            'id' => $group,
            'starts_on' => $days->first()->date->toDateString(),
            'ends_on' => $days->last()->date->toDateString(),
            'days' => $days->count(),
            'name_en' => $days->first()->name_en,
            'name_bn' => $days->first()->name_bn,
        ])->sortBy('starts_on')->values()->all();
    }
}
