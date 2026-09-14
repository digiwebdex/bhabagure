<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A one-time link that sets a staff password (docs/phase-7-hr-attendance-bonus-wallet.md §4.1): `invite` for a new
 * account, `reset` for a forgotten password. Only the token's hash is stored; a newer link cancels the older ones.
 */
class StaffInvitation extends Model
{
    public const INVITE = 'invite';

    public const RESET = 'reset';

    protected $fillable = ['staff_id', 'purpose', 'token_hash', 'expires_at', 'used_at', 'cancelled_at', 'created_by_staff_id'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /** Not used, not cancelled, not expired. */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('used_at')->whereNull('cancelled_at')->where('expires_at', '>', now());
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }
}
