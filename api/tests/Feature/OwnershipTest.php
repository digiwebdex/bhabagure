<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Staff;
use App\Models\Transaction;
use App\Services\Admin\Ownership;
use App\Services\Admin\OwnershipRefused;
use App\Services\Booking\BookingCreator;
use App\Services\Booking\BookingRequest;
use Database\Seeders\ContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesFinanceRecords;
use Tests\TestCase;

/** docs/phase-5-admin-core.md §0: who owns a booking, the shared pool, claims and audited reassignment. */
class OwnershipTest extends TestCase
{
    use CreatesFinanceRecords, RefreshDatabase;

    #[Test]
    public function a_booking_belongs_to_the_staff_member_who_made_it_and_a_website_booking_starts_in_the_pool(): void
    {
        $this->seed(ContentSeeder::class);
        $agent = $this->staff('sales_agent');

        $byStaff = app(BookingCreator::class)->create($this->request('first'), null, $agent)['booking'];
        $fromWebsite = app(BookingCreator::class)->create($this->request('second'))['booking'];

        $this->assertSame($agent->id, $byStaff->assigned_staff_id);
        $this->assertNull($fromWebsite->assigned_staff_id);
        $this->assertSame(['website_form', 'website_form'], [$fromWebsite->source, Customer::query()->find($fromWebsite->customer_id)->source]);

        $other = $this->staff('sales_agent');
        $references = fn (Staff $staff) => collect($this->actingAsApi($staff)->getJson('/api/v1/admin/bookings')->assertOk()->json('data'))->pluck('reference')->sort()->values()->all();
        $this->assertSame(collect([$byStaff->reference, $fromWebsite->reference])->sort()->values()->all(), $references($agent));
        $this->assertSame([$fromWebsite->reference], $references($other), 'another agent sees the pool, not the colleague\'s booking');
    }

    #[Test]
    public function claiming_takes_an_inquiry_from_the_pool_with_its_lead_is_audited_and_only_one_claim_wins(): void
    {
        [$first, $second] = [$this->staff('sales_agent'), $this->staff('sales_agent')];
        $customer = $this->customer(['stage' => 'lead']);
        $booking = $this->booking();

        $this->actingAsApi($first)->postJson("/api/v1/admin/bookings/{$booking->id}/claim")->assertOk()
            ->assertJsonPath('data.assigned_staff.id', $first->id)->assertJsonPath('data.claimable', false);

        $this->assertSame($first->id, $customer->fresh()->assigned_staff_id, 'the lead comes with the booking');
        $this->assertTrue(AuditLog::query()->where('action', 'booking.claimed')->where('auditable_id', $booking->id)->where('actor_id', $first->id)->exists());
        $this->assertTrue(AuditLog::query()->where('action', 'customer.claimed')->where('auditable_id', $customer->id)->exists());

        // The second agent no longer sees it at all; claiming directly is refused rather than overwriting the owner.
        $this->actingAsApi($second)->postJson("/api/v1/admin/bookings/{$booking->id}/claim")->assertNotFound();
        try {
            app(Ownership::class)->claim($booking, $second);
            $this->fail('A second claim must be refused.');
        } catch (OwnershipRefused $e) {
            $this->assertSame('not_claimable', $e->reason);
        }
        $this->assertSame($first->id, $booking->fresh()->assigned_staff_id);
    }

    #[Test]
    public function a_confirmed_website_booking_is_not_claimable_and_only_an_admin_assigns_it_with_a_reason_on_record(): void
    {
        $agent = $this->staff('sales_agent');
        $admin = $this->staff('admin');
        $booking = $this->booking();
        DB::table('bookings')->where('id', $booking->id)->update(['status' => 'confirmed']);

        $this->actingAsApi($agent)->getJson('/api/v1/admin/bookings')->assertOk()->assertJsonPath('meta.total', 0);
        $this->actingAsApi($agent)->postJson("/api/v1/admin/bookings/{$booking->id}/claim")->assertNotFound();
        $this->actingAsApi($admin)->postJson("/api/v1/admin/bookings/{$booking->id}/claim", [])->assertStatus(409)->assertJsonPath('code', 'not_claimable');
        $this->actingAsApi($agent)->postJson("/api/v1/admin/bookings/{$booking->id}/assign", ['staff_id' => $agent->id, 'reason' => 'mine'])->assertForbidden();

        $this->actingAsApi($admin)->postJson("/api/v1/admin/bookings/{$booking->id}/assign", ['staff_id' => $agent->id])->assertUnprocessable();
        $this->actingAsApi($admin)->postJson("/api/v1/admin/bookings/{$booking->id}/assign", ['staff_id' => $agent->id, 'reason' => 'Handled the customer by phone'])
            ->assertOk()->assertJsonPath('data.assigned_staff.id', $agent->id);

        $log = AuditLog::query()->where('action', 'booking.reassigned')->where('auditable_id', $booking->id)->firstOrFail();
        // assertEquals: MySQL stores JSON object keys in its own order.
        $this->assertEquals(['from' => null, 'to' => $agent->id, 'reason' => 'Handled the customer by phone'], $log->changes);
        $this->assertSame($admin->id, $log->actor_id);
        $this->actingAsApi($agent)->getJson("/api/v1/admin/bookings/{$booking->id}")->assertOk();
    }

    #[Test]
    public function reassignment_moves_the_booking_away_from_the_previous_owner_and_refuses_staff_who_cannot_own_it(): void
    {
        [$first, $second] = [$this->staff('sales_agent'), $this->staff('sales_agent')];
        $admin = $this->staff('admin');
        $inactive = $this->staff('sales_agent', ['status' => 'suspended']);
        $cms = $this->staff(null);
        $booking = $this->booking();
        app(Ownership::class)->claim($booking, $first);

        $this->actingAsApi($admin)->postJson("/api/v1/admin/bookings/{$booking->id}/assign", ['staff_id' => $second->id, 'reason' => 'First agent on leave'])->assertOk();
        $this->actingAsApi($first)->getJson("/api/v1/admin/bookings/{$booking->id}")->assertNotFound();
        $this->actingAsApi($second)->getJson("/api/v1/admin/bookings/{$booking->id}")->assertOk();
        $this->assertEquals(['from' => $first->id, 'to' => $second->id, 'reason' => 'First agent on leave'],
            AuditLog::query()->where('action', 'booking.reassigned')->latest('id')->value('changes'));

        foreach ([$inactive, $cms] as $staff) {
            $this->actingAsApi($admin)->postJson("/api/v1/admin/bookings/{$booking->id}/assign", ['staff_id' => $staff->id, 'reason' => 'Try'])
                ->assertUnprocessable()->assertJsonPath('code', 'cannot_own');
        }
        $this->actingAsApi($admin)->postJson("/api/v1/admin/bookings/{$booking->id}/assign", ['staff_id' => $second->id, 'reason' => 'Again'])
            ->assertUnprocessable()->assertJsonPath('code', 'same_owner');
        $this->actingAsApi($admin)->postJson("/api/v1/admin/bookings/{$booking->id}/assign", ['staff_id' => null, 'reason' => 'Back to the pool'])
            ->assertOk()->assertJsonPath('data.assigned_staff', null)->assertJsonPath('data.claimable', true);
    }

    #[Test]
    public function owner_filters_split_mine_from_the_pool(): void
    {
        $agent = $this->staff('sales_agent');
        $mine = $this->booking();
        $this->booking();
        app(Ownership::class)->claim($mine, $agent);

        $this->actingAsApi($agent)->getJson('/api/v1/admin/bookings?owner=mine')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $mine->id);
        $this->actingAsApi($agent)->getJson('/api/v1/admin/bookings?owner=pool')->assertOk()->assertJsonPath('meta.total', 1);
    }

    #[Test]
    public function status_chip_counts_share_the_badge_query_and_delete_is_only_for_bookings_without_money(): void
    {
        $agent = $this->staff('sales_agent');
        $admin = $this->staff('admin');
        [$pool, $mine, $other] = [$this->booking(), $this->booking(), $this->booking()];
        app(Ownership::class)->claim($mine, $agent);
        app(Ownership::class)->claim($other, $this->staff('sales_agent'));
        DB::table('bookings')->where('id', $mine->id)->update(['status' => 'confirmed']);

        $meta = $this->actingAsApi($agent)->getJson('/api/v1/admin/bookings')->assertOk()->json('meta');
        $this->assertSame(['inquiry' => 1, 'confirmed' => 1, 'completed' => 0, 'cancelled' => 0], $meta['status_counts']);
        $this->assertSame($meta['status_counts']['inquiry'], $this->actingAsApi($agent)->getJson('/api/v1/admin/nav-counts')->json('data.bookings.count'));

        // No invoice, no money: an admin may delete it (audited). With a payment recorded it must be cancelled instead.
        $this->actingAsApi($agent)->deleteJson("/api/v1/admin/bookings/{$pool->id}")->assertForbidden();
        $this->actingAsApi($admin)->getJson('/api/v1/admin/bookings')->assertJsonPath('data.2.has_payments', false);
        $this->actingAsApi($admin)->deleteJson("/api/v1/admin/bookings/{$pool->id}")->assertNoContent();
        $this->assertSoftDeleted('bookings', ['id' => $pool->id]);
        $this->assertTrue(AuditLog::query()->where('action', 'booking.deleted')->where('auditable_id', $pool->id)->exists());

        Transaction::query()->create(['booking_id' => $other->id, 'direction' => 'in', 'amount' => 5000, 'category' => 'customer_payment', 'method' => 'cash', 'description' => 'Advance', 'occurred_at' => now()]);
        $this->actingAsApi($admin)->deleteJson("/api/v1/admin/bookings/{$other->id}")->assertStatus(409)->assertJsonPath('code', 'has_payments');
        $this->assertNotSoftDeleted('bookings', ['id' => $other->id]);
    }

    private function request(string $who): BookingRequest
    {
        return new BookingRequest('nepal-mustang-adventure-tour-8-days-7-nights', '2026-10-31', 1, 'twin', [], [
            ['name' => "Traveller {$who}", 'passportNumber' => 'A01234567', 'dateOfBirth' => '1990-04-12', 'passportExpiry' => '2030-01-31',
                'phone' => $who === 'first' ? '8801711000001' : '8801711000002', 'email' => "{$who}@example.test"],
        ], 76500, 'en', 'website_form', true);
    }
}
