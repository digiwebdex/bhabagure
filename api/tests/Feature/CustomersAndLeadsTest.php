<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\BookingTraveller;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Services\Admin\Ownership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesFinanceRecords;
use Tests\TestCase;

/** docs/phase-5-admin-core.md §4.4: the lead board, derived lead states, the contact log, lost leads and visibility. */
class CustomersAndLeadsTest extends TestCase
{
    use CreatesFinanceRecords, RefreshDatabase;

    #[Test]
    public function a_website_enquiry_becomes_a_pool_lead_and_states_follow_what_happens_to_it(): void
    {
        $agent = $this->staff('sales_agent');
        $this->postJson('/api/v1/public/inquiries', ['name' => 'Rumana Islam', 'phone' => '01711000901', 'message' => 'Maldives in December?', 'locale' => 'en'])->assertAccepted();
        $lead = Customer::query()->where('phone', '8801711000901')->firstOrFail();
        $this->assertSame(['lead', 'website_form', null], [$lead->stage, $lead->source, $lead->assigned_staff_id]);

        $state = fn () => $this->actingAsApi($agent)->getJson("/api/v1/admin/customers/{$lead->id}")->assertOk()->json('data.lead_state');
        $this->assertSame('new', $state());
        // What they wrote is on the lead's profile, not only in a notification.
        $this->postJson('/api/v1/public/inquiries', ['name' => 'Rumana Islam', 'phone' => '01711000901', 'travellers' => 4, 'message' => 'And Thailand for four?', 'locale' => 'en'])->assertAccepted();
        $this->actingAsApi($agent)->getJson("/api/v1/admin/customers/{$lead->id}")->assertOk()
            ->assertJsonCount(2, 'data.enquiries')
            ->assertJsonPath('data.enquiries.0.type', 'contact')->assertJsonPath('data.enquiries.0.message', 'And Thailand for four?')->assertJsonPath('data.enquiries.0.pax', 4)
            ->assertJsonPath('data.enquiries.1.message', 'Maldives in December?');

        // A pool lead is claimed before it is worked.
        $this->actingAsApi($agent)->postJson("/api/v1/admin/customers/{$lead->id}/contacts", ['channel' => 'call', 'outcome' => 'reached'])->assertStatus(409)->assertJsonPath('code', 'claim_first');
        $this->actingAsApi($agent)->postJson("/api/v1/admin/customers/{$lead->id}/claim")->assertOk()->assertJsonPath('data.assigned_staff.id', $agent->id);

        $followUp = now()->addDays(2)->toIso8601String();
        $this->actingAsApi($agent)->postJson("/api/v1/admin/customers/{$lead->id}/contacts", ['channel' => 'whatsapp', 'outcome' => 'call_back', 'note' => 'Wants prices for 2', 'next_follow_up_at' => $followUp])
            ->assertCreated()->assertJsonPath('data.lead_state', 'contacted')->assertJsonPath('data.contacts.0.note', 'Wants prices for 2')
            ->assertJsonPath('data.contacts.0.staff.id', $agent->id)->assertJsonPath('data.follow_up_overdue', false);

        // The log is append-only.
        $this->expectExceptionMessage('append-only');
        CustomerContact::query()->firstOrFail()->update(['note' => 'rewritten']);
    }

    #[Test]
    public function the_board_counts_each_state_and_shows_only_what_the_staff_member_may_see(): void
    {
        [$agent, $colleague] = [$this->staff('sales_agent'), $this->staff('sales_agent')];
        $admin = $this->staff('admin');
        $new = $this->lead('8801711000911');
        $contacted = $this->lead('8801711000912', $agent->id);
        CustomerContact::query()->create(['customer_id' => $contacted->id, 'staff_id' => $agent->id, 'channel' => 'call', 'outcome' => 'reached', 'occurred_at' => now()]);
        $theirs = $this->lead('8801711000913', $colleague->id);
        $converted = $this->lead('8801711000914', $agent->id);
        $booking = $this->booking();
        DB::table('bookings')->where('id', $booking->id)->update(['customer_id' => $converted->id, 'assigned_staff_id' => $agent->id]);
        $lost = $this->lead('8801711000915', $agent->id);
        $this->actingAsApi($agent)->postJson("/api/v1/admin/customers/{$lost->id}/lost", ['reason' => 'Chose another agency'])->assertOk()->assertJsonPath('data.lead_state', 'lost');

        $board = fn ($staff) => collect($this->actingAsApi($staff)->getJson('/api/v1/admin/customers/board')->assertOk()->json('data'))
            ->map(fn (array $column) => [$column['count'], array_column($column['cards'], 'id')])->all();

        $this->assertSame([
            'new' => [1, [$new->id]],
            'contacted' => [1, [$contacted->id]],
            'quoted' => [0, []],
            'converted' => [1, [$converted->id]],
        ], $board($agent), 'the agent\'s own leads and the pool, never the colleague\'s');
        $this->assertSame(2, $board($admin)['new'][0], 'the admin sees the colleague\'s new lead too');
        $this->assertNotContains($theirs->id, $board($agent)['new'][1]);

        // Lost leads have their own filter, with the reason on record; reopening brings it back.
        $this->actingAsApi($agent)->getJson('/api/v1/admin/customers?state=lost')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.lost_reason', 'Chose another agency');
        $this->assertTrue(AuditLog::query()->where('action', 'customer.lost')->where('auditable_id', $lost->id)->exists());
        $this->actingAsApi($agent)->deleteJson("/api/v1/admin/customers/{$lost->id}/lost")->assertOk()->assertJsonPath('data.lead_state', 'new');
    }

    #[Test]
    public function the_list_shows_passport_status_never_the_number_and_trips_and_a_known_phone_is_refused(): void
    {
        $admin = $this->staff('admin');
        $customer = $this->customer(['name' => 'Tanvir Hasan', 'phone' => '8801711000921', 'email' => 'tanvir@example.test']);
        $booking = $this->booking();
        DB::table('bookings')->where('id', $booking->id)->update(['status' => 'completed']);
        BookingTraveller::query()->create(['booking_id' => $booking->id, 'customer_id' => $customer->id, 'is_lead' => true, 'full_name' => 'Tanvir Hasan',
            'passport_number' => 'A01234567', 'passport_expiry' => now()->addMonths(3)->toDateString(), 'sort_order' => 0]);

        $row = $this->actingAsApi($admin)->getJson('/api/v1/admin/customers?stage=customer')->assertOk()->json('data.0');
        $this->assertSame(['expiring', 1], [$row['passport_status'], $row['trips_completed']]);
        $this->assertStringNotContainsString('A01234567', json_encode($row));
        $this->actingAsApi($admin)->getJson('/api/v1/admin/customers?passport=expiring')->assertJsonPath('meta.total', 1);

        $this->actingAsApi($admin)->postJson('/api/v1/admin/customers', ['name' => 'Duplicate', 'phone' => '01711-000921', 'source' => 'phone_call'])
            ->assertStatus(409)->assertJsonPath('code', 'customer_exists')->assertJsonPath('customer.id', $customer->id);
        $this->actingAsApi($admin)->postJson('/api/v1/admin/customers', ['name' => 'Mim Akter', 'phone' => '01811-000922', 'source' => 'facebook', 'interest' => 'Maldives · honeymoon'])
            ->assertCreated()->assertJsonPath('data.lead_state', 'new')->assertJsonPath('data.assigned_staff.id', $admin->id)->assertJsonPath('data.interest', 'Maldives · honeymoon');

        // A customer with bookings can't be deleted; a lead made by mistake can.
        $this->actingAsApi($admin)->deleteJson("/api/v1/admin/customers/{$customer->id}")->assertStatus(409)->assertJsonPath('code', 'has_bookings');
        $mistake = Customer::query()->where('phone', '8801811000922')->firstOrFail();
        $this->actingAsApi($admin)->deleteJson("/api/v1/admin/customers/{$mistake->id}")->assertNoContent();
        $this->assertSoftDeleted('customers', ['id' => $mistake->id]);
        $this->assertSame(1, Booking::query()->count());
    }

    #[Test]
    public function reassigning_a_lead_is_audited_and_moves_it_between_agents(): void
    {
        [$agent, $colleague] = [$this->staff('sales_agent'), $this->staff('sales_agent')];
        $admin = $this->staff('admin');
        $lead = $this->lead('8801711000931');
        app(Ownership::class)->claim($lead, $agent);

        $this->actingAsApi($agent)->postJson("/api/v1/admin/customers/{$lead->id}/assign", ['staff_id' => $colleague->id, 'reason' => 'x'])->assertForbidden();
        $this->actingAsApi($admin)->postJson("/api/v1/admin/customers/{$lead->id}/assign", ['staff_id' => $colleague->id, 'reason' => 'Speaks the customer\'s dialect'])->assertOk();
        $this->actingAsApi($agent)->getJson("/api/v1/admin/customers/{$lead->id}")->assertNotFound();
        $this->actingAsApi($colleague)->getJson("/api/v1/admin/customers/{$lead->id}")->assertOk();
        $this->assertTrue(AuditLog::query()->where('action', 'customer.reassigned')->where('auditable_id', $lead->id)->exists());
    }

    private function lead(string $phone, ?int $owner = null): Customer
    {
        return Customer::query()->create(['name' => "Lead {$phone}", 'phone' => $phone, 'stage' => 'lead', 'source' => 'facebook', 'assigned_staff_id' => $owner]);
    }
}
