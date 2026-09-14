<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\LeadSource;
use App\Http\Controllers\Controller;
use App\Http\Resources\AdminBooking;
use App\Models\Addon;
use App\Models\Customer;
use App\Models\PackageDeparture;
use App\Models\TourPackage;
use App\Services\Booking\BookingRequest;
use App\Services\Booking\CustomerExists;
use App\Services\Booking\DepartureSeats;
use App\Services\Booking\PriceChanged;
use App\Services\Booking\SeatsUnavailable;
use App\Services\Booking\StaffBookingCreator;
use App\Support\Money;
use App\Support\Phone;
use App\Support\Pricing\PricingConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/** "+ New booking" (docs/phase-5-admin-core.md §4.3): what the form can offer, and creating the booking. */
class StaffBookingController extends Controller
{
    /** Published packages with their upcoming departures, active add-ons and the pricing the form quotes with. */
    public function options(Request $request): JsonResponse
    {
        abort_unless($request->user('staff')->can('bookings.create'), 403, __('auth.forbidden'));
        $today = now('Asia/Dhaka')->toDateString();
        $config = PricingConfig::current();

        return response()->json(['data' => [
            'packages' => TourPackage::query()->published()->orderBy('sort_order')->orderBy('id')
                ->with(['departures' => fn ($q) => $q->where('status', 'scheduled')->whereDate('departs_on', '>=', $today)->orderBy('departs_on')])
                ->get()
                ->map(fn (TourPackage $package) => [
                    'slug' => $package->slug,
                    'title_en' => $package->title_en,
                    'title_bn' => $package->title_bn,
                    'duration_days' => $package->duration_days,
                    'list_price' => Money::toNumber($package->sale_price ?? $package->regular_price),
                    'departures' => $package->departures->map(fn (PackageDeparture $departure) => [
                        'date' => $departure->departs_on->toDateString(),
                        'seats_left' => $departure->seats_total === null ? null : DepartureSeats::available($departure),
                    ])->values(),
                ])->values(),
            'addons' => Addon::query()->where('is_active', true)->orderBy('sort_order')->get()
                ->map(fn (Addon $addon) => ['code' => $addon->code, 'name_en' => $addon->name_en, 'name_bn' => $addon->name_bn, 'price' => Money::toNumber($addon->price), 'unit' => $addon->unit])->values(),
            'config' => [
                'slabs' => $config->slabs, 'singleRoomSupplementPercent' => $config->singleRoomSupplementPercent,
                'serviceChargePercent' => $config->serviceChargePercent, 'maxTravellers' => $config->maxTravellers,
                'onlinePaymentChargePercent' => $config->onlinePaymentChargePercent,
            ],
            'sources' => array_map(fn (LeadSource $source) => $source->value, LeadSource::forCustomers()),
        ]]);
    }

    public function store(Request $request, StaffBookingCreator $creator): JsonResponse
    {
        $staff = $request->user('staff');
        abort_unless($staff->can('bookings.create'), 403, __('auth.forbidden'));
        // Numbers typed as 01711-000001 are stored as 8801711000001, as everywhere else.
        if (is_array($request->input('customer')) && $request->filled('customer.phone')) {
            $request->merge(['customer' => ['phone' => Phone::normalizeBdMobile($request->input('customer.phone')) ?? $request->input('customer.phone')] + $request->input('customer')]);
        }
        if (is_array($request->input('travellers'))) {
            $request->merge(['travellers' => array_map(fn ($t) => is_array($t) && isset($t['phone']) ? ['phone' => Phone::normalizeBdMobile($t['phone']) ?? $t['phone']] + $t : $t, $request->input('travellers'))]);
        }
        $max = PricingConfig::current()->maxTravellers;
        $pax = (int) $request->input('pax');

        $data = $request->validate([
            'customer_id' => ['required_without:customer', 'prohibits:customer', 'nullable', 'integer'],
            'customer' => ['required_without:customer_id', 'nullable', 'array'],
            'customer.name' => ['required_with:customer', 'string', 'max:160'],
            'customer.phone' => ['required_with:customer', 'regex:/^8801[3-9]\d{8}$/'],
            'customer.email' => ['nullable', 'email', 'max:190'],
            'customer.source' => ['required_with:customer', Rule::in(array_map(fn (LeadSource $s) => $s->value, LeadSource::forCustomers()))],
            'package_slug' => ['required', 'string', Rule::exists('tour_packages', 'slug')->where('status', 'published')],
            'travel_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.now('Asia/Dhaka')->toDateString()],
            'pax' => ['required', 'integer', 'min:1', "max:{$max}"],
            'room' => ['required', Rule::in(['twin', 'triple', 'single'])],
            'addons' => ['array', 'max:20'],
            'addons.*' => ['string', 'distinct', Rule::exists(Addon::class, 'code')->where('is_active', true)],
            // Passports often arrive after the booking at the office; the documents-pending message asks for them.
            'travellers' => ['required', 'array', "size:{$pax}"],
            'travellers.*.name' => ['required', 'string', 'max:160'],
            'travellers.*.passport_number' => ['nullable', 'regex:/^(?:[A-Z]{2}\d{7}|[A-Z]\d{8})$/'],
            'travellers.*.date_of_birth' => ['nullable', 'date_format:Y-m-d', 'before:today'],
            'travellers.*.passport_expiry' => ['nullable', 'date_format:Y-m-d', 'after:travel_date'],
            'travellers.*.phone' => ['nullable', 'regex:/^8801[3-9]\d{8}$/'],
            'travellers.*.email' => ['nullable', 'email', 'max:190'],
            'expected_total' => ['required', 'numeric', 'min:0'],
            'locale' => ['required', Rule::in(['bn', 'en'])],
        ], ['travellers.*.passport_expiry.after' => __('booking.passport_expiry_after_travel')]);

        $customer = isset($data['customer_id']) ? Customer::query()->visibleTo($staff)->findOrFail($data['customer_id']) : null;
        $travellers = array_values($data['travellers']);
        // The lead traveller is reachable at the customer's number unless another was given.
        $travellers[0]['phone'] ??= $customer?->phone ?? $data['customer']['phone'];
        $travellers[0]['email'] ??= $customer?->email ?? ($data['customer']['email'] ?? null);

        try {
            $booking = $creator->create(new BookingRequest(
                packageSlug: $data['package_slug'],
                travelDate: $data['travel_date'],
                pax: $data['pax'],
                room: $data['room'],
                addonCodes: $data['addons'] ?? [],
                travellers: array_map(fn (array $t) => [
                    'name' => trim($t['name']),
                    'passportNumber' => $t['passport_number'] ?? null,
                    'dateOfBirth' => $t['date_of_birth'] ?? null,
                    'passportExpiry' => $t['passport_expiry'] ?? null,
                    'phone' => $t['phone'] ?? null,
                    'email' => $t['email'] ?? null,
                ], $travellers),
                expectedTotal: $data['expected_total'],
                locale: $data['locale'],
                source: $customer?->source ?? $data['customer']['source'],
                termsAccepted: false,
            ), $staff, $customer, $customer ? null : [
                'name' => trim($data['customer']['name']), 'phone' => $data['customer']['phone'], 'email' => $data['customer']['email'] ?? null, 'source' => $data['customer']['source'],
            ]);
        } catch (CustomerExists $e) {
            // Name the record only to someone who may see it; otherwise say no more than that the number is taken.
            $visible = Customer::query()->visibleTo($staff)->whereKey($e->customer->id)->exists();

            return response()->json([
                'message' => __('booking.customer_exists'), 'code' => 'customer_exists',
                'customer' => $visible ? ['id' => $e->customer->id, 'name' => $e->customer->name] : null,
            ], Response::HTTP_CONFLICT);
        } catch (PriceChanged $e) {
            return response()->json(['message' => __('booking.price_changed'), 'code' => 'price_changed', 'quote' => $e->quote], Response::HTTP_CONFLICT);
        } catch (SeatsUnavailable $e) {
            return response()->json(['message' => __('booking.seats_unavailable', ['count' => $e->available]), 'code' => 'seats_unavailable', 'available' => $e->available], Response::HTTP_CONFLICT);
        }

        return response()->json(['data' => AdminBooking::detail($booking, $staff)], Response::HTTP_CREATED);
    }
}
