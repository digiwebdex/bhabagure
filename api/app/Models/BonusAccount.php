<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A staff member's bonus account (docs/phase-7-hr-attendance-bonus-wallet.md §7). It holds no balance of its own: the
 * balance is the sum of its ledger. The row exists to be locked while a credit, a reversal or a withdrawal is decided.
 */
class BonusAccount extends Model
{
    protected $fillable = ['staff_id'];

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(BonusTransaction::class);
    }

    public function withdrawals(): HasMany
    {
        return $this->hasMany(BonusWithdrawal::class);
    }
}
