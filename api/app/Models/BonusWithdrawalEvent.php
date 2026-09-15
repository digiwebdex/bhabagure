<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One step of a bonus withdrawal: requested, cancelled, approved, rejected, paid or payment reversed, with who and why. Append-only. */
class BonusWithdrawalEvent extends Model
{
    use AppendOnly;

    public const UPDATED_AT = null;

    protected $fillable = ['bonus_withdrawal_id', 'action', 'actor_staff_id', 'note'];

    public function withdrawal(): BelongsTo
    {
        return $this->belongsTo(BonusWithdrawal::class, 'bonus_withdrawal_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'actor_staff_id');
    }
}
