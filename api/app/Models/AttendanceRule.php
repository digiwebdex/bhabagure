<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The attendance and salary rules from a month onward (docs/phase-7-hr-attendance-bonus-wallet.md §6): the design's
 * duty hours, grace, late-or-early pay and working days, plus weekly off days and what a single-punch day counts as.
 * The rules for a month are the latest version whose effective month isn't after it.
 */
class AttendanceRule extends Model
{
    public const SINGLE_PUNCH = ['late_early', 'absent', 'full'];

    protected $fillable = [
        'effective_month', 'duty_start', 'duty_end', 'grace_minutes', 'late_early_pay_percent', 'working_days_per_month',
        'weekly_off_days', 'single_punch_counts_as', 'created_by_staff_id',
    ];

    protected function casts(): array
    {
        return [
            'effective_month' => 'date',
            'grace_minutes' => 'integer',
            'late_early_pay_percent' => 'integer',
            'working_days_per_month' => 'integer',
            'weekly_off_days' => 'array',
        ];
    }

    /** The version in force for a month (YYYY-MM). */
    public static function forMonth(string $month): self
    {
        return self::query()->where('effective_month', '<=', "{$month}-01")->orderByDesc('effective_month')->firstOrFail();
    }
}
