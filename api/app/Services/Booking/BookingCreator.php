<?php

namespace App\Services\Booking;

use App\Enums\LeadSource;
use App\Events\BookingCreated;
use App\Models\Addon;
use App\Models\Booking;
use App\Models\BookingTraveller;
use App\Models\Customer;
use App\Models\PackageDeparture;
use App\Models\PassportScan;
use App\Models\Quotation;
use App\Models\QuotationLine;
use App\Models\SeatHold;
use App\Models\Staff;
use App\Models\TourPackage;
use App\Models\TravellerDocument;
use App\Services\AuditLogger;
use App\Services\Documents\DocumentNumbers;
use App\Support\Money;
use App\Support\Pricing\PriceGrid;
use App\Support\Pricing\PricingConfig;
use App\Support\Pricing\PricingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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

            $lines = self::lineRows($package, $quote, $addons->all());

            $result = $this->persist($request, [
                'tour_package_id' => $package->id,
                'package_title_en' => $package->title_en,
                'package_title_bn' => $package->title_bn,
                'duration_days' => $package->duration_days,
                'list_price' => $this->listPrice($package),
                // The chosen hotel category and its grid row as priced, kept like list_price (Phase 8 §4.D).
                'hotel_category' => $quote['hotelCategory'],
                'price_grid' => PriceGrid::rowFor($package->price_grid, $quote['hotelCategory']),
                'unit_price' => $quote['perPerson'],
                'subtotal_amount' => $quote['subtotal'],
                'single_supplement_amount' => $quote['singleSupplement'],
                'addons_amount' => array_sum(array_column($quote['addons'], 'amount')),
                'discount_amount' => $quote['discount'],
                'vat_rate' => $quote['chargePercent'],
                'vat_amount' => $quote['serviceCharge'],
                'total_amount' => $quote['total'],
            ], $lines, $customer, $staff);

            return $result + ['quote' => $quote];
        });
    }

    /**
     * Booking and quotation lines from a pricing quote: package, single supplement and add-ons, titled in both languages.
     *
     * @param  array<string, mixed>  $quote  PricingService::quoteBooking
     * @param  list<Addon>  $addons
     * @return list<array{kind: string, code: ?string, title_en: string, title_bn: ?string, quantity: int, unit_price: int|float, amount: int|float}>
     */
    public static function lineRows(TourPackage $package, array $quote, array $addons): array
    {
        $addonsByCode = collect($addons)->keyBy('code');

        return array_map(fn (array $line) => [
            'kind' => $line['kind'],
            'code' => $line['code'],
            'title_en' => ($line['code'] ? $addonsByCode[$line['code']]->name_en : null) ?? ($line['kind'] === 'package' ? PriceGrid::lineTitle($package->title_en, $quote['hotelCategory'] ?? null, 'en') : 'Single room supplement'),
            'title_bn' => ($line['code'] ? $addonsByCode[$line['code']]->name_bn : null) ?? ($line['kind'] === 'package' ? ($package->title_bn === null ? null : PriceGrid::lineTitle($package->title_bn, $quote['hotelCategory'] ?? null, 'bn')) : 'সিঙ্গেল রুম সাপ্লিমেন্ট'),
            'quantity' => $line['quantity'],
            'unit_price' => $line['unitPrice'],
            'amount' => $line['amount'],
        ], $quote['lines']);
    }

    /**
     * A booking from a sent or accepted quotation, at the quotation's frozen price — nothing is re-priced; the lines
     * are copied one to one. The booking belongs to the quotation's owner (commission follows it); $staff is who
     * converted it. The quotation must be locked by the caller (QuotationService::convert).
     *
     * @param  list<array<string, mixed>>  $travellers
     * @return array{booking: Booking, accessToken: string}
     *
     * @throws SeatsUnavailable
     */
    public function createFromQuotation(Quotation $quotation, string $travelDate, array $travellers, Staff $staff): array
    {
        return DB::transaction(function () use ($quotation, $travelDate, $travellers, $staff) {
            $quotation->loadMissing(['lines', 'customer']);
            $request = new BookingRequest(
                packageSlug: '', travelDate: $travelDate, pax: $quotation->pax_count, room: $quotation->room_type,
                addonCodes: [], travellers: $travellers, expectedTotal: (float) $quotation->total_amount, locale: $quotation->locale,
                source: $quotation->customer->source, termsAccepted: false, hotelCategory: $quotation->hotel_category,
            );

            return $this->persist($request, [
                'tour_package_id' => $quotation->tour_package_id,
                'package_title_en' => $quotation->package_title_en,
                'package_title_bn' => $quotation->package_title_bn,
                'duration_days' => $quotation->duration_days,
            ] + $quotation->only(['list_price', 'hotel_category', 'price_grid', 'unit_price', 'subtotal_amount', 'single_supplement_amount', 'addons_amount', 'discount_amount', 'vat_rate', 'vat_amount', 'total_amount']),
                $quotation->lines->map(fn (QuotationLine $line) => $line->only(['kind', 'code', 'title_en', 'title_bn', 'quantity', 'unit_price', 'amount']))->all(),
                $quotation->customer, $staff, $quotation->id, $quotation->assigned_staff_id ?? $staff->id);
        });
    }

    /**
     * Everything after pricing, the same for the website, the office and a converted quotation: seats, customer, number,
     * snapshot, lines, travellers, seat hold, audit and the booking-received messages.
     *
     * @param  array<string, mixed>  $snapshot  package snapshot and amounts
     * @param  list<array<string, mixed>>  $lines
     * @return array{booking: Booking, accessToken: string}
     *
     * @throws SeatsUnavailable
     */
    private function persist(BookingRequest $request, array $snapshot, array $lines, ?Customer $customer, ?Staff $staff, ?int $quotationId = null, ?int $ownerId = null): array
    {
        $departure = $snapshot['tour_package_id'] === null ? null : PackageDeparture::query()->where('tour_package_id', $snapshot['tour_package_id'])
            ->where('status', 'scheduled')->whereDate('departs_on', $request->travelDate)->lockForUpdate()->first();
        if ($departure && $departure->seats_total !== null && DepartureSeats::available($departure) < $request->pax) {
            throw new SeatsUnavailable(DepartureSeats::available($departure));
        }

        $lead = $request->travellers[0];
        $customer ??= $this->customerFor($lead['name'], $lead['phone'], $lead['email'] ?? null, $request->locale);
        $accessToken = Str::random(48);
        $start = Carbon::parse($request->travelDate);

        $booking = Booking::query()->create([
            'reference' => $this->numbers->bookingReference(),
            'customer_id' => $customer->id,
            'client_id' => $customer->client_id,
            'tour_package_id' => $snapshot['tour_package_id'],
            'departure_id' => $departure?->id,
            'quotation_id' => $quotationId,
            'package_title_en' => $snapshot['package_title_en'],
            'package_title_bn' => $snapshot['package_title_bn'],
            'travel_start' => $start->toDateString(),
            'travel_end' => $snapshot['duration_days'] ? $start->copy()->addDays($snapshot['duration_days'] - 1)->toDateString() : null,
            'pax_count' => $request->pax,
            'room_type' => $request->room,
            'source' => $request->source,
            'created_by_staff_id' => $staff?->id,
            // A booking belongs to the staff member who made it (a converted quotation's owner); a website booking
            // starts in the shared pool.
            'assigned_staff_id' => $ownerId ?? $staff?->id,
            'locale' => $request->locale,
            'terms_accepted_at' => $request->termsAccepted ? now() : null,
            'terms_version' => $request->termsAccepted ? config('bhabaghure.booking.terms_version') : null,
            'access_token_hash' => Booking::hashAccessToken($accessToken),
        ] + array_intersect_key($snapshot, array_flip(['list_price', 'hotel_category', 'price_grid', 'unit_price', 'subtotal_amount', 'single_supplement_amount', 'addons_amount', 'discount_amount', 'vat_rate', 'vat_amount', 'total_amount'])));

        foreach (array_values($lines) as $index => $line) {
            $booking->lines()->create($line + ['sort_order' => $index]);
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

        $this->audit->record('booking.created', $staff ?? $customer, $booking, array_filter(['source' => $request->source, 'total' => $snapshot['total_amount'], 'quotation_id' => $quotationId]));

        // The private link (token in the fragment) can only be sent now: afterwards only its hash exists.
        $web = rtrim((string) config('bhabaghure.web_url'), '/').($request->locale === 'en' ? '/en' : '');
        BookingCreated::dispatch($booking, "{$web}/booking/{$booking->reference}#t={$accessToken}");

        return ['booking' => $booking->refresh(), 'accessToken' => $accessToken];
    }

    /**
     * The quote the website shows for the same inputs (GET /public/pricing + packages + add-ons).
     *
     * @param  list<Addon>  $addons
     * @return array<string, mixed>
     */
    public function quote(TourPackage $package, BookingRequest $request, array $addons): array
    {
        self::assertHotelCategory($package, $request->hotelCategory);

        return PricingService::quoteBooking(
            $this->listPrice($package),
            $request->pax,
            $request->room,
            array_map(fn (Addon $addon) => ['code' => $addon->code, 'price' => Money::toNumber($addon->price), 'unit' => $addon->unit], $addons),
            PricingConfig::current(),
            grid: $package->price_grid,
            hotelCategory: $request->hotelCategory,
        );
    }

    /**
     * A package with a price grid is booked and quoted in one of the categories it offers; the category is refused as a
     * validation error on `hotel_category`, never as a server error.
     *
     * @throws ValidationException
     */
    public static function assertHotelCategory(TourPackage $package, ?string $category): void
    {
        $offered = PricingService::gridCategories($package->price_grid);
        if ($offered !== [] && ! in_array($category, $offered, true)) {
            throw ValidationException::withMessages(['hotel_category' => [__('cms.hotel_category_required')]]);
        }
    }

    public function listPrice(TourPackage $package): int|float
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
            'stage' => 'lead', 'source' => LeadSource::WebsiteForm->value, 'locale' => $locale,
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
        // Waits for staff review like a scan uploaded later in the portal (docs/phase-6-customer-portal.md §3.3).
        TravellerDocument::query()->create([
            'booking_traveller_id' => $traveller->id, 'kind' => TravellerDocument::PASSPORT_SCAN, 'status' => TravellerDocument::UPLOADED,
            'disk' => $scan->disk, 'path' => $scan->path, 'mime' => $scan->mime, 'bytes' => $scan->bytes, 'source' => 'booking', 'uploaded_at' => now(),
        ]);
    }
}
