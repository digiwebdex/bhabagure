<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A leave request (docs/phase-7-hr-attendance-bonus-wallet.md §5.1): filed by the staff member or recorded for them,
 * approved (paid or unpaid) or rejected by attendance.manage, cancelled by its owner while pending, or revoked after
 * approval. Its history is in leave_request_events, append-only.
 */
class LeaveRequest extends Model
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const CANCELLED = 'cancelled';

    public const REVOKED = 'revoked';

    public const STATUSES = [self::PENDING, self::APPROVED, self::REJECTED, self::CANCELLED, self::REVOKED];

    protected $fillable = ['staff_id', 'starts_on', 'ends_on', 'reason', 'status', 'paid', 'filed_by_staff_id', 'decided_by_staff_id', 'decided_at', 'decision_note'];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'paid' => 'boolean',
            'decided_at' => 'datetime',
        ];
    }

    /** Leave covering any day between $from and $to (inclusive, YYYY-MM-DD). */
    public function scopeOverlapping(Builder $query, string $from, string $to): void
    {
        $query->where('starts_on', '<=', $to)->where('ends_on', '>=', $from);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    public function filedBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'filed_by_staff_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'decided_by_staff_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(LeaveRequestEvent::class)->orderBy('id');
    }
}
