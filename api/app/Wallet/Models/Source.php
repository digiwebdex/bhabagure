<?php

namespace App\Wallet\Models;

use Illuminate\Database\Eloquent\Builder;

/** Where wallet money came from or went to: a business, Personal or Other. Archived ones stay on old entries. */
class Source extends WalletModel
{
    protected $table = 'wallet_sources';

    protected $fillable = ['name', 'sort_order'];

    protected function casts(): array
    {
        return ['archived_at' => 'datetime'];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at')->orderBy('sort_order')->orderBy('name');
    }
}
