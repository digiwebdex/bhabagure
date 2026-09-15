<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Quotation;
use App\Models\TourPackage;
use Database\Seeders\ContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * docs/phase-8-visa-quotes-pricing-downloads.md §4.D: a package priced by hotel category × travellers. The grid replaces
 * the one price and the group discounts; bookings and quotations keep the category and its prices as quoted.
 */
class PriceGridTest extends TestCase
{
    use RefreshDatabase;

    private const MUSTANG = 'nepal-mustang-adventure-tour-8-days-7-nights';

    private const GRID = [
        '3' => ['1' => 95000, '2' => 75000, '4' => 70000, '6' => 66000, '10' => 62000],
        '4' => ['1' => 120000, '2' => 90000, '4' => 85000, '6' => 80000, '10' => 76000],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ContentSeeder::class);
    }

    #[Test]
    public function the_editor_saves_a_tidy_grid_whose_reference_price_replaces_the_one_price_and_the_sale(): void
    {
        $admin = $this->staff('admin');
        $package = TourPackage::query()->where('slug', self::MUSTANG)->sole();
        $payload = $this->actingAsApi($admin)->getJson("/api/v1/admin/packages/{$package->id}")->assertOk()->json('data');
        $save = fn (mixed $grid) => $this->actingAsApi($admin)->putJson("/api/v1/admin/packages/{$package->id}", [...$payload, 'price_grid' => $grid]);

        // Blank cells and rows are dropped; the list price shown elsewhere becomes basic/3-star for two travellers.
        $save(['3' => ['1' => '95000', '2' => 75000, '4' => '', '6' => null, '10' => 62000.4], '4' => self::GRID['4'], '5' => ['1' => '', '2' => null], '9' => ['1' => 5]])
            ->assertOk()
            ->assertJsonPath('data.price_grid', ['3' => ['1' => 95000, '2' => 75000, '10' => 62000], '4' => self::GRID['4']])
            ->assertJsonPath('data.regular_price', 75000)->assertJsonPath('data.sale_price', null);
        $this->getJson('/api/v1/public/packages/'.self::MUSTANG)->assertOk()->assertJsonPath('data.priceGrid.3.2', 75000)->assertJsonPath('data.salePrice', null);

        $save(['5' => ['2' => 150000]])->assertUnprocessable()->assertJsonValidationErrors('price_grid.5');
        $save(['3' => ['1' => 0]])->assertUnprocessable()->assertJsonValidationErrors('price_grid.3');

        // Emptied: priced the old way again, with the price the editor sends.
        $save(['3' => ['1' => '']])->assertOk()->assertJsonPath('data.price_grid', null);
    }

    #[Test]
    public function a_website_booking_picks_an_offered_category_and_is_priced_by_its_tier_without_the_group_discount(): void
    {
        TourPackage::query()->where('slug', self::MUSTANG)->update(['price_grid' => json_encode(self::GRID)]);

        $this->postJson('/api/v1/public/bookings', $this->booking(3, null))->assertUnprocessable()->assertJsonValidationErrors('hotel_category');
        $this->postJson('/api/v1/public/bookings', $this->booking(3, '5'))->assertUnprocessable()->assertJsonValidationErrors('hotel_category');

        // 3 travellers in 4-star pay the 2-person price, 90,000; + 2% service charge = 2,75,400.
        $this->postJson('/api/v1/public/bookings', $this->booking(3, '4', 229500))->assertStatus(409)->assertJsonPath('quote.total', 275400)
            ->assertJsonPath('quote.hotelCategory', '4')->assertJsonPath('quote.slab', ['minPax' => 2, 'discountPercent' => 0]);
        $reference = $this->postJson('/api/v1/public/bookings', $this->booking(3, '4', 275400))->assertCreated()->json('data.reference');

        $booking = Booking::query()->with('lines')->where('reference', $reference)->sole();
        $this->assertSame(['4', ['4' => self::GRID['4']], '90000.00'], [$booking->hotel_category, $booking->price_grid, $booking->unit_price]);
        $this->assertSame($booking->package_title_en.' · 4-star hotel', $booking->lines->firstWhere('kind', 'package')->title_en);

        // The draft invoice re-prices with the grid as booked, even after the package's prices change: 4 travellers → 85,000.
        TourPackage::query()->where('slug', self::MUSTANG)->update(['price_grid' => json_encode(['4' => ['1' => 1, '2' => 1, '4' => 1]])]);
        $admin = $this->staff('admin');
        $this->actingAsApi($admin)->putJson("/api/v1/admin/bookings/{$booking->id}/quote", ['pax' => 4, 'room' => 'twin', 'discount' => 0, 'vat_rate' => 2, 'expected_total' => 346800])
            ->assertOk()->assertJsonPath('data.total_amount', 346800)->assertJsonPath('data.quote_inputs.hotel_category', '4')->assertJsonPath('data.quote_inputs.grid.4.4', 85000);
        $this->assertStringEndsWith('· 4-star hotel', $booking->fresh('lines')->lines->firstWhere('kind', 'package')->title_en);
    }

    #[Test]
    public function a_solo_traveller_in_a_single_room_pays_the_one_traveller_price_and_a_group_pays_the_supplement(): void
    {
        TourPackage::query()->where('slug', self::MUSTANG)->update(['price_grid' => json_encode(self::GRID)]);

        // 95,000 + 2% = 96,900: no supplement on the 1-traveller price.
        $this->postJson('/api/v1/public/bookings', [...$this->booking(1, '3', 0), 'room' => 'single'])->assertStatus(409)
            ->assertJsonPath('quote.total', 96900)->assertJsonPath('quote.singleSupplement', 0);
        // Two in single rooms: 75,000 × 2 + 12% × 2 = 1,68,000 + 2% = 1,71,360.
        $this->postJson('/api/v1/public/bookings', [...$this->booking(2, '3', 0), 'room' => 'single'])->assertStatus(409)
            ->assertJsonPath('quote.total', 171360)->assertJsonPath('quote.singleSupplement', 18000);
        // A package without a grid is unchanged, and ignores a category.
        TourPackage::query()->where('slug', self::MUSTANG)->update(['price_grid' => null]);
        $this->postJson('/api/v1/public/bookings', $this->booking(3, '4', 0))->assertStatus(409)->assertJsonPath('quote.total', 222615)->assertJsonPath('quote.hotelCategory', null);
    }

    #[Test]
    public function a_quotation_keeps_its_category_and_prices_and_the_booking_it_becomes_does_too(): void
    {
        TourPackage::query()->where('slug', self::MUSTANG)->update(['price_grid' => json_encode(self::GRID)]);
        $agent = $this->staff('sales_agent');
        $lead = Customer::query()->create(['name' => 'Rahim Uddin', 'phone' => '8801711000321', 'stage' => 'lead', 'source' => 'facebook']);
        $payload = [
            'customer_id' => $lead->id, 'package_slug' => self::MUSTANG, 'travel_date' => now('Asia/Dhaka')->addDays(40)->toDateString(), 'pax' => 2, 'room' => 'twin',
            'addons' => [], 'discount' => 0, 'vat_rate' => 2, 'validity_days' => 7, 'locale' => 'bn', 'notes' => null,
        ];

        $this->actingAsApi($agent)->postJson('/api/v1/admin/quotations', $payload + ['expected_total' => 153000])->assertUnprocessable()->assertJsonValidationErrors('hotel_category');
        $id = $this->actingAsApi($agent)->postJson('/api/v1/admin/quotations', $payload + ['hotel_category' => '4', 'expected_total' => 183600])
            ->assertCreated()->assertJsonPath('data.hotel_category', '4')->json('data.id');
        $quotation = Quotation::query()->with('lines')->findOrFail($id);
        $this->assertSame(['4' => self::GRID['4']], $quotation->price_grid);
        $this->assertStringEndsWith('· ৪ তারকা হোটেল', (string) $quotation->lines->firstWhere('kind', 'package')->title_bn);

        $this->actingAsApi($agent)->postJson("/api/v1/admin/quotations/{$id}/send")->assertOk();
        $bookingId = $this->actingAsApi($agent)->postJson("/api/v1/admin/quotations/{$id}/convert", ['travellers' => [['name' => 'Rahim Uddin'], ['name' => 'Karima Begum']]])
            ->assertCreated()->json('data.booking.id');
        $booking = Booking::query()->findOrFail($bookingId);
        $this->assertSame(['4', ['4' => self::GRID['4']], 183600.0], [$booking->hotel_category, $booking->price_grid, (float) $booking->total_amount]);
    }

    /** @return array<string, mixed> */
    private function booking(int $pax, ?string $category, int $expectedTotal = 0): array
    {
        return array_filter([
            'package_slug' => self::MUSTANG,
            'travel_date' => now('Asia/Dhaka')->addDays(50)->toDateString(),
            'pax' => $pax,
            'room' => 'twin',
            'hotel_category' => $category,
            'addons' => [],
            'travellers' => [['name' => 'Tanvir Hasan', 'phone' => '01711000001'], ...array_fill(0, $pax - 1, [])],
            'expected_total' => $expectedTotal,
            'terms_accepted' => true,
            'locale' => 'en',
        ], fn ($value) => $value !== null);
    }
}
