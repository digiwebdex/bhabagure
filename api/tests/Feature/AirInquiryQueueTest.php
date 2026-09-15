<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Inquiry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** docs/phase-5-admin-core.md §4.7: the Air ticketing queue, fed by the website's air-ticket form. */
class AirInquiryQueueTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_website_form_feeds_the_queue_oldest_open_enquiry_first_with_its_age_and_flag(): void
    {
        $agent = $this->staff('sales_agent');
        foreach (['01711000101' => 30, '01711000102' => 3] as $phone => $hoursOld) {
            $this->postJson('/api/v1/public/air-quotes', [
                'from_place' => 'Dhaka', 'to_place' => 'Kathmandu', 'depart_on' => now('Asia/Dhaka')->addDays(20)->toDateString(),
                'passengers' => 2, 'cabin_class' => 'business', 'name' => "Passenger {$phone}", 'phone' => $phone, 'locale' => 'bn',
            ])->assertAccepted();
            DB::table('inquiries')->where('phone', '88'.$phone)->update(['created_at' => now()->subHours($hoursOld)->subMinutes(5)]);
        }

        $rows = $this->actingAsApi($agent)->getJson('/api/v1/admin/air-inquiries')->assertOk()->assertJsonPath('meta.total', 2)->json('data');
        $this->assertSame(['8801711000101', '8801711000102'], array_column($rows, 'phone'));
        $this->assertSame([30, 3], array_column($rows, 'age_hours'));
        $this->assertSame([true, false], array_column($rows, 'stale'));
        $this->assertSame(['Dhaka', 'Kathmandu', 2, 'business'], [$rows[0]['from'], $rows[0]['to'], $rows[0]['passengers'], $rows[0]['cabin_class']]);
        $this->assertSame(['claim' => true, 'mark_quoted' => true, 'undo_quoted' => false, 'assign' => false, 'reply' => true], $rows[0]['actions']);
    }

    #[Test]
    public function marking_quoted_records_who_and_when_takes_ownership_and_leaves_the_open_queue(): void
    {
        [$agent, $other] = [$this->staff('sales_agent'), $this->staff('sales_agent')];
        $admin = $this->staff('admin');
        $inquiry = $this->airInquiry();

        $this->actingAsApi($agent)->postJson("/api/v1/admin/air-inquiries/{$inquiry->id}/quoted")->assertOk()
            ->assertJsonPath('data.status', 'quoted')->assertJsonPath('data.quoted_by.id', $agent->id)
            ->assertJsonPath('data.assigned_staff.id', $agent->id)->assertJsonPath('data.actions.undo_quoted', true);
        $this->assertTrue(AuditLog::query()->where('action', 'inquiry.claimed')->where('auditable_id', $inquiry->id)->exists());
        $this->assertTrue(AuditLog::query()->where('action', 'inquiry.quoted')->where('auditable_id', $inquiry->id)->where('actor_id', $agent->id)->exists());

        $this->actingAsApi($agent)->getJson('/api/v1/admin/air-inquiries')->assertJsonPath('meta.total', 0);
        $this->actingAsApi($agent)->getJson('/api/v1/admin/air-inquiries?state=quoted')->assertJsonPath('meta.total', 1);
        $this->actingAsApi($agent)->postJson("/api/v1/admin/air-inquiries/{$inquiry->id}/quoted")->assertStatus(409)->assertJsonPath('code', 'already_quoted');

        // Someone else's enquiry is invisible to another agent; the admin can undo it, and it returns to the open queue.
        $this->actingAsApi($other)->deleteJson("/api/v1/admin/air-inquiries/{$inquiry->id}/quoted")->assertNotFound();
        $this->actingAsApi($admin)->deleteJson("/api/v1/admin/air-inquiries/{$inquiry->id}/quoted")->assertOk()
            ->assertJsonPath('data.status', 'new')->assertJsonPath('data.quoted_at', null)->assertJsonPath('data.assigned_staff.id', $agent->id);
        $this->assertTrue(AuditLog::query()->where('action', 'inquiry.quote_undone')->where('actor_id', $admin->id)->exists());
    }

    #[Test]
    public function only_roles_that_work_the_queue_can_open_it(): void
    {
        $inquiry = $this->airInquiry();
        foreach (['tour_operator', 'accountant'] as $role) {
            $staff = $this->staff($role);
            $this->actingAsApi($staff)->getJson('/api/v1/admin/air-inquiries')->assertForbidden();
            $this->actingAsApi($staff)->postJson("/api/v1/admin/air-inquiries/{$inquiry->id}/quoted")->assertForbidden();
        }
        $this->assertSame(Inquiry::OPEN, $inquiry->fresh()->status);
    }

    private function airInquiry(): Inquiry
    {
        return Inquiry::query()->create([
            'type' => 'air_quote', 'name' => 'Rafiq Islam', 'phone' => '8801711000301', 'email' => 'rafiq@example.test', 'pax' => 1, 'locale' => 'en',
            'details' => ['from' => 'Dhaka', 'to' => 'Dubai', 'departOn' => '2026-12-01', 'returnOn' => '2026-12-10', 'cabinClass' => 'economy'],
        ]);
    }
}
