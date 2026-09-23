<?php

namespace App\Models;

use App\Support\Pricing\PricingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One use of a coupon by one booking (docs/coupons.md §2.1, §2.4), with the coupon's terms as they were when applied.
 * reserved: an open booking holds it; used: the booking was confirmed; released: given back (an unconfirmed booking was
 * cancelled or deleted, or staff removed the coupon). Reserved and used count against the limits. Written only by
 * CouponService.
 */
class CouponRedemption extends Model
{
    public const RESERVED = 'reserved';

    public const USED = 'used';

    public const RELEASED = 'released';

    public const LIVE = [self::RESERVED, self::USED];

    public const STATUSES = [self::RESERVED, self::USED, self::RELEASED];

    /** The website's booking form (the customer), or staff on the booking page. */
    public const SOURCE_WEBSITE = 'website';

    public const SOURCE_OFFICE = 'office';

    protected $fillable = [
        'coupon_id', 'booking_id', 'customer_id', 'code', 'kind', 'discount_type', 'discount_value', 'max_discount_amount',
        'min_booking_amount', 'passport_number', 'eligible_amount', 'discount_amount', 'original_total', 'final_total', 'status',
        'source', 'applied_by_staff_id', 'applied_at', 'used_at', 'released_at', 'release_reason', 'released_by_staff_id',
    ];

    protected $hidden = ['passport_number', 'passport_number_hash'];

    protected function casts(): array
    {
        return [
            'discount_value' => 'decimal:2',
            'max_discount_amount' => 'decimal:2',
            'min_booking_amount' => 'decimal:2',
            'eligible_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'original_total' => 'decimal:2',
            'final_total' => 'decimal:2',
            'applied_at' => 'datetime',
            'used_at' => 'datetime',
            'released_at' => 'datetime',
            'passport_number' => 'encrypted',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $redemption) {
            if ($redemption->isDirty('passport_number')) {
                $number = $redemption->passport_number;
                $redemption->passport_number_hash = $number === null ? null : BookingTraveller::passportHash($number);
            }
        });
    }

    public function scopeLive(Builder $query): void
    {
        $query->whereIn($this->qualifyColumn('status'), self::LIVE);
    }

    /** The coupon, archived or not: a use outlives its coupon's archiving. */
    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class)->withTrashed();
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class)->withTrashed();
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function appliedBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'applied_by_staff_id');
    }

    /**
     * The terms as @bhabaghure/pricing's couponDiscount takes them — what the admin screen re-works the discount with.
     *
     * @return array{type: string, value: float, maxDiscount: ?float, minAmount: ?float}
     */
    public function terms(): array
    {
        return [
            'type' => $this->discount_type,
            'value' => (float) $this->discount_value,
            'maxDiscount' => $this->max_discount_amount === null ? null : (float) $this->max_discount_amount,
            'minAmount' => $this->min_booking_amount === null ? null : (float) $this->min_booking_amount,
        ];
    }

    /**
     * The discount these copied terms give on a booking amount (never the coupon's current terms).
     *
     * @return array{eligible: bool, discount: int}
     */
    public function discountOn(int|float $amount): array
    {
        $terms = $this->terms();

        return PricingService::couponDiscount($terms['type'], $terms['value'], $terms['maxDiscount'], $terms['minAmount'], $amount);
    }
}
