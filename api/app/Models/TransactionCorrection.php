<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An edit or a delete on the cash book (docs/transaction-edits.md): the entry it cancelled, the reversing entry that did
 * it, and for an edit the corrected entry, with who, why and what changed. Append-only, like the books it explains.
 */
class TransactionCorrection extends Model
{
    use AppendOnly;

    public const UPDATED_AT = null;

    public const EDIT = 'edit';

    public const DELETE = 'delete';

    protected $fillable = ['kind', 'original_transaction_id', 'reversal_transaction_id', 'replacement_transaction_id', 'changes', 'reason', 'staff_id'];

    protected function casts(): array
    {
        return ['changes' => 'array'];
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    public function original(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'original_transaction_id');
    }

    public function replacement(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'replacement_transaction_id');
    }
}
