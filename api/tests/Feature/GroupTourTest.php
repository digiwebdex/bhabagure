<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\PackageDeparture;
use App\Models\TourPackage;
use App\Services\Brochures\BrochurePdf;
use Database\Seeders\ContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * docs/fixed-departure-group-tours.md: a group tour has one fixed price per person — no group-size discount — its own
 * single and triple room prices, and is booked only on its scheduled departures, from the website and at the office.
 */
class GroupTourTest extends TestCase
{
    use RefreshDatabase;

    private const MUSTANG = 'nepal-mustang-adventure-tour-8-days-7-nights';

    private TourPackage $package;

    private PackageDeparture $departure;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ContentSeeder::class);
        // 75,000 per person (sale price) in triple sharing, the base; twin +5%, single +50% (docs/room-rates.md).
        $this->package = TourPackage::query()->where('slug', self::MUSTANG)->sole();
        $this->package->update(['trip_type' => TourPackage::GROUP_FIXED, 'single_supplement_percent' => 50, 'twin_supplement_percent' => 5]);
        $this->departure = PackageDeparture::query()->create([
            'tour_package_id' => $this->package->id, 'departs_on' => now('Asia/Dhaka')->addDays(30)->toDateString(), 'seats_total' => 12, 'status' => 'scheduled',
        ]);
    }

    #[Test]
    public function the_price_is_fixed_and_only_the_room_changes_it(): void
    {
        // Four travellers in triple sharing pay the list price: no group-size discount (a customized trip's 4–5 tier
        // would take 6% off). All with the 2% service charge.
        $this->assertSame(306000, $this->quote(['pax' => 4, 'room' => 'triple']));
        // Twin sharing: 75,000 + 3,750 each; one traveller in a single: 75,000 + 37,500.
        $this->assertSame(240975, $this->quote(['pax' => 3, 'room' => 'twin']));
        $this->assertSame(114750, $this->quote(['pax' => 1, 'room' => 'single']));

        $booking = $this->book(['pax' => 3, 'room' => 'twin']);
        $this->assertSame([$this->departure->id, 75000.0, 11250.0, 240975.0], [$booking->departure_id, (float) $booking->unit_price, (float) $booking->single_supplement_amount, (float) $booking->total_amount]);
        $this->assertEquals(['singleSupplementPercent' => 50, 'twinSupplementPercent' => 5], $booking->room_rates);
        $this->assertTrue($booking->fixed_price);
        $twin = $booking->lines()->where('kind', 'twin_supplement')->sole();
        $this->assertSame(['Twin sharing supplement', 'টুইন শেয়ারিং সাপ্লিমেন্ট', 3, 3750.0], [$twin->title_en, $twin->title_bn, (int) $twin->quantity, (float) $twin->unit_price]);
    }

    #[Test]
    public function a_group_tour_is_booked_on_its_departures_only(): void
    {
        $elsewhere = now('Asia/Dhaka')->addDays(31)->toDateString();
        $this->postJson('/api/v1/public/bookings', $this->payload(['travel_date' => $elsewhere, 'room' => 'triple']) + ['expected_total' => 153000])
            ->assertUnprocessable()->assertJsonValidationErrors(['travel_date']);

        // A cancelled departure is no longer offered.
        $this->departure->update(['status' => 'cancelled']);
        $this->postJson('/api/v1/public/bookings', $this->payload(['room' => 'triple']) + ['expected_total' => 153000])
            ->assertUnprocessable()->assertJsonValidationErrors(['travel_date']);
        $this->departure->update(['status' => 'scheduled']);

        // At the office too: a walk-in booking on another date is refused the same way.
        $this->actingAsApi($this->staff('sales_agent'))->postJson('/api/v1/admin/bookings', [
            'customer' => ['name' => 'Karim Uddin', 'phone' => '01711-000555', 'source' => 'walk_in'],
            'package_slug' => self::MUSTANG, 'travel_date' => $elsewhere, 'pax' => 2, 'room' => 'triple', 'addons' => [],
            'travellers' => [['name' => 'Karim Uddin'], ['name' => 'Salma Begum']], 'expected_total' => 153000, 'locale' => 'bn',
        ])->assertUnprocessable()->assertJsonValidationErrors(['travel_date']);
        $this->assertSame(0, Booking::query()->count());

        // A full departure is refused as before.
        $this->departure->update(['seats_total' => 1]);
        $this->postJson('/api/v1/public/bookings', $this->payload(['room' => 'triple']) + ['expected_total' => 153000])->assertStatus(409);
    }

    #[Test]
    public function a_booking_keeps_the_room_prices_it_was_booked_with(): void
    {
        $booking = $this->book(['pax' => 2, 'room' => 'triple']);
        $this->package->update(['single_supplement_percent' => 60, 'twin_supplement_percent' => 10]);

        // Staff move them to singles: +50% as booked, not the new 60%. 150,000 + 75,000, then 2%.
        $admin = $this->staff('admin');
        $this->actingAsApi($admin)->putJson("/api/v1/admin/bookings/{$booking->id}/quote", [
            'pax' => 2, 'room' => 'single', 'discount' => 0, 'vat_rate' => 2, 'expected_total' => 229500,
        ])->assertOk();
        $this->assertSame(229500.0, (float) $booking->refresh()->total_amount);
        // And to a twin room: +5% as booked, not the new 10%. 150,000 + 7,500, then 2%.
        $this->actingAsApi($admin)->putJson("/api/v1/admin/bookings/{$booking->id}/quote", [
            'pax' => 2, 'room' => 'twin', 'discount' => 0, 'vat_rate' => 2, 'expected_total' => 160650,
        ])->assertOk()->assertJsonPath('data.quote_inputs.room_rates.twinSupplementPercent', 5);
    }

    #[Test]
    public function a_customized_trip_takes_its_group_rate_as_the_base_and_its_own_room_rates(): void
    {
        $this->package->update(['trip_type' => TourPackage::CUSTOMIZED]);
        // Four travellers: the 6% group-size tier is the base (70,500), twin adds the package's 5% (3,525); any date.
        $this->assertSame(302022, $this->quote(['pax' => 4, 'room' => 'twin', 'travel_date' => now('Asia/Dhaka')->addDays(31)->toDateString()]));
        $this->assertSame(287640, $this->quote(['pax' => 4, 'room' => 'triple', 'travel_date' => now('Asia/Dhaka')->addDays(31)->toDateString()]));
    }

    #[Test]
    public function staff_mark_a_package_as_a_group_tour_and_the_website_is_told(): void
    {
        $operator = $this->staff('tour_operator');
        $url = "/api/v1/admin/packages/{$this->package->id}";
        $detail = $this->actingAsApi($operator)->getJson($url)->assertOk()->json('data');
        $this->assertSame(['group_fixed', 50, 5], [$detail['trip_type'], $detail['single_supplement_percent'], $detail['twin_supplement_percent']]);

        $this->actingAsApi($operator)->putJson($url, ['single_supplement_percent' => 101] + $this->editable($detail))
            ->assertUnprocessable()->assertJsonValidationErrors(['single_supplement_percent']);
        $this->actingAsApi($operator)->putJson($url, ['trip_type' => 'customized', 'twin_supplement_percent' => 0] + $this->editable($detail))->assertOk();
        $this->assertFalse($this->package->refresh()->isGroupTour());
        $this->actingAsApi($operator)->putJson($url, ['trip_type' => 'group_fixed', 'single_supplement_percent' => 40, 'twin_supplement_percent' => 8] + $this->editable($detail))->assertOk();

        $this->getJson('/api/v1/public/packages/'.self::MUSTANG)->assertOk()
            ->assertJsonPath('data.groupTour', true)
            ->assertJsonPath('data.roomRates', ['singleSupplementPercent' => 40, 'twinSupplementPercent' => 8]);
    }

    #[Test]
    public function the_brochure_shows_the_room_prices_and_the_departure_dates(): void
    {
        $this->package->load(['destination', 'itineraryDays', 'inclusions']);
        $html = app(BrochurePdf::class)->packageHtml($this->package, null, 4, 'en');

        $this->assertStringContainsString('Group Tour · Fixed Departure', $html);
        $this->assertStringContainsString('Twin sharing', $html);
        // Triple 75,000 (the base), twin +5% 78,750, single +50% 1,12,500.
        foreach (['৳ 75,000', '৳ 78,750', '৳ 1,12,500'] as $price) {
            $this->assertStringContainsString($price, $html);
        }
        $this->assertStringNotContainsString('Group size', $html);
        $this->assertStringContainsString($this->departure->departs_on->format('Y'), $html);
    }

    /** @param array<string, mixed> $overrides */
    private function quote(array $overrides): int
    {
        return (int) $this->postJson('/api/v1/public/bookings', $this->payload($overrides) + ['expected_total' => 0])->assertStatus(409)->json('quote.total');
    }

    /** @param array<string, mixed> $overrides */
    private function book(array $overrides): Booking
    {
        $reference = $this->postJson('/api/v1/public/bookings', $this->payload($overrides) + ['expected_total' => $this->quote($overrides)])
            ->assertCreated()->json('data.reference');

        return Booking::query()->where('reference', $reference)->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        $pax = $overrides['pax'] ?? 2;

        return $overrides + [
            'package_slug' => self::MUSTANG,
            'travel_date' => $this->departure->departs_on->toDateString(),
            'pax' => $pax,
            'room' => 'twin',
            'addons' => [],
            'travellers' => [['name' => 'Tanvir Hasan', 'phone' => '01711-000001'], ...array_fill(0, $pax - 1, [])],
            'terms_accepted' => true,
            'locale' => 'en',
        ];
    }

    /**
     * The editor's own fields from the admin detail, as the package editor sends them back.
     *
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>
     */
    private function editable(array $detail): array
    {
        return [
            ...collect($detail)->only([
                'code', 'slug', 'destination_id', 'title_en', 'title_bn', 'summary_en', 'summary_bn', 'duration_days', 'duration_nights',
                'regular_price', 'sale_price', 'price_grid', 'price_options', 'includes_airfare', 'group_mode', 'min_pax', 'departure_mode',
                'trip_type', 'single_supplement_percent', 'twin_supplement_percent', 'difficulty', 'is_featured',
                'seo_title_bn', 'seo_title_en', 'seo_description_bn', 'seo_description_en', 'itinerary', 'includes', 'excludes', 'activities', 'trip_types',
            ])->all(),
        ];
    }
}
