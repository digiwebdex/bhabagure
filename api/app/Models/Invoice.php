<?php

namespace App\Models;

use App\Exceptions\InvoiceFrozen;
use App\Support\WriteScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * Once issued, the billing snapshot is frozen: corrections are a void and reissue. Enforced on model events
 * here and on InvoiceItem (and by optional database triggers, DatabaseGuardTriggers).
 * balance_due is a stored generated column. status changes only in InvoiceIssuer; paid_amount and payment_status only in
 * LedgerService.
 */
class Invoice extends Model
{
    /** What was billed, to whom, for how much — never changes after the invoice leaves draft. */
    public const SNAPSHOT_COLUMNS = [
        'invoice_number', 'booking_id', 'customer_id', 'client_id', 'issued_on', 'due_on',
        'billed_name', 'billed_phone', 'billed_email', 'billed_address',
        'package_code', 'package_title_en', 'package_title_bn', 'travel_start', 'travel_end',
        'booking_reference', 'package_duration_days', 'package_duration_nights', 'includes_airfare', 'sales_agent_name', 'travellers',
        'pax_count', 'unit_price', 'subtotal_amount', 'discount_label', 'discount_amount',
        'vat_rate', 'vat_amount', 'total_amount',
    ];

    public const DRAFT = 'draft';

    public const ISSUED = 'issued';

    public const VOID = 'void';

    protected $fillable = [
        'invoice_number', 'booking_id', 'customer_id', 'client_id', 'issued_on', 'due_on',
        'billed_name', 'billed_phone', 'billed_email', 'billed_address', 'package_code', 'package_title_en', 'package_title_bn',
        'travel_start', 'travel_end', 'pax_count', 'unit_price', 'subtotal_amount', 'discount_label', 'discount_amount',
        'vat_rate', 'vat_amount', 'total_amount', 'status', 'share_token', 'issued_by_staff_id',
        'booking_reference', 'package_duration_days', 'package_duration_nights', 'includes_airfare', 'sales_agent_name', 'travellers',
    ];

    protected $hidden = ['share_token'];

    protected function casts(): array
    {
        return [
            'issued_on' => 'date',
            'due_on' => 'date',
            'travel_start' => 'date',
            'travel_end' => 'date',
            'unit_price' => 'decimal:2',
            'subtotal_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'vat_rate' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'balance_due' => 'decimal:2',
            'voided_at' => 'datetime',
            'includes_airfare' => 'boolean',
            'pax_count' => 'integer',
            // [{name, passportNumber, passportExpiry}] as printed. Passport data: encrypted at rest.
            'travellers' => 'encrypted:array',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $invoice) {
            if ($invoice->exists && $invoice->isDirty('status') && ! WriteScope::active(WriteScope::INVOICE_STATUS)) {
                throw new LogicException('An invoice is issued and voided only through InvoiceIssuer.');
            }
            if ($invoice->isDirty(['paid_amount', 'payment_status']) && ! WriteScope::active(WriteScope::BOOKING_MONEY)) {
                throw new LogicException('An invoice\'s paid amount and payment status are derived from the ledger by LedgerService.');
            }
        });

        static::updating(function (self $invoice) {
            if ($invoice->getOriginal('status') !== self::DRAFT && $invoice->isDirty(self::SNAPSHOT_COLUMNS)) {
                throw InvoiceFrozen::snapshot($invoice);
            }
        });

        static::deleting(function (self $invoice) {
            if ($invoice->getOriginal('status') !== self::DRAFT) {
                throw InvoiceFrozen::delete($invoice);
            }
        });
    }

    public function isIssued(): bool
    {
        return $this->status !== self::DRAFT;
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class)->orderBy('sort_order');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class)->orderBy('occurred_at');
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
