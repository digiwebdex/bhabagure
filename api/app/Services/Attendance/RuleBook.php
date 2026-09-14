<?php

namespace App\Services\Attendance;

use App\Models\AttendanceRule;
use App\Models\Holiday;
use App\Models\Staff;
use App\Services\AuditLogger;

/**
 * The attendance and salary rules and the holiday list (docs/phase-7-hr-attendance-bonus-wallet.md §6): this
 * installation's own settings, nothing hard-coded. Saving rules for a month makes a version that holds from that month
 * until a later one, so a change never rewrites months before it. Audited with the values before and after.
 */
final class RuleBook
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{duty_start: string, duty_end: string, grace_minutes: int, late_early_pay_percent: int, working_days_per_month: int, weekly_off_days: list<int>, single_punch_counts_as: string}  $values
     */
    public function save(string $month, array $values, Staff $by): AttendanceRule
    {
        $before = AttendanceRule::forMonth($month);
        $values['weekly_off_days'] = array_values(array_unique(array_map('intval', $values['weekly_off_days'])));
        sort($values['weekly_off_days']);

        $rule = AttendanceRule::query()->updateOrCreate(['effective_month' => "{$month}-01"], $values + ['created_by_staff_id' => $by->id]);
        $this->audit->record('attendance.rules_saved', $by, null, [
            'from_month' => $month,
            'before' => self::snapshot($before),
            'after' => self::snapshot($rule),
        ]);

        return $rule;
    }

    public function addHoliday(string $date, string $nameEn, string $nameBn, Staff $by): Holiday
    {
        $holiday = Holiday::query()->updateOrCreate(['date' => $date], ['name_en' => $nameEn, 'name_bn' => $nameBn, 'created_by_staff_id' => $by->id]);
        $this->audit->record('attendance.holiday_saved', $by, null, ['date' => $date, 'name_en' => $nameEn]);

        return $holiday;
    }

    public function removeHoliday(Holiday $holiday, Staff $by): void
    {
        $this->audit->record('attendance.holiday_removed', $by, null, ['date' => $holiday->date->toDateString(), 'name_en' => $holiday->name_en]);
        $holiday->delete();
    }

    /** @return array<string, mixed> */
    public static function snapshot(AttendanceRule $rule): array
    {
        return [
            'effective_month' => $rule->effective_month->format('Y-m'),
            'duty_start' => substr((string) $rule->duty_start, 0, 5),
            'duty_end' => substr((string) $rule->duty_end, 0, 5),
            'grace_minutes' => $rule->grace_minutes,
            'late_early_pay_percent' => $rule->late_early_pay_percent,
            'working_days_per_month' => $rule->working_days_per_month,
            'weekly_off_days' => $rule->weekly_off_days,
            'single_punch_counts_as' => $rule->single_punch_counts_as,
        ];
    }
}
