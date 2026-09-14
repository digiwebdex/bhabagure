<?php

namespace Tests\Feature;

use App\Enums\InquiryType;
use App\Models\Booking;
use App\Models\Inquiry;
use App\Models\Staff;
use App\Services\Admin\Ownership;
use App\Support\Admin\NavBadges;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesFinanceRecords;
use Tests\TestCase;

/**
 * docs/phase-5-admin-core.md §3.1: a badge is the total of the list it opens. For every role — including a sales agent
 * with their own records, someone else's and the pool — `nav-counts[key].count === GET <path>?<filter>.meta.total`,
 * and it still holds after each change that moves a count.
 */
class NavCountsContractTest extends TestCase
{
    use CreatesFinanceRecords, RefreshDatabase;

    /** @var array<string, Staff> */
    private array $staff = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['super_admin', 'admin', 'accountant', 'tour_operator'] as $role) {
            $this->staff[$role] = $this->staff($role);
        }
        $this->staff['agent_a'] = $this->staff('sales_agent');
        $this->staff['agent_b'] = $this->staff('sales_agent');
        $this->staff['cms_only'] = $this->staff(null);
    }

    #[Test]
    public function every_badge_equals_its_list_for_every_role_before_and_after_each_change(): void
    {
        $ownership = app(Ownership::class);

        // Bookings: two in the pool, one each for the agents, one confirmed website booking nobody may claim.
        [$pool1, $pool2, $ofA, $ofB, $confirmed] = [$this->booking(), $this->booking(), $this->booking(), $this->booking(), $this->booking()];
        $ownership->claim($ofA, $this->staff['agent_a']);
        $ownership->claim($ofB, $this->staff['agent_b']);
        DB::table('bookings')->where('id', $confirmed->id)->update(['status' => 'confirmed']);

        // Air-ticket enquiries: stale and fresh in the pool, a stale one each for the agents, one already quoted.
        $stalePool = $this->airInquiry(30);
        $this->airInquiry(2);
        $ownership->claim($this->airInquiry(26), $this->staff['agent_a']);
        $staleOfB = $this->airInquiry(48);
        $ownership->claim($staleOfB, $this->staff['agent_b']);
        $quoted = $this->airInquiry(40);
        $this->actingAsApi($this->staff['admin'])->postJson("/api/v1/admin/air-inquiries/{$quoted->id}/quoted")->assertOk();

        $this->assertContract([
            'super_admin' => ['bookings' => 4, 'air_inquiries' => 3],
            'admin' => ['bookings' => 4, 'air_inquiries' => 3],
            'accountant' => ['bookings' => 4],
            'tour_operator' => ['bookings' => 4],
            'agent_a' => ['bookings' => 3, 'air_inquiries' => 2],
            'agent_b' => ['bookings' => 3, 'air_inquiries' => 2],
            'cms_only' => [],
        ]);

        // Agent A claims from both pools: A's counts stay, B loses the pool items.
        $this->actingAsApi($this->staff['agent_a'])->postJson("/api/v1/admin/bookings/{$pool1->id}/claim")->assertOk();
        $this->actingAsApi($this->staff['agent_a'])->postJson("/api/v1/admin/air-inquiries/{$stalePool->id}/claim")->assertOk();
        $this->assertContract([
            'admin' => ['bookings' => 4, 'air_inquiries' => 3],
            'agent_a' => ['bookings' => 3, 'air_inquiries' => 2],
            'agent_b' => ['bookings' => 2, 'air_inquiries' => 1],
        ]);

        // B quotes their stale enquiry; a pool booking is cancelled; an admin hands A's booking to B.
        $this->actingAsApi($this->staff['agent_b'])->postJson("/api/v1/admin/air-inquiries/{$staleOfB->id}/quoted")->assertOk();
        DB::table('bookings')->where('id', $pool2->id)->update(['status' => 'cancelled']);
        $this->actingAsApi($this->staff['admin'])->postJson("/api/v1/admin/bookings/{$ofA->id}/assign", ['staff_id' => $this->staff['agent_b']->id, 'reason' => 'Rebalance'])->assertOk();
        $this->assertContract([
            'admin' => ['bookings' => 3, 'air_inquiries' => 2],
            'agent_a' => ['bookings' => 1, 'air_inquiries' => 2],
            'agent_b' => ['bookings' => 2, 'air_inquiries' => 0],
            'tour_operator' => ['bookings' => 3],
        ]);
    }

    #[Test]
    public function a_badge_counts_what_the_queue_flags_and_nothing_younger_than_24_hours(): void
    {
        $this->airInquiry(23);
        $this->airInquiry(25);

        $this->assertSame(1, NavBadges::for($this->staff['admin'])['air_inquiries']['count']);
        $flagged = collect($this->actingAsApi($this->staff['admin'])->getJson('/api/v1/admin/air-inquiries')->assertOk()->json('data'))->where('stale', true);
        $this->assertCount(1, $flagged);
    }

    /** @param array<string, array<string, int>> $expected per staff key: badge key => count; keys absent must be absent */
    private function assertContract(array $expected): void
    {
        foreach ($expected as $who => $badges) {
            $counts = $this->actingAsApi($this->staff[$who])->getJson('/api/v1/admin/nav-counts')->assertOk()->json('data');
            $this->assertSame(array_keys($badges), array_keys($counts), "{$who}: which badges");

            foreach ($counts as $key => $badge) {
                $this->assertSame($badges[$key], $badge['count'], "{$who}: {$key} count");
                $path = NavBadges::registry()[$key]['path'].'?'.http_build_query($badge['filter']);
                $total = $this->actingAsApi($this->staff[$who])->getJson($path)->assertOk()->json('meta.total');
                $this->assertSame($badge['count'], $total, "{$who}: {$key} badge must equal {$path}");
            }
        }
    }

    private function airInquiry(int $hoursOld): Inquiry
    {
        static $phone = 100;
        $phone++;
        $inquiry = Inquiry::query()->create([
            'type' => InquiryType::AirQuote, 'name' => "Passenger {$phone}", 'phone' => '8801711000'.$phone, 'pax' => 1, 'locale' => 'en',
            'details' => ['from' => 'Dhaka', 'to' => 'Bangkok', 'departOn' => '2026-11-10', 'returnOn' => null, 'cabinClass' => 'economy'],
        ]);
        DB::table('inquiries')->where('id', $inquiry->id)->update(['created_at' => now()->subHours($hoursOld)]);

        return $inquiry->fresh();
    }
}
