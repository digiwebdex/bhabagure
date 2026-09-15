<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A person's pay for a finalised month (docs/phase-7-hr-attendance-bonus-wallet.md §6): the base, the day rate, the
 * attendance totals and day statuses as they stood at finalising, the deductions, adjustments and payable — and, once
 * paid, the company cash-out that paid it.
 */
class PayrollItem extends Model
{
    protected $fillable = ['payroll_run_id', 'staff_id', 'base', 'day_rate', 'totals', 'days', 'deductions', 'adjustments', 'payable', 'paid_at', 'paid_by_staff_id', 'transaction_id'];

    protected function casts(): array
    {
        return [
            'base' => 'decimal:2',
            'day_rate' => 'decimal:4',
            'totals' => 'array',
            'days' => 'array',
            'deductions' => 'decimal:2',
            'adjustments' => 'decimal:2',
            'payable' => 'decimal:2',
            'paid_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'paid_by_staff_id');
    }
}
