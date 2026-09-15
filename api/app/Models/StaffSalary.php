<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A base monthly salary from a month onward (docs/phase-7-hr-attendance-bonus-wallet.md §6). Append-only: a raise, or a
 * correction, is a new row with its reason; the salary for a month is the latest row whose effective month isn't after it.
 */
class StaffSalary extends Model
{
    use AppendOnly;

    public const UPDATED_AT = null;

    protected $fillable = ['staff_id', 'effective_month', 'amount', 'reason', 'created_by_staff_id'];

    protected function casts(): array
    {
        return ['effective_month' => 'date', 'amount' => 'decimal:2'];
    }

    /** The base salary in force for a month (YYYY-MM), or null when none has been set. */
    public static function forMonth(int $staffId, string $month): ?self
    {
        return self::query()->where('staff_id', $staffId)->where('effective_month', '<=', "{$month}-01")
            ->orderByDesc('effective_month')->orderByDesc('id')->first();
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'created_by_staff_id');
    }
}
