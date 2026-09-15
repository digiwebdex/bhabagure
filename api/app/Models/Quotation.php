<?php

namespace App\Models;

use App\Models\Concerns\OwnedByStaff;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A priced offer for a customer (docs/phase-5-admin-core.md §4.5). Its lines and amounts are frozen when saved and
 * honoured until the end of valid_until in Dhaka; "expired" is derived from that date and never stored. Only
 * App\Services\Quotations\QuotationService changes a quotation.
 */
class Quotation extends Model
{
    use OwnedByStaff, SoftDeletes;

    public const DRAFT = 'draft';

    public const SENT = 'sent';

    public const ACCEPTED = 'accepted';

    public const DECLINED = 'declined';

    public const WITHDRAWN = 'withdrawn';

    public const CONVERTED = 'converted';

    public const VALIDITY_DAYS = [3, 7, 14];

    /** The status filter and badge values: the stored statuses plus the derived "expired" and "expiring". */
    public const FILTERS = ['draft', 'sent', 'accepted', 'declined', 'withdrawn', 'converted', 'expired', 'expiring'];

    protected $fillable = [
        'number', 'revision_of_id', 'customer_id', 'tour_package_id', 'departure_id', 'package_title_en', 'package_title_bn', 'package_code',
        'duration_days', 'duration_nights', 'includes_airfare', 'travel_date', 'pax_count', 'room_type', 'hotel_category', 'list_price', 'price_grid', 'unit_price',
        'subtotal_amount', 'single_supplement_amount', 'addons_amount', 'discount_amount', 'vat_rate', 'vat_amount', 'total_amount',
        'validity_days', 'valid_until', 'status', 'locale', 'notes', 'share_token', 'assigned_staff_id', 'created_by_staff_id',
    ];

    protected $hidden = ['share_token'];

    protected function casts(): array
    {
        return [
            'travel_date' => 'date', 'valid_until' => 'date', 'includes_airfare' => 'boolean',
            'list_price' => 'decimal:2', 'price_grid' => 'array', 'unit_price' => 'decimal:2', 'subtotal_amount' => 'decimal:2', 'single_supplement_amount' => 'decimal:2',
            'addons_amount' => 'decimal:2', 'discount_amount' => 'decimal:2', 'vat_rate' => 'decimal:2', 'vat_amount' => 'decimal:2', 'total_amount' => 'decimal:2',
            'sent_at' => 'datetime', 'viewed_at' => 'datetime', 'expiry_reminded_at' => 'datetime', 'accepted_at' => 'datetime', 'declined_at' => 'datetime', 'withdrawn_at' => 'datetime', 'converted_at' => 'datetime',
        ];
    }

    public static function seesAll(Staff $staff): bool
    {
        return $staff->can('quotations.view_all');
    }

    public static function seesOwn(Staff $staff): bool
    {
        return $staff->can('quotations.view_own');
    }

    /** Staff make every quotation, so there is no pool. */
    public function scopeClaimable(Builder $query): void
    {
        $query->whereRaw('1 = 0');
    }

    /** Still an offer the customer can take: sent and not expired, or accepted and not yet booked. */
    public function scopeOpen(Builder $query): void
    {
        $query->where(fn (Builder $q) => $q
            ->where(fn (Builder $sent) => $sent->where($this->qualifyColumn('status'), self::SENT)->whereDate($this->qualifyColumn('valid_until'), '>=', now('Asia/Dhaka')->toDateString()))
            ->orWhere($this->qualifyColumn('status'), self::ACCEPTED));
    }

    /** Sent and past the end of valid_until (Dhaka). */
    public function scopeExpired(Builder $query): void
    {
        $query->where($this->qualifyColumn('status'), self::SENT)->whereDate($this->qualifyColumn('valid_until'), '<', now('Asia/Dhaka')->toDateString());
    }

    /** Sent, not yet expired, and running out within 48 hours: valid_until ends (Dhaka midnight after it) by now + 48 h. */
    public function scopeExpiringSoon(Builder $query): void
    {
        $query->where($this->qualifyColumn('status'), self::SENT)
            ->whereDate($this->qualifyColumn('valid_until'), '>=', now('Asia/Dhaka')->toDateString())
            ->whereDate($this->qualifyColumn('valid_until'), '<=', self::expiringBy());
    }

    /** The same rule for one loaded row. */
    public function isExpiringSoon(): bool
    {
        return $this->status === self::SENT && ! $this->isExpired() && $this->valid_until->toDateString() <= self::expiringBy();
    }

    /** The last valid_until that ends within 48 hours from now. */
    private static function expiringBy(): string
    {
        return now('Asia/Dhaka')->addHours(48)->subDay()->toDateString();
    }

    /**
     * The list filters, shared by GET /admin/quotations and its sidebar badge.
     *
     * @param  array{status?: ?string, owner?: ?string, search?: ?string}  $filters
     */
    public function scopeFiltered(Builder $query, array $filters, Staff $staff): void
    {
        $status = $filters['status'] ?? null;
        $query
            ->when($status === 'expired', fn (Builder $q) => $q->expired())
            ->when($status === 'expiring', fn (Builder $q) => $q->expiringSoon())
            // "Sent" means still open: an expired one has its own filter.
            ->when($status === self::SENT, fn (Builder $q) => $q->where('status', self::SENT)->whereDate('valid_until', '>=', now('Asia/Dhaka')->toDateString()))
            ->when($status !== null && ! in_array($status, ['expired', 'expiring', self::SENT], true), fn (Builder $q) => $q->where('status', $status))
            ->when(($filters['owner'] ?? null) === 'mine', fn (Builder $q) => $q->where('assigned_staff_id', $staff->id))
            ->when($filters['search'] ?? null, fn (Builder $q, string $search) => $q->where(fn (Builder $inner) => $inner
                ->where('number', 'like', "%{$search}%")->orWhere('package_title_en', 'like', "%{$search}%")
                ->orWhereHas('customer', fn (Builder $c) => $c->where('name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%"))));
    }

    /** The status shown: the stored one, or "expired" once a sent quotation is past its date. */
    public function displayStatus(): string
    {
        return $this->status === self::SENT && $this->isExpired() ? 'expired' : $this->status;
    }

    public function isExpired(): bool
    {
        return $this->valid_until->toDateString() < now('Asia/Dhaka')->toDateString();
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(TourPackage::class, 'tour_package_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(QuotationLine::class)->orderBy('sort_order');
    }

    public function revisionOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'revision_of_id');
    }

    public function convertedBooking(): BelongsTo
    {
        return $this->belongsTo(Booking::class, 'converted_booking_id');
    }

    public function departure(): BelongsTo
    {
        return $this->belongsTo(PackageDeparture::class, 'departure_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'created_by_staff_id');
    }

    /** Quotations that revise this one, newest first. */
    public function revisions(): HasMany
    {
        return $this->hasMany(self::class, 'revision_of_id')->latest('id');
    }

    /** The draft revision of this quotation still being prepared, if any. */
    public function openRevision(): HasOne
    {
        return $this->hasOne(self::class, 'revision_of_id')->where('status', self::DRAFT)->latestOfMany();
    }
}
