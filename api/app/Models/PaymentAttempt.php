<?php

namespace App\Models;

use App\Enums\PaymentAttemptStatus;
use App\Services\Ledger\LedgerService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentAttempt extends Model
{
    protected $fillable = [
        'booking_id', 'gateway', 'tran_id', 'amount', 'currency', 'method_hint', 'status', 'session_key', 'gateway_url', 'val_id',
        'bank_tran_id', 'card_type', 'risk_level', 'online_charge', 'gateway_amount', 'store_amount', 'gateway_fee', 'gateway_surcharge', 'failure_reason', 'expires_at',
        'settled_at', 'closed_at', 'gateway_response', 'return_to',
    ];

    protected $hidden = ['gateway_response', 'session_key'];

    protected function casts(): array
    {
        return [
            'status' => PaymentAttemptStatus::class,
            'amount' => 'decimal:2',
            'gateway_amount' => 'decimal:2',
            'store_amount' => 'decimal:2',
            'online_charge' => 'decimal:2',
            'gateway_fee' => 'decimal:2',
            'gateway_surcharge' => 'decimal:2',
            'risk_level' => 'integer',
            'expires_at' => 'datetime',
            'settled_at' => 'datetime',
            'closed_at' => 'datetime',
            'gateway_response' => 'array',
        ];
    }

    /** What the customer was shown and the gateway was asked to collect: booking amount + online payment charge. */
    public function expectedPaisa(): int
    {
        return LedgerService::paisa($this->amount) + LedgerService::paisa($this->online_charge);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
