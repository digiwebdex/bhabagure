<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A coupon (docs/coupons.md): a public campaign code, or one passport holder's. Its terms can change at any time; a
 * booking keeps the terms it was given in its CouponRedemption. Written only through CouponManager; checked and used
 * only through CouponService.
 */
class Coupon extends Model
{
    use SoftDeletes;

    public const PUBLIC = 'public';

    public const PASSPORT = 'passport';

    public const KINDS = [self::PUBLIC, self::PASSPORT];

    public const PERCENT = 'percent';

    public const FIXED = 'fixed';

    public const DISCOUNT_TYPES = [self::PERCENT, self::FIXED];

    /** Where a campaign runs, for the report's campaign table. */
    public const CHANNELS = ['website', 'facebook', 'sms', 'email', 'seasonal', 'other'];

    public const APPLIES_ALL = 'all';

    public const APPLIES_PACKAGES = 'packages';

    /** Letters, digits and dashes, 3–30 long, starting with a letter or digit; stored in capitals. */
    public const CODE_PATTERN = '/^[A-Z0-9][A-Z0-9-]{2,29}$/';

    /** As the admin shows it, in this order of precedence (status()). */
    public const STATUSES = ['archived', 'inactive', 'expired', 'scheduled', 'used_up', 'active'];

    protected $fillable = [
        'code', 'name', 'kind', 'channel', 'discount_type', 'discount_value', 'max_discount_amount', 'min_booking_amount',
        'starts_at', 'ends_at', 'usage_limit', 'per_customer_limit', 'applies_to', 'passport_number', 'holder_name',
        'is_active', 'notes', 'created_by_staff_id', 'updated_by_staff_id',
    ];

    protected $hidden = ['passport_number', 'passport_number_hash'];

    protected function casts(): array
    {
        return [
            'discount_value' => 'decimal:2',
            'max_discount_amount' => 'decimal:2',
            'min_booking_amount' => 'decimal:2',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'usage_limit' => 'integer',
            'per_customer_limit' => 'integer',
            'is_active' => 'boolean',
            // AES-256 with APP_KEY, like booking_travellers.passport_number.
            'passport_number' => 'encrypted',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $coupon) {
            $coupon->code = self::normalizeCode((string) $coupon->code);
            if ($coupon->isDirty('passport_number')) {
                $number = $coupon->passport_number;
                // The same HMAC as a traveller's passport, so a coupon is matched without decrypting anything.
                $coupon->passport_number_hash = $number === null ? null : BookingTraveller::passportHash($number);
            }
        });
    }

    /** How a code is stored and looked up: capitals, no spaces. Customers can type it in any case. */
    public static function normalizeCode(string $code): string
    {
        return strtoupper((string) preg_replace('/\s+/', '', $code));
    }

    public function packages(): BelongsToMany
    {
        return $this->belongsToMany(TourPackage::class, 'coupon_packages');
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(CouponRedemption::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'created_by_staff_id');
    }

    /** Whether a booking of this package — null for a custom service — may use the coupon. */
    public function appliesToPackage(?int $packageId): bool
    {
        if ($this->applies_to === self::APPLIES_ALL) {
            return true;
        }

        return $packageId !== null && $this->packages()->whereKey($packageId)->exists();
    }

    /** Uses that count against the limits: reserved by an open booking, or used by a confirmed one. */
    public function liveUses(): HasMany
    {
        return $this->redemptions()->whereIn('status', CouponRedemption::LIVE);
    }

    /** With `live_uses` counted, for status() and the list. */
    public function scopeWithLiveUses(Builder $query): void
    {
        $query->withCount(['redemptions as live_uses' => fn (Builder $q) => $q->whereIn('status', CouponRedemption::LIVE)]);
    }

    /**
     * The list's status filter; the same rules as status(), so a chip's count and its rows can't disagree. Archived
     * coupons appear only under their own filter.
     */
    public function scopeInStatus(Builder $query, string $status, ?Carbon $now = null): void
    {
        $now ??= now();
        $live = '(SELECT COUNT(*) FROM coupon_redemptions r WHERE r.coupon_id = coupons.id AND r.status IN (\'reserved\', \'used\'))';
        $expired = fn (Builder $q) => $q->whereNotNull('ends_at')->where('ends_at', '<=', $now);
        $scheduled = fn (Builder $q) => $q->whereNotNull('starts_at')->where('starts_at', '>', $now);
        $running = fn (Builder $q) => $q->where('is_active', true)
            ->where(fn (Builder $w) => $w->whereNull('ends_at')->orWhere('ends_at', '>', $now))
            ->where(fn (Builder $w) => $w->whereNull('starts_at')->orWhere('starts_at', '<=', $now));

        match ($status) {
            'archived' => $query->onlyTrashed(),
            'inactive' => $query->where('is_active', false),
            'expired' => $query->where('is_active', true)->where($expired),
            'scheduled' => $query->where('is_active', true)->where($scheduled)->where(fn (Builder $w) => $w->whereNull('ends_at')->orWhere('ends_at', '>', $now)),
            'used_up' => $query->where($running)->whereNotNull('usage_limit')->whereRaw("{$live} >= usage_limit"),
            'active' => $query->where($running)->where(fn (Builder $w) => $w->whereNull('usage_limit')->orWhereRaw("{$live} < usage_limit")),
            default => null,
        };
    }

    /** One of STATUSES. Needs `live_uses` (scopeWithLiveUses) or counts them. */
    public function status(?Carbon $now = null): string
    {
        $now ??= now();
        $live = $this->live_uses ?? $this->liveUses()->count();

        return match (true) {
            $this->trashed() => 'archived',
            ! $this->is_active => 'inactive',
            $this->ends_at !== null && $this->ends_at->lte($now) => 'expired',
            $this->starts_at !== null && $this->starts_at->gt($now) => 'scheduled',
            $this->usage_limit !== null && $live >= $this->usage_limit => 'used_up',
            default => 'active',
        };
    }
}
