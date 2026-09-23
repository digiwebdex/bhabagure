<?php

namespace App\Services\Coupons;

use App\Models\Booking;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Customer;
use App\Models\Staff;
use App\Services\AuditLogger;
use App\Support\Pricing\PricingService;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Checking a coupon and recording its uses (docs/coupons.md §2.3, §2.4) — the only code that does either. The discount
 * is always worked out here, from the coupon's terms and the booking amount; nothing a customer sends is trusted.
 *
 * Limits are race-safe: evaluate(lock: true) locks the coupon's row and counts its uses with locking reads, which see
 * the latest committed uses rather than the transaction's snapshot. Called inside the booking's own transaction, two
 * bookings can't both take the last use — the second waits for the first, then sees its use.
 */
final class CouponService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * The coupon for this code and its discount on this booking, or why it can't be used.
     *
     * @return array{coupon: Coupon, discount: int, passportHash: ?string, customerId: ?int}
     *
     * @throws CouponRefused
     */
    public function evaluate(string $code, CouponCheck $check, bool $lock = false): array
    {
        if ($lock && DB::transactionLevel() === 0) {
            throw new LogicException('A coupon is reserved inside the booking\'s transaction.');
        }

        $query = Coupon::query()->where('code', Coupon::normalizeCode($code));
        $coupon = ($lock ? $query->lockForUpdate() : $query)->first();
        $now = now();
        if ($coupon === null) {
            throw new CouponRefused('not_found');
        }
        if (! $coupon->is_active) {
            throw new CouponRefused('inactive');
        }
        if ($coupon->starts_at !== null && $coupon->starts_at->gt($now)) {
            throw new CouponRefused('not_started', ['date' => $coupon->starts_at]);
        }
        if ($coupon->ends_at !== null && $coupon->ends_at->lte($now)) {
            throw new CouponRefused('expired', ['date' => $coupon->ends_at]);
        }
        if (! $coupon->appliesToPackage($check->packageId)) {
            throw new CouponRefused('package');
        }

        $outcome = PricingService::couponDiscount($coupon->discount_type, (float) $coupon->discount_value,
            $coupon->max_discount_amount === null ? null : (float) $coupon->max_discount_amount,
            $coupon->min_booking_amount === null ? null : (float) $coupon->min_booking_amount,
            $check->eligibleAmount);
        if (! $outcome['eligible']) {
            throw new CouponRefused('min_amount', ['amount' => (float) $coupon->min_booking_amount]);
        }

        // Any traveller on the booking may be the holder (decided 2026-09-24). The passport wanted is never revealed.
        if ($coupon->kind === Coupon::PASSPORT && ! in_array($coupon->passport_number_hash, $check->passportHashes, true)) {
            throw new CouponRefused('passport');
        }

        $customerId = $check->customerId ?? ($check->phone === null ? null
            : ($lock ? Customer::query()->where('phone', $check->phone)->sharedLock() : Customer::query()->where('phone', $check->phone))->value('id'));
        $uses = fn () => CouponRedemption::query()->where('coupon_id', $coupon->id)->whereIn('status', CouponRedemption::LIVE)
            ->when($lock, fn ($q) => $q->sharedLock());
        if ($coupon->usage_limit !== null && $uses()->count() >= $coupon->usage_limit) {
            throw new CouponRefused('used_up');
        }
        if ($coupon->per_customer_limit !== null && $customerId !== null && $uses()->where('customer_id', $customerId)->count() >= $coupon->per_customer_limit) {
            throw new CouponRefused('customer_limit');
        }

        return [
            'coupon' => $coupon,
            'discount' => $outcome['discount'],
            'passportHash' => $coupon->kind === Coupon::PASSPORT ? $coupon->passport_number_hash : null,
            'customerId' => $customerId,
        ];
    }

    /**
     * Records the use against a booking just priced with it — reserved until the booking is confirmed. Call in the same
     * transaction as evaluate(lock: true). The terms are copied, so the booking never depends on the coupon's later edits.
     *
     * @param  array{eligible: int, discount: int, originalTotal: int|float, finalTotal: int|float}  $amounts
     */
    public function reserve(Coupon $coupon, Booking $booking, array $amounts, string $source, ?Staff $staff = null): CouponRedemption
    {
        $redemption = CouponRedemption::query()->create([
            'coupon_id' => $coupon->id,
            'booking_id' => $booking->id,
            'customer_id' => $booking->customer_id,
            'code' => $coupon->code,
            'kind' => $coupon->kind,
            'discount_type' => $coupon->discount_type,
            'discount_value' => $coupon->discount_value,
            'max_discount_amount' => $coupon->discount_type === Coupon::PERCENT ? $coupon->max_discount_amount : null,
            'min_booking_amount' => $coupon->min_booking_amount,
            // The passport that satisfied it — the coupon's own, which a traveller on the booking holds.
            'passport_number' => $coupon->kind === Coupon::PASSPORT ? $coupon->passport_number : null,
            'eligible_amount' => $amounts['eligible'],
            'discount_amount' => $amounts['discount'],
            'original_total' => $amounts['originalTotal'],
            'final_total' => $amounts['finalTotal'],
            'status' => CouponRedemption::RESERVED,
            'source' => $source,
            'applied_by_staff_id' => $staff?->id,
            'applied_at' => now(),
        ]);
        $this->audit->record('coupon.applied', $staff ?? $booking->customer, $booking, [
            'coupon' => $coupon->code, 'discount' => $amounts['discount'], 'source' => $source,
        ]);

        return $redemption;
    }

    /** The booking's quote changed while it can still change: its use follows (amounts only — the terms stay as copied). */
    public function syncAmounts(Booking $booking, int|float $eligible, int|float $discount, int|float $originalTotal): void
    {
        CouponRedemption::query()->where('booking_id', $booking->id)->whereIn('status', CouponRedemption::LIVE)->update([
            'eligible_amount' => $eligible,
            'discount_amount' => $discount,
            'original_total' => $originalTotal,
            'final_total' => $booking->total_amount,
            'updated_at' => now(),
        ]);
    }

    /** The booking is confirmed: its reserved use becomes used, and counts in the report. */
    public function markUsed(Booking $booking): void
    {
        CouponRedemption::query()->where('booking_id', $booking->id)->where('status', CouponRedemption::RESERVED)
            ->update(['status' => CouponRedemption::USED, 'used_at' => now(), 'updated_at' => now()]);
    }

    /**
     * Gives the booking's use back: it stops counting against the limits. With $keepUsed (a cancellation), a use that a
     * confirmed booking already made stays used (decided 2026-09-24); only a reserved one is released.
     */
    public function release(Booking $booking, string $reason, ?Staff $staff = null, bool $keepUsed = false): ?CouponRedemption
    {
        $redemption = CouponRedemption::query()->where('booking_id', $booking->id)
            ->whereIn('status', $keepUsed ? [CouponRedemption::RESERVED] : CouponRedemption::LIVE)->lockForUpdate()->first();
        if ($redemption === null) {
            return null;
        }

        $redemption->forceFill([
            'status' => CouponRedemption::RELEASED, 'released_at' => now(), 'release_reason' => $reason, 'released_by_staff_id' => $staff?->id,
        ])->save();
        $this->audit->record('coupon.released', $staff, $booking, ['coupon' => $redemption->code, 'reason' => $reason]);

        return $redemption;
    }
}
