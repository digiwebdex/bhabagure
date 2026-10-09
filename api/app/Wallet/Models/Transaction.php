<?php

namespace App\Wallet\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Money in or out of the wallet. Append-only (wallet_transactions is in LedgerTables). Edit and Delete
 * (docs/wallet-edits.md) cancel an entry with a reversal the other way, on the entry's own date; an edit then records
 * the corrected entry, whose `replaces_id` points back at the one it corrects. Each entry is cancelled once at most.
 */
class Transaction extends WalletModel
{
    use AppendOnly;

    public const UPDATED_AT = null;

    public const IN = 'in';

    public const OUT = 'out';

    public const ENTRY = 'entry';

    public const DEAL_ADVANCE = 'deal_advance';

    public const DEAL_PAYMENT = 'deal_payment';

    public const REVERSAL = 'reversal';

    protected $table = 'wallet_transactions';

    protected $fillable = [
        'direction', 'amount', 'kind', 'source_id', 'source_name', 'deal_id', 'reference', 'occurred_on',
        'evidence_path', 'evidence_mime', 'evidence_name', 'reverses_id', 'replaces_id', 'reason', 'created_by_staff_id',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'occurred_on' => 'date', 'created_at' => 'datetime'];
    }

    /** One person's book (docs/personal-wallets.md): only a wallet's owner ever writes to it, so its maker is its owner. */
    public function scopeOwnedBy(Builder $query, int $staffId): Builder
    {
        return $query->where('created_by_staff_id', $staffId);
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_id');
    }

    /** The reversal that cancelled this entry: an edit or a delete (or, before 2026-10-09, Reverse). */
    public function reversedBy(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_id');
    }

    /** The entry this one corrects (an edit). */
    public function replaces(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaces_id');
    }

    /** The corrected entry recorded when this one was edited. */
    public function replacedBy(): HasOne
    {
        return $this->hasOne(self::class, 'replaces_id');
    }
}
