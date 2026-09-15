<?php

namespace App\Services\Payroll;

use App\Enums\TransactionDirection;
use App\Events\CashEntryReversed;
use App\Http\Controllers\Api\V1\Admin\AttendanceController;
use App\Models\AttendanceRule;
use App\Models\PayrollAdjustment;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\Staff;
use App\Models\StaffSalary;
use App\Services\Attendance\DailyAttendance;
use App\Services\Attendance\RuleBook;
use App\Services\AuditLogger;
use App\Services\Ledger\LedgerService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Salary from attendance (docs/phase-7-hr-attendance-bonus-wallet.md §6).
 *
 *   day rate    = base ÷ working days a month (the rule)
 *   deductions  = day rate × (absent days + working days not employed + (100 % − late-or-early pay) × late-or-early days)
 *   payable     = base − deductions + adjustments, never below 0, rounded to the taka
 *
 * That is the design's formula written as deductions: the same figure whenever a month has exactly the configured
 * working days, and a full month always pays exactly the base. A draft is figured live; finalising freezes each
 * person's figures, their days and the rules used. Paying one records a cash-out under Salaries in the company books;
 * reversing that cash-out in the cash book leaves the month unpaid again.
 *
 * Nobody sets their own salary, adjusts their own pay or marks it paid: someone else with payroll.manage does, or the
 * super admin. People employed in a month without a base salary (the proprietor, say) aren't on its payroll.
 */
final class PayrollDesk
{
    /** What each frozen day keeps: enough to show the month again, not the raw punches. */
    private const FROZEN_DAY_KEYS = ['date', 'kind', 'status', 'in', 'out', 'corrected', 'leave', 'minutes'];

    public function __construct(
        private readonly DailyAttendance $attendance,
        private readonly AuditLogger $audit,
        private readonly LedgerService $ledger,
    ) {}

    /**
     * The month's sheet: frozen rows once finalised, live ones before.
     *
     * @return array{run: PayrollRun|null, rules: array<string, mixed>, rows: list<array<string, mixed>>}
     */
    public function sheet(string $month): array
    {
        $run = PayrollRun::query()->where('month', "{$month}-01")->first();
        if ($run?->status === PayrollRun::FINALISED) {
            $adjustments = $this->adjustmentsByStaff($run);
            $items = $run->items()->with(['staff.roles', 'staff.profile', 'paidBy', 'transaction'])->get()->sortBy(fn (PayrollItem $item) => $item->staff->name)->values();

            return ['run' => $run, 'rules' => $run->rules, 'rows' => $items->map(fn (PayrollItem $item) => $this->frozenRow($item, $run->rules, $adjustments->get($item->staff_id, collect())))->all()];
        }

        $live = $this->live($month, $run);

        return ['run' => $run, 'rules' => RuleBook::snapshot($live['rules']), 'rows' => array_map(fn (array $row) => Arr::except($row, 'days'), $live['rows'])];
    }

    /**
     * @param  array<string, int|float>  $totals  DailyAttendance totals
     * @return array{day_rate: float, deduction_days: float, deductions: float, adjustments: float, payable: int}
     */
    public static function figures(float $base, int $workingDays, int $latePayPercent, array $totals, float $adjustments): array
    {
        $dayRate = $base / max(1, $workingDays);
        $deductionDays = self::deductionDays($totals, $latePayPercent);
        $deductions = round($dayRate * $deductionDays, 2);

        return [
            'day_rate' => round($dayRate, 4),
            'deduction_days' => round($deductionDays, 2),
            'deductions' => $deductions,
            'adjustments' => round($adjustments, 2),
            'payable' => (int) max(0, round($base - $deductions + $adjustments)),
        ];
    }

    /**
     * The base salary in force for a month for each of these people; nobody without one is in the result.
     *
     * @param  list<int>  $staffIds
     * @return array<int, float>
     */
    public static function salariesFor(array $staffIds, string $month): array
    {
        // Oldest first, so each person's latest row in force is the one left standing.
        return StaffSalary::query()->whereIn('staff_id', $staffIds)->where('effective_month', '<=', "{$month}-01")
            ->orderBy('effective_month')->orderBy('id')->get(['staff_id', 'amount'])
            ->mapWithKeys(fn (StaffSalary $salary) => [$salary->staff_id => (float) $salary->amount])->all();
    }

    /** @throws PayrollRefused */
    public function setSalary(Staff $person, string $month, float $amount, string $reason, Staff $by): StaffSalary
    {
        self::ensureNotOwn($person->id, $by);
        if ($this->finalisedFrom($month)) {
            throw new PayrollRefused('month_finalised');
        }

        return DB::transaction(function () use ($person, $month, $amount, $reason, $by) {
            $previous = StaffSalary::forMonth($person->id, $month);
            $salary = StaffSalary::query()->create(['staff_id' => $person->id, 'effective_month' => "{$month}-01", 'amount' => $amount, 'reason' => $reason, 'created_by_staff_id' => $by->id]);
            $this->audit->record('payroll.salary_set', $by, $person, ['from_month' => $month, 'amount' => $amount, 'previous' => $previous?->amount, 'reason' => $reason]);

            return $salary;
        });
    }

    /** @throws PayrollRefused */
    public function addAdjustment(string $month, Staff $person, float $amount, string $reason, Staff $by): PayrollAdjustment
    {
        self::ensureNotOwn($person->id, $by);
        if (! AttendanceController::employedIn($month)->whereKey($person->id)->exists()) {
            throw new PayrollRefused('not_on_sheet');
        }

        return DB::transaction(function () use ($month, $person, $amount, $reason, $by) {
            $run = $this->draftRun($month, $by);
            $adjustment = PayrollAdjustment::query()->create(['payroll_run_id' => $run->id, 'staff_id' => $person->id, 'amount' => $amount, 'reason' => $reason, 'created_by_staff_id' => $by->id]);
            $this->audit->record('payroll.adjustment_added', $by, $person, ['month' => $month, 'amount' => $amount, 'reason' => $reason]);

            return $adjustment;
        });
    }

    /** @throws PayrollRefused */
    public function removeAdjustment(PayrollAdjustment $adjustment, Staff $by): void
    {
        self::ensureNotOwn($adjustment->staff_id, $by);

        DB::transaction(function () use ($adjustment, $by) {
            $run = PayrollRun::query()->whereKey($adjustment->payroll_run_id)->lockForUpdate()->firstOrFail();
            if ($run->status !== PayrollRun::DRAFT) {
                throw new PayrollRefused('month_finalised');
            }
            $this->audit->record('payroll.adjustment_removed', $by, $adjustment->staff()->first(), ['month' => $run->month->format('Y-m'), 'amount' => $adjustment->amount, 'reason' => $adjustment->reason]);
            $adjustment->delete();
        });
    }

    /**
     * Freezes a month that has ended: everyone on it with a base salary gets an item with their figures and days.
     *
     * @throws PayrollRefused
     */
    public function finalise(string $month, Staff $by): PayrollRun
    {
        if ($month >= now('Asia/Dhaka')->format('Y-m')) {
            throw new PayrollRefused('month_not_over');
        }

        return DB::transaction(function () use ($month, $by) {
            $run = $this->draftRun($month, $by);
            $live = $this->live($month, $run);
            $rows = array_values(array_filter($live['rows'], fn (array $row) => $row['figures'] !== null));
            if ($rows === []) {
                throw new PayrollRefused('no_salaries');
            }

            foreach ($rows as $row) {
                PayrollItem::query()->create([
                    'payroll_run_id' => $run->id,
                    'staff_id' => $row['staff']['id'],
                    'base' => $row['base'],
                    'day_rate' => $row['figures']['day_rate'],
                    'totals' => $row['totals'],
                    'days' => array_map(fn (array $day) => Arr::only($day, self::FROZEN_DAY_KEYS), $row['days']),
                    'deductions' => $row['figures']['deductions'],
                    'adjustments' => $row['figures']['adjustments'],
                    'payable' => $row['figures']['payable'],
                ]);
            }
            $run->forceFill(['status' => PayrollRun::FINALISED, 'rules' => RuleBook::snapshot($live['rules']), 'finalised_at' => now(), 'finalised_by_staff_id' => $by->id])->save();
            $this->audit->record('payroll.finalised', $by, null, [
                'month' => $month,
                'people' => count($rows),
                'without_salary' => count($live['rows']) - count($rows),
                'total' => array_sum(array_map(fn (array $row) => $row['figures']['payable'], $rows)),
            ]);

            return $run;
        });
    }

    /** Back to a draft, before anyone is paid, by the super admin, with a reason. @throws PayrollRefused */
    public function reopen(PayrollRun $run, string $reason, Staff $by): PayrollRun
    {
        if (! $by->isSuperAdmin()) {
            throw new PayrollRefused('super_admin_only');
        }

        return DB::transaction(function () use ($run, $reason, $by) {
            $locked = PayrollRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== PayrollRun::FINALISED) {
                throw new PayrollRefused('not_finalised');
            }
            if ($locked->items()->whereNotNull('paid_at')->exists()) {
                throw new PayrollRefused('already_paid');
            }
            $locked->items()->delete();
            $locked->forceFill(['status' => PayrollRun::DRAFT, 'rules' => null, 'finalised_at' => null, 'finalised_by_staff_id' => null])->save();
            $this->audit->record('payroll.reopened', $by, null, ['month' => $locked->month->format('Y-m'), 'reason' => $reason]);

            return $locked;
        });
    }

    /**
     * Pays one person's salary: a cash-out under Salaries from the money account the method names, with its receipt.
     *
     * @param  array{method: string, reference: ?string, occurred_on: ?string}  $payment
     *
     * @throws PayrollRefused
     */
    public function markPaid(PayrollItem $item, array $payment, ?string $evidencePath, Staff $by): PayrollItem
    {
        self::ensureNotOwn($item->staff_id, $by);

        return DB::transaction(function () use ($item, $payment, $evidencePath, $by) {
            $locked = PayrollItem::query()->whereKey($item->id)->lockForUpdate()->with(['staff', 'run'])->firstOrFail();
            if ($locked->paid_at !== null) {
                throw new PayrollRefused('already_paid');
            }
            if ($locked->run->status !== PayrollRun::FINALISED) {
                throw new PayrollRefused('not_finalised');
            }
            if ((float) $locked->payable <= 0) {
                throw new PayrollRefused('nothing_to_pay');
            }
            $month = $locked->run->month->format('Y-m');
            $entry = $this->ledger->recordManualEntry(
                TransactionDirection::Out, $locked->payable, $payment['method'], 'salaries', 'office',
                "Salary {$month} · {$locked->staff->name} ({$locked->staff->employee_code})", $by, $payment['reference'] ?: null, $evidencePath,
                // As the cash book records a past day: noon in Dhaka.
                filled($payment['occurred_on']) && $payment['occurred_on'] !== now('Asia/Dhaka')->toDateString()
                    ? CarbonImmutable::parse("{$payment['occurred_on']} 12:00", 'Asia/Dhaka')->utc() : null,
            );
            $locked->forceFill(['paid_at' => now(), 'paid_by_staff_id' => $by->id, 'transaction_id' => $entry->id])->save();
            $this->audit->record('payroll.paid', $by, $locked->staff, ['month' => $month, 'amount' => $locked->payable, 'transaction_id' => $entry->id]);

            return $locked;
        });
    }

    /** The cash book reversed a salary cash-out: that month's pay is unpaid again, and can be paid afresh. */
    public function onCashEntryReversed(CashEntryReversed $event): void
    {
        $item = PayrollItem::query()->where('transaction_id', $event->entry->id)->lockForUpdate()->with(['staff', 'run'])->first();
        if ($item === null) {
            return;
        }
        $item->forceFill(['paid_at' => null, 'paid_by_staff_id' => null, 'transaction_id' => null])->save();
        $this->audit->record('payroll.payment_reversed', $event->by, $item->staff, [
            'month' => $item->run->month->format('Y-m'), 'amount' => $item->payable, 'transaction_id' => $event->entry->id,
            'reversal_id' => $event->reversal->id, 'reason' => $event->reason,
        ]);
    }

    /** @return array<string, mixed> */
    public static function adjustmentRow(PayrollAdjustment $adjustment): array
    {
        return [
            'id' => $adjustment->id,
            'amount' => (float) $adjustment->amount,
            'reason' => $adjustment->reason,
            'by' => $adjustment->relationLoaded('createdBy') ? $adjustment->createdBy?->name : null,
        ];
    }

    /** @param array<string, int|float> $totals */
    private static function deductionDays(array $totals, int $latePayPercent): float
    {
        return $totals['absent_days'] + $totals['not_employed_working_days'] + (100 - $latePayPercent) / 100 * $totals['reduced_days'];
    }

    /**
     * Everyone employed in the month, figured from attendance as it stands, with their computed days under 'days'.
     *
     * @return array{rules: AttendanceRule, rows: list<array<string, mixed>>}
     */
    private function live(string $month, ?PayrollRun $run): array
    {
        $rules = AttendanceRule::forMonth($month);
        $staff = AttendanceController::employedIn($month)->with(['roles', 'profile'])->orderBy('name')->get();
        $computed = $this->attendance->month($month, $staff);
        $salaries = self::salariesFor($staff->modelKeys(), $month);
        $adjustments = $run ? $this->adjustmentsByStaff($run) : collect();

        return [
            'rules' => $rules,
            'rows' => $staff->map(function (Staff $person) use ($rules, $computed, $salaries, $adjustments) {
                $base = $salaries[$person->id] ?? null;
                $own = $adjustments->get($person->id, collect());

                return [
                    'staff' => AttendanceController::person($person),
                    'totals' => $computed[$person->id]['totals'],
                    'days' => $computed[$person->id]['days'],
                    'base' => $base,
                    'figures' => $base === null ? null : self::figures($base, $rules->working_days_per_month, $rules->late_early_pay_percent, $computed[$person->id]['totals'], (float) $own->sum('amount')),
                    'adjustments' => $own->map(fn (PayrollAdjustment $adjustment) => self::adjustmentRow($adjustment))->values()->all(),
                    'item_id' => null,
                    'paid_at' => null,
                    'paid_by' => null,
                    'transaction_id' => null,
                    'method' => null,
                    'reference' => null,
                ];
            })->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $rules  the frozen snapshot
     * @param  Collection<int, PayrollAdjustment>  $adjustments
     * @return array<string, mixed>
     */
    private function frozenRow(PayrollItem $item, array $rules, Collection $adjustments): array
    {
        return [
            'staff' => AttendanceController::person($item->staff),
            'totals' => $item->totals,
            'base' => (float) $item->base,
            'figures' => [
                'day_rate' => (float) $item->day_rate,
                'deduction_days' => round(self::deductionDays($item->totals, (int) ($rules['late_early_pay_percent'] ?? 50)), 2),
                'deductions' => (float) $item->deductions,
                'adjustments' => (float) $item->adjustments,
                'payable' => (int) round((float) $item->payable),
            ],
            'adjustments' => $adjustments->map(fn (PayrollAdjustment $adjustment) => self::adjustmentRow($adjustment))->values()->all(),
            'item_id' => $item->id,
            'paid_at' => $item->paid_at?->toIso8601String(),
            'paid_by' => $item->paidBy?->name,
            'transaction_id' => $item->transaction_id,
            'method' => $item->transaction?->method,
            'reference' => $item->transaction?->reference_label,
        ];
    }

    /** @return Collection<int, Collection<int, PayrollAdjustment>> */
    private function adjustmentsByStaff(PayrollRun $run): Collection
    {
        return $run->adjustments()->with('createdBy')->orderBy('id')->get()->groupBy('staff_id');
    }

    /**
     * The month's run, locked, created as a draft when it doesn't exist yet. Two people starting the same month at once
     * deadlock on the insert; PayrollController retries the loser, which then finds the run.
     *
     * @throws PayrollRefused
     */
    private function draftRun(string $month, Staff $by): PayrollRun
    {
        $run = PayrollRun::query()->where('month', "{$month}-01")->lockForUpdate()->first()
            ?? PayrollRun::query()->create(['month' => "{$month}-01", 'status' => PayrollRun::DRAFT, 'created_by_staff_id' => $by->id]);
        if ($run->status !== PayrollRun::DRAFT) {
            throw new PayrollRefused('month_finalised');
        }

        return $run;
    }

    /** A finalised run for this month or a later one: a salary change can't reach back into it. */
    private function finalisedFrom(string $month): bool
    {
        return PayrollRun::query()->where('status', PayrollRun::FINALISED)->where('month', '>=', "{$month}-01")->exists();
    }

    /** @throws PayrollRefused */
    private static function ensureNotOwn(int $staffId, Staff $by): void
    {
        if ($staffId === $by->id && ! $by->isSuperAdmin()) {
            throw new PayrollRefused('own_pay');
        }
    }
}
