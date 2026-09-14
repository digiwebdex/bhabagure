<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Customer;
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
