<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An allowance (+) or a recovery (−) on one person's pay for a month, with a reason. Editable only while the run is a draft. */
class PayrollAdjustment extends Model
{
    protected $fillable = ['payroll_run_id', 'staff_id', 'amount', 'reason', 'created_by_staff_id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'created_by_staff_id');
    }
}
