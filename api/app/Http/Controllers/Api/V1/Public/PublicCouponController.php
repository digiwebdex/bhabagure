<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Models\Addon;
use App\Models\BookingTraveller;
use App\Models\Coupon;
use App\Models\TourPackage;
use App\Services\Booking\BookingCreator;
use App\Services\Coupons\CouponCheck;
use App\Services\Coupons\CouponRefused;
use App\Services\Coupons\CouponService;
use App\Support\Money;
use App\Support\Numerals;
use App\Support\Phone;
use App\Support\Pricing\PricingConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The booking form's Apply button (docs/coupons.md §2.6): the booking's choices, the lead's WhatsApp number and the
 * passport numbers typed so far, against a code. The answer is advice — booking checks the coupon again, with its
 * limits locked — and the discount in it is the API's: the website never works one out or sends one.
 */
class PublicCouponController extends Controller
{
    public function check(Request $request, BookingCreator $creator, CouponService $coupons): JsonResponse
    {
        $phone = trim((string) $request->input('phone'));
        $request->merge([
            'phone' => $phone === '' ? null : (Phone::normalizeBdMobile($phone) ?? $phone),
            'passport_numbers' => array_values(array_filter(array_map(
                fn ($number) => strtoupper((string) preg_replace('/\s+/', '', (string) $number)), (array) $request->input('passport_numbers', []),
            ))),
        ]);
        $max = PricingConfig::current()->maxTravellers;
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40'],
            'package_slug' => ['required', 'string', 'max:190'],
            'pax' => ['required', 'integer', 'min:1', "max:{$max}"],
            'room' => ['required', Rule::in(['twin', 'triple', 'single'])],
            'hotel_category' => ['nullable', Rule::in(['3', '4', '5'])],
            'addons' => ['array', 'max:20'],
            'addons.*' => ['string', 'distinct', Rule::exists(Addon::class, 'code')->where('is_active', true)],
            'phone' => ['nullable', 'string', 'max:20'],
            'passport_numbers' => ['array', 'max:20'],
            'passport_numbers.*' => ['string', 'max:20'],
            'locale' => ['required', Rule::in(['bn', 'en'])],
        ]);
        $locale = $data['locale'];

        $package = TourPackage::query()->published()->where('slug', $data['package_slug'])->firstOrFail();
        $addons = Addon::query()->where('is_active', true)->whereIn('code', $data['addons'] ?? [])->orderBy('sort_order')->get()->all();
        $quote = $creator->packageQuote($package, $data['pax'], $data['room'], $data['hotel_category'] ?? null, $addons);
        $subtotal = BookingCreator::eligibleAmount($quote['lines']);

        try {
            $offer = $coupons->evaluate($data['code'], new CouponCheck(
                $package->id, $subtotal, $request->user('customer')?->id, $data['phone'] ?? null,
                array_values(array_unique(array_map(BookingTraveller::passportHash(...), $data['passport_numbers'] ?? []))),
            ));
        } catch (CouponRefused $e) {
            return response()->json(['data' => [
                'valid' => false, 'code' => Coupon::normalizeCode($data['code']), 'reason' => $e->reason, 'message' => $e->reasonText($locale),
                'subtotal' => $subtotal, 'total' => $quote['total'],
            ]]);
        }

        $coupon = $offer['coupon'];
        $final = $creator->packageQuote($package, $data['pax'], $data['room'], $data['hotel_category'] ?? null, $addons, $offer['discount']);

        return response()->json(['data' => [
            'valid' => true,
            'code' => $coupon->code,
            'kind' => $coupon->kind,
            'discountType' => $coupon->discount_type,
            'discountValue' => Money::toNumber($coupon->discount_value),
            'maxDiscount' => $coupon->discount_type === Coupon::PERCENT ? Money::toNumber($coupon->max_discount_amount) : null,
            'minAmount' => Money::toNumber($coupon->min_booking_amount),
            'discount' => $final['discount'],
            // Every line before the discount and the service charge; the total without and with the coupon.
            'subtotal' => $subtotal,
            'originalTotal' => $quote['total'],
            'total' => $final['total'],
            'message' => __('coupons.applied', ['amount' => Numerals::bdt($final['discount'], $locale)], $locale),
        ]]);
    }
}
