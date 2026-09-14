<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A money account's opening balance, posted once against Opening balances equity (docs/phase-5-admin-core.md, question
 * 3). Append-only: a wrong figure is corrected with a balance adjustment entry in the cash book, never edited.
 */
class OpeningBalance extends Model
{
    use AppendOnly;

    public const UPDATED_AT = null;

    protected $fillable = ['account_id', 'amount', 'as_of', 'note', 'journal_entry_id', 'created_by_staff_id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'as_of' => 'date'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'created_by_staff_id');
    }
}
