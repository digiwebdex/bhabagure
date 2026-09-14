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
use App\Services\Ledger\LedgerService;
use App\Services\Payments\PaymentAmountChanged;
use App\Services\Payments\PaymentNotAllowed;
use App\Services\Payments\PaymentService;
use App\Services\Payments\PaymentsNotConfigured;
use App\Services\Payments\SslCommerz\GatewayUnavailable;
use App\Support\Money;
use App\Support\Phone;
use App\Support\Pricing\PricingConfig;
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
            'addons' => ['array', 'max:20'],
            'addons.*' => ['string', 'distinct', Rule::exists(Addon::class, 'code')->where('is_active', true)],
            'travellers' => ['required', 'array', "size:{$pax}"],
            'travellers.*.name' => ['required', 'string', 'max:160'],
            'travellers.*.passport_number' => ['required', 'regex:/^(?:[A-Z]{2}\d{7}|[A-Z]\d{8})$/'],
            'travellers.*.date_of_birth' => ['required', 'date_format:Y-m-d', 'before:today'],
            'travellers.*.passport_expiry' => ['required', 'date_format:Y-m-d', 'after:travel_date'],
            'travellers.*.phone' => ['nullable', 'regex:/^8801[3-9]\d{8}$/'],
            'travellers.0.phone' => ['required'],
            'travellers.*.email' => ['nullable', 'email', 'max:190'],
            'travellers.*.passport_scan_token' => ['nullable', 'string', 'size:48'],
            'travellers.*.ocr_filled' => ['boolean'],
            'expected_total' => ['required', 'numeric', 'min:0'],
            'terms_accepted' => ['accepted'],
            'locale' => ['required', Rule::in(['bn', 'en'])],
        ], [
            'travellers.*.passport_expiry.after' => __('booking.passport_expiry_after_travel'),
        ]);

        try {
            $created = $creator->create(new BookingRequest(
                packageSlug: $data['package_slug'],
                travelDate: $data['travel_date'],
                pax: $data['pax'],
                room: $data['room'],
                addonCodes: $data['addons'] ?? [],
                travellers: array_map(fn (array $t) => [
                    'name' => trim($t['name']),
                    'passportNumber' => $t['passport_number'],
                    'dateOfBirth' => $t['date_of_birth'],
                    'passportExpiry' => $t['passport_expiry'],
                    'phone' => $t['phone'] ?? null,
                    'email' => $t['email'] ?? null,
                    'passportScanToken' => $t['passport_scan_token'] ?? null,
                    'ocrFilled' => (bool) ($t['ocr_filled'] ?? false),
                ], array_values($data['travellers'])),
                expectedTotal: $data['expected_total'],
                locale: $data['locale'],
                source: LeadSource::WebsiteForm->value,
                termsAccepted: true,
            ), $request->user('customer'));
        } catch (PriceChanged $e) {
            return response()->json(['message' => __('booking.price_changed'), 'code' => 'price_changed', 'quote' => $e->quote], Response::HTTP_CONFLICT);
        } catch (SeatsUnavailable $e) {
            return response()->json([
                'message' => __('booking.seats_unavailable', ['count' => $e->available]), 'code' => 'seats_unavailable', 'available' => $e->available,
            ], Response::HTTP_CONFLICT);
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
                $travellers[$i]['passport_number'] = strtoupper(preg_replace('/\s+/', '', (string) ($traveller['passport_number'] ?? '')));
                $travellers[$i]['email'] = trim((string) ($traveller['email'] ?? '')) ?: null;
            }
            $request->merge(['travellers' => $travellers]);
        }
    }
}
