<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Enums\LeadSource;
use App\Http\Controllers\Controller;
use App\Http\Resources\PublicBooking;
use App\Models\Addon;
use App\Models\Booking;
use App\Models\Customer;
use App\Services\Booking\BookingCreator;
use App\Services\Booking\BookingRequest;
use App\Services\Booking\PriceChanged;
use App\Services\Booking\SeatsUnavailable;
use App\Services\Coupons\CouponRefused;
use App\Services\Ledger\LedgerService;
use App\Services\Payments\PaymentAmountChanged;
use App\Services\Payments\PaymentNotAllowed;
use App\Services\Payments\PaymentService;
use App\Services\Payments\PaymentsNotConfigured;
use App\Services\Payments\SslCommerz\GatewayUnavailable;
use App\Support\Money;
use App\Support\Numerals;
use App\Support\Phone;
use App\Support\Pricing\PricingConfig;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Website booking (docs/phase-3-booking.md §2 and §4). A guest proves access to their booking with the private token
 * returned once at creation (header X-Booking-Token); a signed-in customer with their own session.
 */
class PublicBookingController extends Controller
{
    public function store(Request $request, BookingCreator $creator): JsonResponse
    {
        $this->normalize($request);
        $max = PricingConfig::current()->maxTravellers;
        $pax = (int) $request->input('pax');

        $data = $request->validate([
            'package_slug' => ['required', 'string', 'max:190'],
            'travel_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.now('Asia/Dhaka')->toDateString()],
            'pax' => ['required', 'integer', 'min:1', "max:{$max}"],
            'room' => ['required', Rule::in(['twin', 'triple', 'single'])],
            'hotel_category' => ['nullable', Rule::in(['3', '4', '5'])],
            'addons' => ['array', 'max:20'],
            'addons.*' => ['string', 'distinct', Rule::exists(Addon::class, 'code')->where('is_active', true)],
            'travellers' => ['required', 'array', "size:{$pax}"],
            // Only the lead traveller's name and WhatsApp number are needed to book (docs/phase-8 §2); staff collect the
            // rest later. Anything given is still checked.
            'travellers.*.name' => ['nullable', 'string', 'max:160'],
            'travellers.0.name' => ['required'],
            'travellers.*.passport_number' => ['nullable', 'regex:/^(?:[A-Z]{2}\d{7}|[A-Z]\d{8})$/'],
            'travellers.*.date_of_birth' => ['nullable', 'date_format:Y-m-d', 'before:today'],
            'travellers.*.passport_expiry' => ['nullable', 'date_format:Y-m-d', 'after:travel_date'],
            'travellers.*.phone' => ['nullable', 'regex:/^8801[3-9]\d{8}$/'],
            'travellers.0.phone' => ['required'],
            'travellers.*.email' => ['nullable', 'email', 'max:190'],
            'travellers.*.passport_scan_token' => ['nullable', 'string', 'size:48'],
            'travellers.*.ocr_filled' => ['boolean'],
            'expected_total' => ['required', 'numeric', 'min:0'],
            'terms_accepted' => ['accepted'],
            'locale' => ['required', Rule::in(['bn', 'en'])],
            // The website sends one per booking attempt; see alreadyCreated().
            'idempotency_key' => ['nullable', 'uuid'],
            // Only a code (docs/coupons.md): the discount is worked out here, never taken from the browser.
            'coupon_code' => ['nullable', 'string', 'max:40'],
        ], [
            'travellers.*.passport_expiry.after' => __('booking.passport_expiry_after_travel'),
        ]);

        if (($data['idempotency_key'] ?? null) !== null && ($existing = $this->alreadyCreated($data['idempotency_key'])) !== null) {
            return $existing;
        }

        try {
            $created = $creator->create(new BookingRequest(
                packageSlug: $data['package_slug'],
                travelDate: $data['travel_date'],
                pax: $data['pax'],
                room: $data['room'],
                addonCodes: $data['addons'] ?? [],
                travellers: array_map(fn (array $t, int $i) => [
                    // An unnamed traveller is kept as "Traveller 2" and so on, for staff to complete.
                    'name' => trim((string) ($t['name'] ?? '')) ?: __('booking.unnamed_traveller', ['n' => Numerals::number($i + 1, $data['locale'])], $data['locale']),
                    'passportNumber' => $t['passport_number'] ?? null,
                    'dateOfBirth' => $t['date_of_birth'] ?? null,
                    'passportExpiry' => $t['passport_expiry'] ?? null,
                    'phone' => $t['phone'] ?? null,
                    'email' => $t['email'] ?? null,
                    'passportScanToken' => $t['passport_scan_token'] ?? null,
                    'ocrFilled' => (bool) ($t['ocr_filled'] ?? false),
                ], array_values($data['travellers']), array_keys(array_values($data['travellers']))),
                expectedTotal: $data['expected_total'],
                locale: $data['locale'],
                source: LeadSource::WebsiteForm->value,
                termsAccepted: true,
                hotelCategory: $data['hotel_category'] ?? null,
                idempotencyKey: $data['idempotency_key'] ?? null,
                couponCode: filled($data['coupon_code'] ?? null) ? $data['coupon_code'] : null,
            ), $request->user('customer'));
        } catch (UniqueConstraintViolationException $e) {
            // Two copies of one attempt at the same moment: the index let the first in, and the second answers as a repeat.
            $existing = ($data['idempotency_key'] ?? null) === null ? null : $this->alreadyCreated($data['idempotency_key']);
            if ($existing === null) {
                throw $e;
            }

            return $existing;
        } catch (PriceChanged $e) {
            return response()->json(['message' => __('booking.price_changed'), 'code' => 'price_changed', 'quote' => $e->quote], Response::HTTP_CONFLICT);
        } catch (SeatsUnavailable $e) {
            return response()->json([
                'message' => __('booking.seats_unavailable', ['count' => $e->available]), 'code' => 'seats_unavailable', 'available' => $e->available,
            ], Response::HTTP_CONFLICT);
        } catch (CouponRefused $e) {
            // The coupon stopped working after the customer applied it (used up, expired, switched off): nothing is booked,
            // and the form says why and offers the price without it.
            return response()->json(['message' => $e->reasonText($data['locale']), 'code' => 'coupon_invalid', 'reason' => $e->reason], Response::HTTP_CONFLICT);
        }

        return response()->json(['data' => PublicBooking::make($created['booking']) + ['accessToken' => $created['accessToken']]], Response::HTTP_CREATED);
    }

    public function show(Request $request, string $reference): JsonResponse
    {
        return response()->json(['data' => PublicBooking::make($this->authorizedBooking($request, $reference))]);
    }

    /**
     * Starts an SSLCommerz payment for the full balance plus any online payment charge, and returns the gateway page.
     * `expected_total` is the total the customer saw; if it no longer matches, nothing starts (409 price_changed).
     */
    public function pay(Request $request, string $reference): JsonResponse
    {
        $booking = $this->authorizedBooking($request, $reference);
        $data = $request->validate([
            'method' => ['required', Rule::in(array_keys(PaymentService::METHODS))],
            'expected_total' => ['required', 'numeric', 'min:0'],
            'return_to' => ['nullable', Rule::in(['site', 'portal'])],
        ]);
        // Back to the portal only for its signed-in owner: a guest with the private link has no portal session to return to.
        $customer = $request->user('customer');
        $returnTo = ($data['return_to'] ?? 'site') === 'portal' && $customer instanceof Customer && $customer->id === $booking->customer_id ? 'portal' : 'site';

        try {
            $attempt = app(PaymentService::class)->start($booking, $data['method'], $data['expected_total'], $returnTo);
        } catch (PaymentAmountChanged $e) {
            return response()->json(['message' => __('booking.price_changed'), 'code' => 'price_changed', 'payment' => $e->payment], Response::HTTP_CONFLICT);
        } catch (PaymentsNotConfigured|PaymentNotAllowed) {
            return response()->json(['message' => __('booking.payment_unavailable'), 'code' => 'payment_unavailable'], Response::HTTP_CONFLICT);
        } catch (SeatsUnavailable $e) {
            return response()->json([
                'message' => __('booking.seats_unavailable', ['count' => $e->available]), 'code' => 'seats_unavailable', 'available' => $e->available,
            ], Response::HTTP_CONFLICT);
        } catch (GatewayUnavailable) {
            return response()->json(['message' => __('booking.payment_start_failed'), 'code' => 'gateway_unavailable'], Response::HTTP_BAD_GATEWAY);
        }

        return response()->json(['data' => [
            'redirectUrl' => $attempt->gateway_url,
            'amount' => Money::toNumber($attempt->amount),
            'charge' => Money::toNumber($attempt->online_charge),
            'total' => Money::toNumber(LedgerService::amount($attempt->expectedPaisa())),
        ]]);
    }

    /**
     * A booking attempt that already went through: 409 `already_created` with its reference, never a second booking. The
     * access token is not sent again — only its hash is kept, and the form that made the attempt still holds it.
     */
    private function alreadyCreated(string $key): ?JsonResponse
    {
        $booking = Booking::query()->where('idempotency_key', $key)->first(['id', 'reference']);

        return $booking === null ? null : response()->json([
            'message' => __('booking.already_created', ['reference' => $booking->reference]),
            'code' => 'already_created',
            'reference' => $booking->reference,
        ], Response::HTTP_CONFLICT);
    }

    private function authorizedBooking(Request $request, string $reference): Booking
    {
        $booking = Booking::query()->where('reference', $reference)->first();
        $token = (string) $request->header('X-Booking-Token', '');
        $customer = $request->user('customer');

        $allowed = $booking !== null && (
            ($token !== '' && $booking->access_token_hash !== null && hash_equals($booking->access_token_hash, Booking::hashAccessToken($token)))
            || ($customer instanceof Customer && $customer->id === $booking->customer_id)
        );

        // Same answer for "no such booking" and "not yours": references are guessable, bookings must not be.
        abort_unless($allowed, Response::HTTP_NOT_FOUND, __('booking.not_found'));

        return $booking;
    }

    private function normalize(Request $request): void
    {
        $travellers = $request->input('travellers');
        if (is_array($travellers)) {
            foreach ($travellers as $i => $traveller) {
                if (! is_array($traveller)) {
                    continue;
                }
                $phone = trim((string) ($traveller['phone'] ?? ''));
                $travellers[$i]['phone'] = $phone === '' ? null : (Phone::normalizeBdMobile($phone) ?? $phone);
                $travellers[$i]['passport_number'] = strtoupper(preg_replace('/\s+/', '', (string) ($traveller['passport_number'] ?? ''))) ?: null;
                foreach (['name', 'date_of_birth', 'passport_expiry'] as $optional) {
                    $travellers[$i][$optional] = trim((string) ($traveller[$optional] ?? '')) ?: null;
                }
                $travellers[$i]['email'] = trim((string) ($traveller['email'] ?? '')) ?: null;
            }
            $request->merge(['travellers' => $travellers]);
        }
    }
}
