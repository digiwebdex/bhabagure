<?php

namespace App\Wallet\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Money in or out of the wallet. Append-only (wallet_transactions is in LedgerTables): a mistake is undone by a reversal
 * the other way, with a reason, and each entry can be reversed once.
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
        'evidence_path', 'evidence_mime', 'evidence_name', 'reverses_id', 'reason', 'created_by_staff_id',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'occurred_on' => 'date', 'created_at' => 'datetime'];
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_id');
    }

    public function reversedBy(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_id');
    }
}
