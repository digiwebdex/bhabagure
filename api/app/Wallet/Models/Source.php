<?php

namespace App\Wallet\Models;

use Illuminate\Database\Eloquent\Builder;

/**
 * Where money came from or went to: a business, Personal or Other — each person's own list (docs/personal-wallets.md).
 * Archived ones stay on old entries.
 */
class Source extends WalletModel
{
    /** Every wallet starts with these two; no business name is written into the code. */
    public const DEFAULTS = ['Personal' => 900, 'Other' => 950];

    protected $table = 'wallet_sources';

    protected $fillable = ['owner_staff_id', 'name', 'sort_order'];

    protected function casts(): array
    {
        return ['archived_at' => 'datetime', 'owner_staff_id' => 'integer'];
    }

    public function scopeOwnedBy(Builder $query, int $staffId): Builder
    {
        return $query->where('owner_staff_id', $staffId);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at')->orderBy('sort_order')->orderBy('name');
    }

    /** A new wallet's Personal and Other, made the first time its owner opens it. */
    public static function ensureDefaults(int $staffId): void
    {
        if (self::query()->ownedBy($staffId)->exists()) {
            return;
        }
        foreach (self::DEFAULTS as $name => $order) {
            self::query()->firstOrCreate(['owner_staff_id' => $staffId, 'name' => $name], ['sort_order' => $order]);
        }
    }
}
