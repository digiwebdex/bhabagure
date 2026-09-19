<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Invoice;
use Database\Seeders\ContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** docs/phase-5-admin-core.md §4.3: "+ New booking" — the website's pricing and seats, the office's customer and ownership. */
class StaffBookingTest extends TestCase
{
    use RefreshDatabase;

    private const MUSTANG = 'nepal-mustang-adventure-tour-8-days-7-nights';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ContentSeeder::class);
    }

    #[Test]
    public function the_form_options_are_for_staff_who_create_bookings(): void
    {
        $options = $this->actingAsApi($this->staff('sales_agent'))->getJson('/api/v1/admin/bookings/options')->assertOk()->json('data');
        $mustang = collect($options['packages'])->firstWhere('slug', self::MUSTANG);
        $this->assertSame(75000, $mustang['list_price']);
        $this->assertContains('walk_in', $options['sources']);
        $this->assertNotContains('b2b_agent', $options['sources']);
        $this->assertArrayHasKey('slabs', $options['config']);

        $this->actingAsApi($this->staff('accountant'))->getJson('/api/v1/admin/bookings/options')->assertForbidden();
        $this->actingAsApi($this->staff('accountant'))->postJson('/api/v1/admin/bookings', $this->payload())->assertForbidden();
    }

    #[Test]
    public function a_walk_in_lead_booked_at_the_office_belongs_to_the_staff_member_and_passports_can_follow(): void
    {
        $agent = $this->staff('sales_agent');

        $response = $this->actingAsApi($agent)->postJson('/api/v1/admin/bookings', $this->payload())->assertCreated()
            ->assertJsonPath('data.assigned_staff.id', $agent->id)->assertJsonPath('data.status', 'inquiry')
            ->assertJsonPath('data.total_amount', 153000)->assertJsonPath('data.source', 'walk_in')->assertJsonPath('data.claimable', false);

        $customer = Customer::query()->where('phone', '8801711000555')->firstOrFail();
        $this->assertSame(['lead', 'walk_in', $agent->id], [$customer->stage, $customer->source, $customer->assigned_staff_id]);
        $this->assertSame([null, null], array_column($response->json('data.travellers'), 'passport_number'));
        $this->assertSame('8801711000555', $response->json('data.travellers.0.phone'), 'the lead traveller is reached at the customer\'s number');
        $this->assertNull(Booking::query()->firstOrFail()->terms_accepted_at, 'nobody accepted the website terms');
        $this->assertTrue(AuditLog::query()->where('action', 'booking.created')->where('actor_id', $agent->id)->exists());
    }

    #[Test]
    public function the_office_books_a_number_of_travellers_and_the_names_follow_on_the_booking(): void
    {
        $agent = $this->staff('sales_agent');

        // No traveller details at all: the lead is the customer, the rest wait to be named (docs/phase-5-admin-core.md §4.3).
        $payload = $this->payload();
        unset($payload['travellers']);
        $travellers = $this->actingAsApi($agent)->postJson('/api/v1/admin/bookings', $payload)->assertCreated()->json('data.travellers');

        // The booking's language is Bangla, so the unnamed one reads as it will on the booking.
        $this->assertSame(['Karim Uddin', 'যাত্রী ২'], array_column($travellers, 'full_name'));
        $this->assertSame('8801711000555', $travellers[0]['phone'], 'the lead traveller is reached at the customer’s number');
        $this->assertSame([null, null], array_column($travellers, 'passport_number'));
    }

    #[Test]
    public function an_existing_customer_is_picked_only_from_the_staff_members_own_records_and_a_taken_number_is_refused(): void
    {
        [$agent, $colleague] = [$this->staff('sales_agent'), $this->staff('sales_agent')];
        $theirs = Customer::query()->create(['name' => 'Colleague Customer', 'phone' => '8801711000777', 'stage' => 'customer', 'source' => 'phone_call', 'assigned_staff_id' => $colleague->id]);
        $lead = Customer::query()->create(['name' => 'Pool Lead', 'phone' => '8801711000888', 'stage' => 'lead', 'source' => 'facebook']);

        $withCustomer = fn (int $id) => ['customer_id' => $id] + array_diff_key($this->payload(), ['customer' => true]);
        $this->actingAsApi($agent)->postJson('/api/v1/admin/bookings', $withCustomer($theirs->id))->assertNotFound();

        // An unowned lead comes with the booking.
        $this->actingAsApi($agent)->postJson('/api/v1/admin/bookings', $withCustomer($lead->id))->assertCreated()->assertJsonPath('data.customer.id', $lead->id);
        $this->assertSame($agent->id, $lead->fresh()->assigned_staff_id);

        // Typing a number that already belongs to someone: refused; the record is named only to someone who may see it.
        $taken = fn (string $phone) => array_replace_recursive($this->payload(), ['customer' => ['phone' => $phone]]);
        $this->actingAsApi($agent)->postJson('/api/v1/admin/bookings', $taken('01711-000777'))->assertStatus(409)->assertJsonPath('code', 'customer_exists')->assertJsonPath('customer', null);
        $this->actingAsApi($colleague)->postJson('/api/v1/admin/bookings', $taken('01711000777'))->assertStatus(409)->assertJsonPath('customer.id', $theirs->id);
        $this->assertSame(1, Booking::query()->count());
    }

    #[Test]
    public function a_total_the_staff_member_did_not_see_is_refused_with_the_current_quote(): void
    {
        $this->actingAsApi($this->staff('admin'))->postJson('/api/v1/admin/bookings', ['expected_total' => 150000] + $this->payload())
            ->assertStatus(409)->assertJsonPath('code', 'price_changed')->assertJsonPath('quote.total', 153000);
        $this->assertSame(0, Booking::query()->count());
    }

    /**
     * docs/custom-service-bookings.md (asked for 2026-09-19): instead of a package, a custom service — its name and items,
     * each at a price per person — with the service charge and VAT on top, a date only if known, and a draft invoice
     * that re-prices it from those same items.
     */
    #[Test]
    public function the_office_books_a_custom_service_with_its_own_items_and_prices(): void
    {
        $agent = $this->staff('sales_agent');
        $custom = [
            'title' => "Cox's Bazar family trip",
            'items' => [['title' => 'Hotel, 3 nights', 'unit_price' => 8000], ['title' => 'Air ticket Dhaka–Cox\'s Bazar', 'unit_price' => 6500]],
        ];
        $payload = ['custom' => $custom, 'travel_date' => null, 'room' => null, 'expected_total' => 29580] + $this->payload();
        unset($payload['package_slug']);

        // 2 travellers × (8,000 + 6,500) = 29,000; service charge and VAT 2% = 580 → 29,580.
        $this->actingAsApi($agent)->postJson('/api/v1/admin/bookings', ['expected_total' => 29000] + $payload)
            ->assertStatus(409)->assertJsonPath('code', 'price_changed')->assertJsonPath('quote.total', 29580);
        $id = $this->actingAsApi($agent)->postJson('/api/v1/admin/bookings', $payload)->assertCreated()
            ->assertJsonPath('data.is_custom', true)
            ->assertJsonPath('data.package_title_en', "Cox's Bazar family trip")
            ->assertJsonPath('data.travel_start', null)
            ->assertJsonPath('data.total_amount', 29580)
            ->assertJsonPath('data.quote_inputs.custom_items', [['title' => 'Hotel, 3 nights', 'unitPrice' => 8000], ['title' => "Air ticket Dhaka–Cox's Bazar", 'unitPrice' => 6500]])
            ->json('data.id');
        $booking = Booking::query()->with('lines')->findOrFail($id);
        $this->assertNull($booking->tour_package_id);
        $this->assertSame([['custom', 'Hotel, 3 nights', 2, '8000.00', '16000.00'], ['custom', "Air ticket Dhaka–Cox's Bazar", 2, '6500.00', '13000.00']],
            $booking->lines->sortBy('sort_order')->map(fn ($l) => [$l->kind, $l->title_en, $l->quantity, $l->unit_price, $l->amount])->values()->all());

        // The draft invoice: 3 travellers, 1,000 off, 2% → 43,500 − 1,000 = 42,500 + 850 = 43,350. The items stay.
        $admin = $this->staff('admin');
        $this->actingAsApi($admin)->putJson("/api/v1/admin/bookings/{$id}/quote", ['pax' => 3, 'room' => 'twin', 'discount' => 1000, 'vat_rate' => 2, 'expected_total' => 43350])
            ->assertOk()->assertJsonPath('data.total_amount', 43350);
        $this->assertSame([['Hotel, 3 nights', 3, '24000.00'], ["Air ticket Dhaka–Cox's Bazar", 3, '19500.00']],
            $booking->lines()->orderBy('sort_order')->get()->map(fn ($l) => [$l->title_en, $l->quantity, $l->amount])->all());

        // Issued, the invoice lists the same items.
        $this->actingAsApi($admin)->postJson("/api/v1/admin/bookings/{$id}/invoice")->assertOk();
        $this->assertSame(['Hotel, 3 nights', "Air ticket Dhaka–Cox's Bazar"], Invoice::query()->where('booking_id', $id)->firstOrFail()->items()->orderBy('sort_order')->pluck('title_en')->all());

        // A package or a custom service, not both; a custom service needs its items and whole, non-negative prices.
        $this->actingAsApi($agent)->postJson('/api/v1/admin/bookings', ['package_slug' => self::MUSTANG] + $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('package_slug');
        $this->actingAsApi($agent)->postJson('/api/v1/admin/bookings', ['custom' => ['title' => 'Visa help', 'items' => []]] + $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('custom.items');
        $this->actingAsApi($agent)->postJson('/api/v1/admin/bookings', ['custom' => ['title' => 'Visa help', 'items' => [['title' => 'Fee', 'unit_price' => -5]]]] + $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('custom.items.0.unit_price');
        // A package still needs its date.
        $this->actingAsApi($agent)->postJson('/api/v1/admin/bookings', ['travel_date' => null] + $this->payload())
            ->assertUnprocessable()->assertJsonValidationErrors('travel_date');
    }

    private function payload(): array
    {
        return [
            'customer' => ['name' => 'Karim Uddin', 'phone' => '01711-000555', 'email' => 'karim@example.test', 'source' => 'walk_in'],
            'package_slug' => self::MUSTANG,
            'travel_date' => now('Asia/Dhaka')->addDays(40)->toDateString(),
            'pax' => 2,
            'room' => 'twin',
            'addons' => [],
            'travellers' => [['name' => 'Karim Uddin'], ['name' => 'Salma Begum']],
            'expected_total' => 153000,
            'locale' => 'bn',
        ];
    }
}
