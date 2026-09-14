<?php

namespace App\Models;

use App\Enums\TransactionDirection;
use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** The company cash book. Append-only: see Concerns\AppendOnly and the database triggers. */
class Transaction extends Model
{
    use AppendOnly;

    public const UPDATED_AT = null;

    protected $fillable = [
        'direction', 'amount', 'category', 'business_line', 'method', 'external_ref', 'booking_id', 'invoice_id', 'customer_id', 'client_id',
        'description', 'reference_label', 'evidence_path', 'occurred_at', 'recorded_by_staff_id', 'reverses_transaction_id',
    ];

    protected $hidden = ['evidence_path'];

    protected function casts(): array
    {
        return ['direction' => TransactionDirection::class, 'amount' => 'decimal:2', 'occurred_at' => 'datetime'];
    }

    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_transaction_id');
    }

    /** The entry that reversed this one, if any (an entry is reversed at most once). */
    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_transaction_id');
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'recorded_by_staff_id');
    }
}
