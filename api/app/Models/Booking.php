<?php

namespace App\Models;

use App\Enums\BookingStatus;
use App\Models\Concerns\OwnedByStaff;
use App\Support\WriteScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use LogicException;

/**
 * Money columns are DECIMAL(12,2) and come from the shared pricing service; a CHECK constraint keeps the total equal
 * to its parts. due_amount is generated. status changes only through BookingStateMachine; paid_amount and
 * payment_status only through LedgerService — both enforced below.
 */
class Booking extends Model
{
    use OwnedByStaff, SoftDeletes;

    protected $fillable = [
        'reference', 'customer_id', 'client_id', 'tour_package_id', 'departure_id', 'quotation_id', 'package_title_en', 'package_title_bn',
        'travel_start', 'travel_end', 'pax_count', 'room_type', 'hotel_category', 'list_price', 'price_grid', 'unit_price', 'subtotal_amount', 'single_supplement_amount',
        'addons_amount', 'discount_amount', 'vat_rate', 'vat_amount', 'total_amount', 'source', 'assigned_staff_id', 'created_by_staff_id',
        'cancellation_reason', 'internal_notes', 'locale', 'terms_accepted_at', 'terms_version', 'access_token_hash', 'idempotency_key',
        'is_custom',
    ];

    protected $hidden = ['access_token_hash', 'idempotency_key'];

    protected function casts(): array
    {
        return [
            'status' => BookingStatus::class,
            // A custom service (docs/custom-service-bookings.md): no package, the office's own items.
            'is_custom' => 'boolean',
            'travel_start' => 'date',
            'travel_end' => 'date',
            'list_price' => 'decimal:2',
            'price_grid' => 'array',
            'unit_price' => 'decimal:2',
            'subtotal_amount' => 'decimal:2',
            'single_supplement_amount' => 'decimal:2',
            'addons_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'vat_rate' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'due_amount' => 'decimal:2',
            'confirmed_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'terms_accepted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $booking) {
            if ($booking->exists && $booking->isDirty('status') && ! WriteScope::active(WriteScope::BOOKING_STATUS)) {
                throw new LogicException('A booking\'s status changes only through BookingStateMachine.');
            }
            if ($booking->isDirty(['paid_amount', 'payment_status']) && ! WriteScope::active(WriteScope::BOOKING_MONEY)) {
                throw new LogicException('paid_amount and payment_status are derived from the ledger by LedgerService.');
            }
        });
    }

    public static function hashAccessToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(TourPackage::class, 'tour_package_id');
    }

    public function departure(): BelongsTo
    {
        return $this->belongsTo(PackageDeparture::class, 'departure_id');
    }

    public static function seesAll(Staff $staff): bool
    {
        return $staff->can('bookings.view_all');
    }

    public static function seesOwn(Staff $staff): bool
    {
        return $staff->can('bookings.view_own');
    }

    /**
     * The list filters, shared by GET /admin/bookings and its sidebar badge so the two can never disagree.
     *
     * @param  array{status?: ?string, payment_status?: ?string, search?: ?string, owner?: ?string}  $filters
     */
    public function scopeFiltered(Builder $query, array $filters, Staff $staff): void
    {
        $query
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->where('status', $status))
            ->when($filters['payment_status'] ?? null, fn (Builder $q, string $status) => $q->where('payment_status', $status))
            ->when(($filters['owner'] ?? null) === 'mine', fn (Builder $q) => $q->where('assigned_staff_id', $staff->id))
            ->when(($filters['owner'] ?? null) === 'pool', fn (Builder $q) => $q->claimable())
            ->when($filters['search'] ?? null, fn (Builder $q, string $search) => $q->where(fn (Builder $inner) => $inner
                ->where('reference', 'like', "%{$search}%")
                ->orWhereHas('customer', fn (Builder $c) => $c->where('name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%"))));
    }

    /**
     * The pool: unowned bookings still at the inquiry stage. A website booking that was paid online and confirmed without
     * anyone's help isn't claimable — whoever clicked first would take its commission; an admin assigns it (audited).
     */
    public function scopeClaimable(Builder $query): void
    {
        $query->whereNull($this->qualifyColumn('assigned_staff_id'))->where($this->qualifyColumn('status'), BookingStatus::Inquiry);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BookingLine::class)->orderBy('sort_order');
    }

    public function travellers(): HasMany
    {
        return $this->hasMany(BookingTraveller::class)->orderBy('sort_order');
    }

    /** Airline tickets per traveller, voided ones included (docs/phase-6-customer-portal.md §8). */
    public function tickets(): HasMany
    {
        return $this->hasMany(BookingTicket::class)->orderBy('id');
    }

    public function paymentAttempts(): HasMany
    {
        return $this->hasMany(PaymentAttempt::class)->latest('id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class)->orderBy('occurred_at');
    }

    public function seatHolds(): HasMany
    {
        return $this->hasMany(SeatHold::class);
    }

    public function npsResponse(): HasOne
    {
        return $this->hasOne(NpsResponse::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class)->latest('id');
    }

    /** The current invoice: the latest that isn't void. */
    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class)->where('status', '!=', 'void')->latestOfMany();
    }
}
