<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A customer's "I've paid" with its screenshot (docs/payment-requests.md): pending until staff approve it (the payment
 * is then recorded, `transaction_id`) or reject it with a reason the customer reads.
 */
class PaymentRequest extends Model
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const METHODS = ['bank_transfer', 'bkash'];

    protected $fillable = [
        'booking_id', 'customer_id', 'method', 'bank_name', 'account_number', 'amount', 'paid_on', 'reference', 'evidence_path', 'via',
        'status', 'approved_amount', 'transaction_id', 'decided_by_staff_id', 'decided_at', 'decision_note', 'ip',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'approved_amount' => 'decimal:2', 'paid_on' => 'date', 'decided_at' => 'datetime'];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'decided_by_staff_id');
    }
}
