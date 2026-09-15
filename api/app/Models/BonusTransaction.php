<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One entry in a bonus ledger (docs/phase-7-hr-attendance-bonus-wallet.md §7): a credit or a debit. Append-only: a
 * mistake is undone by a reversal entry in the opposite direction, and each entry can be reversed once.
 */
class BonusTransaction extends Model
{
    use AppendOnly;

    public const UPDATED_AT = null;

    public const CREDIT = 'credit';

    public const DEBIT = 'debit';

    /** A booking's commission, credited and reversed by CommissionDesk. */
    public const COMMISSION = 'commission';

    /** The month-end volume bonus, one per person and month (`period`). */
    public const VOLUME = 'volume_bonus';

    public const MANUAL = 'manual';

    public const WITHDRAWAL = 'withdrawal';

    public const REVERSAL = 'reversal';

    protected $fillable = ['bonus_account_id', 'direction', 'amount', 'kind', 'booking_id', 'bonus_withdrawal_id', 'rule', 'period', 'reverses_id', 'reason', 'created_by_staff_id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'rule' => 'array', 'created_at' => 'datetime'];
    }

    /** The amount as it moves the balance: a credit adds, a debit takes away. */
    public function signedAmount(): float
    {
        return $this->direction === self::CREDIT ? (float) $this->amount : -(float) $this->amount;
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(BonusAccount::class, 'bonus_account_id');
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function withdrawal(): BelongsTo
    {
        return $this->belongsTo(BonusWithdrawal::class, 'bonus_withdrawal_id');
    }

    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_id');
    }

    public function reversedBy(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'created_by_staff_id');
    }
}
