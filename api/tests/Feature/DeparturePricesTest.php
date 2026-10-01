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
 * docs/departure-prices.md (client, 2026-10-01): a group tour's departures each with their own price, and the one the
 * website's card shows. A booking, wherever it is made, is priced on the price of the date it travels.
 */
class DeparturePricesTest extends TestCase
{
    use RefreshDatabase;

    private const MUSTANG = 'nepal-mustang-adventure-tour-8-days-7-nights';

    private TourPackage $package;

    private PackageDeparture $october;

    private PackageDeparture $november;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ContentSeeder::class);
        // 75,000 per person (sale price) in triple sharing, the base; twin +5%, single +50% (docs/room-rates.md).
        $this->package = TourPackage::query()->where('slug', self::MUSTANG)->sole();
        $this->package->update(['trip_type' => TourPackage::GROUP_FIXED, 'single_supplement_percent' => 50, 'twin_supplement_percent' => 5]);
        $this->october = PackageDeparture::query()->create([
            'tour_package_id' => $this->package->id, 'departs_on' => now('Asia/Dhaka')->addDays(30)->toDateString(), 'seats_total' => 12, 'status' => 'scheduled',
        ]);
        $this->november = PackageDeparture::query()->create([
            'tour_package_id' => $this->package->id, 'departs_on' => now('Asia/Dhaka')->addDays(45)->toDateString(), 'seats_total' => 12, 'status' => 'scheduled',
        ]);
    }

    #[Test]
    public function staff_price_a_date_and_choose_the_one_the_card_shows(): void
    {
        $operator = $this->staff('tour_operator');
        $this->actingAsApi($operator)->putJson("/api/v1/admin/departures/{$this->november->id}", ['price' => 0] + $this->fields($this->november))
            ->assertUnprocessable()->assertJsonValidationErrors(['price']);
        $this->actingAsApi($operator)->putJson("/api/v1/admin/departures/{$this->november->id}", ['price' => 80000, 'is_featured' => true] + $this->fields($this->november))
            ->assertOk()->assertJsonPath('data.price', 80000)->assertJsonPath('data.is_featured', true);

        // The website is told each date's price (null: the package's) and which one its card shows.
        $public = collect($this->getJson('/api/v1/public/departures')->assertOk()->json('data'))->keyBy('departsOn');
        $this->assertSame([null, false], [$public[$this->october->departs_on->toDateString()]['price'], $public[$this->october->departs_on->toDateString()]['featured']]);
        $this->assertSame([80000, true], [$public[$this->november->departs_on->toDateString()]['price'], $public[$this->november->departs_on->toDateString()]['featured']]);

        // One featured date a package: featuring October takes it off November; and it can be taken off again.
        $this->actingAsApi($operator)->postJson("/api/v1/admin/departures/{$this->october->id}/feature", ['featured' => true])->assertOk()->assertJsonPath('data.is_featured', true);
        $this->assertSame([true, false], [$this->october->fresh()->is_featured, $this->november->fresh()->is_featured]);
        $this->actingAsApi($operator)->postJson("/api/v1/admin/departures/{$this->october->id}/feature", ['featured' => false])->assertOk();
        $this->assertSame(0, PackageDeparture::query()->where('is_featured', true)->count());

        // Without packages.manage: no.
        $this->actingAsApi($this->staff('sales_agent'))->postJson("/api/v1/admin/departures/{$this->october->id}/feature", ['featured' => true])->assertForbidden();
    }

    #[Test]
    public function a_booking_is_priced_on_the_date_it_travels_wherever_it_is_made(): void
    {
        $this->november->update(['price' => 80000]);

        // Two travellers in triple sharing, 2% service charge: October at the package's 75,000, November at its own 80,000.
        $this->assertSame(153000, $this->quote($this->october));
        $this->assertSame(163200, $this->quote($this->november));

        // Booked on November: the booking keeps November's price.
        $reference = $this->postJson('/api/v1/public/bookings', $this->payload($this->november) + ['expected_total' => 163200])->assertCreated()->json('data.reference');
        $booking = Booking::query()->where('reference', $reference)->sole();
        $this->assertSame([80000.0, 80000.0, 163200.0], [(float) $booking->list_price, (float) $booking->unit_price, (float) $booking->total_amount]);

        // A coupon is worked out on the same price (the website sends the date).
        $check = $this->postJson('/api/v1/public/coupons/check', [
            'code' => 'NOSUCHCODE', 'package_slug' => self::MUSTANG, 'pax' => 2, 'room' => 'triple', 'addons' => [], 'locale' => 'en',
            'travel_date' => $this->november->departs_on->toDateString(),
        ])->assertOk()->json('data');
        $this->assertSame([160000, 163200], [$check['subtotal'], $check['total']]);

        // At the office: the booking form is told each date's price, and a walk-in booking is priced on it.
        $agent = $this->staff('sales_agent');
        $options = collect($this->actingAsApi($agent)->getJson('/api/v1/admin/bookings/options')->assertOk()->json('data.packages'))->firstWhere('slug', self::MUSTANG);
        $this->assertSame([null, 80000], array_column($options['departures'], 'price'));
        $this->actingAsApi($agent)->postJson('/api/v1/admin/bookings', [
            'customer' => ['name' => 'Karim Uddin', 'phone' => '01711-000555', 'source' => 'walk_in'],
            'package_slug' => self::MUSTANG, 'travel_date' => $this->november->departs_on->toDateString(), 'pax' => 2, 'room' => 'triple', 'addons' => [],
            'travellers' => [['name' => 'Karim Uddin'], ['name' => 'Salma Begum']], 'expected_total' => 163200, 'locale' => 'bn',
        ])->assertCreated()->assertJsonPath('data.total_amount', 163200);
    }

    #[Test]
    public function the_brochure_says_each_dates_price_and_prices_the_rooms_for_the_featured_date(): void
    {
        $this->november->update(['price' => 80000, 'is_featured' => true]);
        $this->package->load(['destination', 'itineraryDays', 'inclusions']);
        $html = app(BrochurePdf::class)->packageHtml($this->package, null, 2, 'en');

        // Rooms for November (the featured date): triple 80,000, twin +5% 84,000, single +50% 1,20,000.
        foreach (['৳ 80,000', '৳ 84,000', '৳ 1,20,000', '(৳ 75,000)', '(৳ 80,000)'] as $price) {
            $this->assertStringContainsString($price, $html);
        }
    }

    private function quote(PackageDeparture $departure): int
    {
        return (int) $this->postJson('/api/v1/public/bookings', $this->payload($departure) + ['expected_total' => 0])->assertStatus(409)->json('quote.total');
    }

    /** @return array<string, mixed> */
    private function payload(PackageDeparture $departure): array
    {
        return [
            'package_slug' => self::MUSTANG,
            'travel_date' => $departure->departs_on->toDateString(),
            'pax' => 2,
            'room' => 'triple',
            'addons' => [],
            'travellers' => [['name' => 'Tanvir Hasan', 'phone' => '01711-000001'], []],
            'terms_accepted' => true,
            'locale' => 'en',
        ];
    }

    /** @return array<string, mixed> */
    private function fields(PackageDeparture $departure): array
    {
        return ['departs_on' => $departure->departs_on->toDateString(), 'seats_total' => $departure->seats_total, 'status' => $departure->status];
    }
}
