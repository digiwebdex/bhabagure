<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One month's payroll (docs/phase-7-hr-attendance-bonus-wallet.md §6): a draft figured live from attendance, then
 * finalised — the rules used and each person's figures frozen in payroll_items.
 */
class PayrollRun extends Model
{
    public const DRAFT = 'draft';

    public const FINALISED = 'finalised';

    protected $fillable = ['month', 'status', 'rules', 'created_by_staff_id', 'finalised_at', 'finalised_by_staff_id'];

    protected function casts(): array
    {
        return ['month' => 'date', 'rules' => 'array', 'finalised_at' => 'datetime'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(PayrollItem::class);
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(PayrollAdjustment::class);
    }

    public function finalisedBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'finalised_by_staff_id');
    }
}
