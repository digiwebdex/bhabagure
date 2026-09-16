<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Someone with the money's interests ticking off a cash book entry they have checked (docs/phase-9-accounts.md §7).
 *
 * The entry itself is never touched: the cash book is append-only, and that is what makes it worth reading. An approval
 * is a separate record that says who looked, when, and what they wrote — and it can be taken back, which deletes the
 * record rather than editing anything.
 */
class TransactionApproval extends Model
{
    public $timestamps = false;

    protected $fillable = ['transaction_id', 'approved_by_staff_id', 'approved_at', 'note'];

    protected function casts(): array
    {
        return ['approved_at' => 'datetime'];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'approved_by_staff_id');
    }
}
