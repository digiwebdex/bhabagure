<?php

namespace App\Services\Booking;

use App\Events\BookingCreated;
use App\Models\Addon;
use App\Models\Booking;
use App\Models\BookingTraveller;
use App\Models\Customer;
use App\Models\PackageDeparture;
use App\Models\PassportScan;
use App\Models\SeatHold;
use App\Models\Staff;
use App\Models\TourPackage;
use App\Services\AuditLogger;
use App\Services\Documents\DocumentNumbers;
use App\Support\Money;
use App\Support\Pricing\PricingConfig;
use App\Support\Pricing\PricingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates a booking as an unpaid inquiry (docs/phase-3-booking.md §2–§3). The price is recomputed here from current
 * package, slab and add-on data with the PHP twin of the website's pricing service; if it differs from the total the
 * customer saw, nothing is created and PriceChanged carries the new quote.
 */
final class BookingCreator
{
    public function __construct(
        private readonly DocumentNumbers $numbers,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{booking: Booking, accessToken: string, quote: array<string, mixed>}
     *
     * @throws PriceChanged|SeatsUnavailable
     */
    public function create(BookingRequest $request, ?Customer $customer = null, ?Staff $staff = null): array
    {
        return DB::transaction(function () use ($request, $customer, $staff) {
            $package = TourPackage::query()->published()->where('slug', $request->packageSlug)->firstOrFail();
            $addons = Addon::query()->where('is_active', true)->whereIn('code', $request->addonCodes)->orderBy('sort_order')->get();
            $quote = $this->quote($package, $request, $addons->all());

            if ((int) round($request->expectedTotal) !== $quote['total']) {
                throw new PriceChanged($quote);
            }

            $departure = PackageDeparture::query()->where('tour_package_id', $package->id)->where('status', 'scheduled')
                ->whereDate('departs_on', $request->travelDate)->lockForUpdate()->first();
            if ($departure && $departure->seats_total !== null && DepartureSeats::available($departure) < $request->pax) {
                throw new SeatsUnavailable(DepartureSeats::available($departure));
            }

            $lead = $request->travellers[0];
            $customer ??= $this->customerFor($lead['name'], $lead['phone'], $lead['email'] ?? null, $request->locale);
            $accessToken = Str::random(48);
            $start = Carbon::parse($request->travelDate);
            $lines = $quote['lines'];

            $booking = Booking::query()->create([
                'reference' => $this->numbers->bookingReference(),
                'customer_id' => $customer->id,
                'client_id' => $customer->client_id,
                'tour_package_id' => $package->id,
                'departure_id' => $departure?->id,
                'package_title_en' => $package->title_en,
                'package_title_bn' => $package->title_bn,
                'travel_start' => $start->toDateString(),
                'travel_end' => $package->duration_days ? $start->copy()->addDays($package->duration_days - 1)->toDateString() : null,
                'pax_count' => $request->pax,
                'room_type' => $request->room,
                'list_price' => $this->listPrice($package),
                'unit_price' => $quote['perPerson'],
                'subtotal_amount' => $quote['subtotal'],
                'single_supplement_amount' => $quote['singleSupplement'],
                'addons_amount' => array_sum(array_column($quote['addons'], 'amount')),
                'discount_amount' => $quote['discount'],
                'vat_rate' => $quote['chargePercent'],
                'vat_amount' => $quote['serviceCharge'],
                'total_amount' => $quote['total'],
                'source' => $request->source,
                'created_by_staff_id' => $staff?->id,
                'locale' => $request->locale,
                'terms_accepted_at' => $request->termsAccepted ? now() : null,
                'terms_version' => $request->termsAccepted ? config('bhabaghure.booking.terms_version') : null,
                'access_token_hash' => Booking::hashAccessToken($accessToken),
            ]);

            $addonsByCode = $addons->keyBy('code');
            foreach ($lines as $index => $line) {
                $addon = $line['code'] ? $addonsByCode[$line['code']] : null;
                $booking->lines()->create([
                    'kind' => $line['kind'],
                    'code' => $line['code'],
                    'title_en' => $addon?->name_en ?? ($line['kind'] === 'package' ? $package->title_en : 'Single room supplement'),
                    'title_bn' => $addon?->name_bn ?? ($line['kind'] === 'package' ? $package->title_bn : 'সিঙ্গেল রুম সাপ্লিমেন্ট'),
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unitPrice'],
                    'amount' => $line['amount'],
                    'sort_order' => $index,
                ]);
            }

            foreach ($request->travellers as $index => $data) {
                $traveller = $booking->travellers()->create([
                    'customer_id' => $index === 0 ? $customer->id : null,
                    'is_lead' => $index === 0,
                    'full_name' => $data['name'],
                    'date_of_birth' => $data['dateOfBirth'] ?? null,
                    'passport_number' => isset($data['passportNumber']) ? strtoupper(preg_replace('/\s+/', '', $data['passportNumber'])) : null,
                    'passport_expiry' => $data['passportExpiry'] ?? null,
                    'phone' => $data['phone'] ?? null,
                    'email' => $data['email'] ?? null,
                    'sort_order' => $index,
                ]);
                $this->attachScan($traveller, $data['passportScanToken'] ?? null, (bool) ($data['ocrFilled'] ?? false));
            }

            if ($departure && $departure->seats_total !== null) {
                SeatHold::query()->create([
                    'departure_id' => $departure->id, 'booking_id' => $booking->id, 'seats' => $request->pax,
                    'expires_at' => now()->addMinutes((int) config('bhabaghure.booking.hold_minutes')),
                ]);
            }

            $this->audit->record('booking.created', $staff ?? $customer, $booking, ['source' => $request->source, 'total' => $quote['total']]);

            // The private link (token in the fragment) can only be sent now: afterwards only its hash exists.
            $web = rtrim((string) config('bhabaghure.web_url'), '/').($request->locale === 'en' ? '/en' : '');
            BookingCreated::dispatch($booking, "{$web}/booking/{$booking->reference}#t={$accessToken}");

            return ['booking' => $booking->refresh(), 'accessToken' => $accessToken, 'quote' => $quote];
        });
    }

    /**
     * The quote the website shows for the same inputs (GET /public/pricing + packages + add-ons).
     *
     * @param  list<Addon>  $addons
     * @return array<string, mixed>
     */
    public function quote(TourPackage $package, BookingRequest $request, array $addons): array
    {
        return PricingService::quoteBooking(
            $this->listPrice($package),
            $request->pax,
            $request->room,
            array_map(fn (Addon $addon) => ['code' => $addon->code, 'price' => Money::toNumber($addon->price), 'unit' => $addon->unit], $addons),
            PricingConfig::current(),
        );
    }

    private function listPrice(TourPackage $package): int|float
    {
        return Money::toNumber($package->sale_price ?? $package->regular_price);
    }

    /**
     * The customer record for this phone number, created as a lead if new. The guest learns nothing about whether the
     * number was known — the response is the same either way.
     */
    private function customerFor(string $name, string $phone, ?string $email, string $locale): Customer
    {
        $existing = Customer::query()->where('phone', $phone)->first();
        if ($existing) {
            return $existing;
        }

        $emailFree = $email !== null && ! Customer::query()->where('email', $email)->exists();

        return Customer::query()->create([
            'name' => $name, 'phone' => $phone, 'email' => $emailFree ? $email : null,
            'stage' => 'lead', 'source' => 'website_booking', 'locale' => $locale,
        ]);
    }

    private function attachScan(BookingTraveller $traveller, ?string $token, bool $ocrFilled): void
    {
        if ($token === null) {
            return;
        }

        $scan = PassportScan::query()->where('token_hash', PassportScan::hashToken($token))->whereNull('booking_traveller_id')
            ->where('expires_at', '>', now())->lockForUpdate()->first();
        if (! $scan) {
            return;
        }

        $scan->update(['booking_traveller_id' => $traveller->id]);
        $traveller->forceFill(['passport_scan_path' => $scan->path, 'ocr_filled_at' => $ocrFilled ? now() : null])->save();
    }
}
