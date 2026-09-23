<?php

namespace App\Http\Resources;

use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Staff;
use App\Models\TourPackage;
use App\Support\Money;

/** Coupons and their uses for the admin (snake_case, like the rest of the staff API). Amounts are JSON numbers. */
final class AdminCoupon
{
    /**
     * @param  array{used?: int, pending?: int, released?: int, discount_given?: int|float, revenue?: int|float}  $usage
     * @return array<string, mixed>
     */
    public static function make(Coupon $coupon, Staff $viewer, array $usage = []): array
    {
        $manage = $viewer->can('coupons.manage');

        return [
            'id' => $coupon->id,
            'code' => $coupon->code,
            'name' => $coupon->name,
            'kind' => $coupon->kind,
            'channel' => $coupon->channel,
            'discount_type' => $coupon->discount_type,
            'discount_value' => Money::toNumber($coupon->discount_value),
            'max_discount_amount' => Money::toNumber($coupon->max_discount_amount),
            'min_booking_amount' => Money::toNumber($coupon->min_booking_amount),
            'starts_at' => $coupon->starts_at?->toIso8601String(),
            'ends_at' => $coupon->ends_at?->toIso8601String(),
            'usage_limit' => $coupon->usage_limit,
            'per_customer_limit' => $coupon->per_customer_limit,
            'applies_to' => $coupon->applies_to,
            'packages' => $coupon->relationLoaded('packages')
                ? $coupon->packages->map(fn (TourPackage $p) => ['id' => $p->id, 'title' => $p->title_en ?: $p->title_bn])->values()->all() : [],
            // The number in full only for those who manage coupons (the edit form); a masked one for everyone else.
            'passport_number' => $manage ? $coupon->passport_number : null,
            'passport_masked' => $coupon->passport_number === null ? null : self::mask($coupon->passport_number),
            'holder_name' => $coupon->holder_name,
            'is_active' => $coupon->is_active,
            'notes' => $coupon->notes,
            'status' => $coupon->status(),
            'usage' => [
                'live' => (int) ($coupon->live_uses ?? 0),
                'used' => (int) ($usage['used'] ?? 0),
                'pending' => (int) ($usage['pending'] ?? 0),
                'released' => (int) ($usage['released'] ?? 0),
                'discount_given' => $usage['discount_given'] ?? 0,
                'revenue' => $usage['revenue'] ?? 0,
            ],
            // A coupon nothing ever used is deleted outright; otherwise it is archived (CouponManager::delete).
            'ever_used' => (bool) ($coupon->ever_used ?? $coupon->redemptions()->exists()),
            'archived_at' => $coupon->deleted_at?->toIso8601String(),
            'created_by' => $coupon->createdBy?->name,
            'created_at' => $coupon->created_at?->toIso8601String(),
            'updated_at' => $coupon->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> one use, for the report */
    public static function use(CouponRedemption $use): array
    {
        return [
            'id' => $use->id,
            'coupon_id' => $use->coupon_id,
            'code' => $use->code,
            'kind' => $use->kind,
            'coupon_name' => $use->coupon?->name,
            'holder_name' => $use->coupon?->holder_name,
            'booking' => $use->booking ? ['id' => $use->booking->id, 'reference' => $use->booking->reference, 'status' => $use->booking->status->value, 'package_title' => $use->booking->package_title_en] : null,
            'customer' => $use->customer ? ['id' => $use->customer->id, 'name' => $use->customer->name, 'phone' => $use->customer->phone] : null,
            'passport_masked' => $use->passport_number === null ? null : self::mask($use->passport_number),
            'discount_type' => $use->discount_type,
            'discount_value' => Money::toNumber($use->discount_value),
            'eligible_amount' => Money::toNumber($use->eligible_amount),
            'discount_amount' => Money::toNumber($use->discount_amount),
            'original_total' => Money::toNumber($use->original_total),
            'final_total' => Money::toNumber($use->final_total),
            'status' => $use->status,
            'source' => $use->source,
            'applied_at' => $use->applied_at?->toIso8601String(),
            'used_at' => $use->used_at?->toIso8601String(),
            'released_at' => $use->released_at?->toIso8601String(),
            'release_reason' => $use->release_reason,
        ];
    }

    /** "B12345678" → "B1•••••78", as the website's invoice prints it. */
    public static function mask(string $passport): string
    {
        return strlen($passport) <= 4 ? $passport : substr($passport, 0, 2).str_repeat('•', strlen($passport) - 4).substr($passport, -2);
    }
}
