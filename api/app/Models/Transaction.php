<?php

namespace App\Models;

use App\Enums\TransactionDirection;
use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The company cash book. Append-only: see Concerns\AppendOnly and the database triggers. */
class Transaction extends Model
{
    use AppendOnly;

    public const UPDATED_AT = null;

    protected $fillable = [
        'direction', 'amount', 'category', 'method', 'external_ref', 'booking_id', 'invoice_id', 'customer_id', 'client_id',
        'description', 'reference_label', 'evidence_path', 'occurred_at', 'recorded_by_staff_id', 'reverses_transaction_id',
    ];

    protected function casts(): array
    {
        return ['direction' => TransactionDirection::class, 'amount' => 'decimal:2', 'occurred_at' => 'datetime'];
    }

    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_transaction_id');
    }
}
