<?php

namespace App\Wallet\Models;

use Illuminate\Database\Eloquent\Builder;

/** A saved reference for one direction, filled into the form with a click; each person's own list. */
class ReferencePreset extends WalletModel
{
    public const UPDATED_AT = null;

    protected $table = 'wallet_reference_presets';

    protected $fillable = ['owner_staff_id', 'direction', 'label'];

    public function scopeOwnedBy(Builder $query, int $staffId): Builder
    {
        return $query->where('owner_staff_id', $staffId);
    }
}
