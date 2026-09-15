<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A request to take money out of a bonus account (docs/phase-7-hr-attendance-bonus-wallet.md §7): pending → approved or
 * rejected (or cancelled by its owner while pending) → paid. Every step is a bonus_withdrawal_events row.
 */
class BonusWithdrawal extends Model
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const CANCELLED = 'cancelled';

    public const PAID = 'paid';

    public const STATUSES = [self::PENDING, self::APPROVED, self::REJECTED, self::CANCELLED, self::PAID];

    /** Still holding money back from the available balance, and waiting for someone to act. */
    public const OPEN = [self::PENDING, self::APPROVED];

    protected $fillable = ['bonus_account_id', 'staff_id', 'amount', 'note', 'status'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'decided_at' => 'datetime', 'paid_at' => 'datetime'];
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', self::OPEN);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(BonusAccount::class, 'bonus_account_id');
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'decided_by_staff_id');
    }

    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'paid_by_staff_id');
    }

    public function cashTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'cash_transaction_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(BonusTransaction::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(BonusWithdrawalEvent::class);
    }
}
