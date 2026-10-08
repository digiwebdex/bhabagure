<?php

namespace App\Wallet\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A deal: its total, and the advance and payments received against it as wallet transactions. */
class Deal extends WalletModel
{
    protected $table = 'wallet_deals';

    protected $fillable = ['name', 'total', 'note', 'created_by_staff_id'];

    protected function casts(): array
    {
        return ['total' => 'decimal:2'];
    }

    /** One person's deals (docs/personal-wallets.md): made in their own wallet, so the maker is the owner. */
    public function scopeOwnedBy(Builder $query, int $staffId): Builder
    {
        return $query->where('created_by_staff_id', $staffId);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'deal_id');
    }
}
