<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A staff day set by hand, with a reason (docs/phase-7-hr-attendance-bonus-wallet.md §5.1): an in or out time, or
 * "worked" for official duty away from the device. Append-only: a mistake is undone by a reversal row pointing at it.
 */
class AttendanceCorrection extends Model
{
    use AppendOnly;

    public const UPDATED_AT = null;

    public const IN = 'in';

    public const OUT = 'out';

    public const WORKED = 'worked';

    public const REVERSAL = 'reversal';

    // A whole day's status set by hand (docs/staff-without-access.md): it overrides the device. "Worked" is Present.
    public const ABSENT = 'absent';

    public const LEAVE_PAID = 'leave_paid';

    public const LEAVE_UNPAID = 'leave_unpaid';

    /** The kinds that set a day's status; the latest in force wins. */
    public const DAY_STATUSES = [self::WORKED, self::ABSENT, self::LEAVE_PAID, self::LEAVE_UNPAID];

    public const KINDS = [self::IN, self::OUT, self::WORKED, self::ABSENT, self::LEAVE_PAID, self::LEAVE_UNPAID];

    protected $fillable = ['staff_id', 'work_date', 'kind', 'time', 'reason', 'reverses_id', 'created_by_staff_id'];

    protected function casts(): array
    {
        return ['work_date' => 'date'];
    }

    /** Corrections in force: not reversals, and not reversed. */
    public function scopeInForce(Builder $query): void
    {
        $query->where('kind', '!=', self::REVERSAL)
            ->whereNotExists(fn ($reversal) => $reversal->selectRaw('1')->from('attendance_corrections as reversals')->whereColumn('reversals.reverses_id', 'attendance_corrections.id'));
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
