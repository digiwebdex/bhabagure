<?php

namespace Tests\Feature;

use App\Enums\InquiryType;
use App\Enums\StaffStatus;
use App\Models\Booking;
use App\Models\BookingTraveller;
use App\Models\Customer;
use App\Models\Inquiry;
use App\Models\Quotation;
use App\Models\Staff;
use App\Models\StaffDocument;
use App\Models\SupportTicket;
use App\Models\TravellerDocument;
use App\Services\Admin\Ownership;
use App\Services\Attendance\LeaveDesk;
use App\Services\Bonus\BonusDesk;
use App\Support\Admin\NavBadges;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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

        // Hotel quotation requests: stale and fresh in the pool, a stale one of A's.
        $staleHotelPool = $this->hotelInquiry(30);
        $this->hotelInquiry(1);
        $ownership->claim($this->hotelInquiry(27), $this->staff['agent_a']);

        // Quotations: one expiring for A; for B one expiring, one with days left, one expired and a draft.
        $this->quotation($this->staff['agent_a'], 1);
        $expiringOfB = $this->quotation($this->staff['agent_b'], 0);
        $this->quotation($this->staff['agent_b'], 5);
        $this->quotation($this->staff['agent_b'], -1);
        $this->quotation($this->staff['agent_b'], 0, Quotation::DRAFT);

        // Portal uploads waiting for review: one on a pool booking, one on A's; a verified one on B's is done.
        $this->upload($pool1);
        $this->upload($ofA);
        $this->upload($ofB, TravellerDocument::VERIFIED);

        // Support tickets: one waiting 30 hours, one 2 hours, one answered long ago — only the first is overdue.
        $overdueTicket = $this->ticket(30);
        $this->ticket(2);
        $this->ticket(50, SupportTicket::ANSWERED);

        // Staff documents: A's expired and B's expiring need attention; a valid one and a suspended leaver's don't.
        $expiredOfA = $this->staffDocument($this->staff['agent_a'], -3);
        $this->staffDocument($this->staff['agent_b'], 12);
        $this->staffDocument($this->staff['tour_operator'], 200);
        $this->staffDocument($this->staff('sales_agent', ['status' => StaffStatus::Suspended]), -40);

        // Leave: A's request is pending, B's was approved.
        $pendingLeave = app(LeaveDesk::class)->file($this->staff['agent_a'], '2026-12-01', '2026-12-02', 'Family visit', $this->staff['agent_a']);
        app(LeaveDesk::class)->approve(app(LeaveDesk::class)->file($this->staff['agent_b'], '2026-12-07', '2026-12-07', 'Doctor', $this->staff['agent_b']), true, null, $this->staff['admin']);

        // Bonus withdrawals: A's pending and B's approved are waiting; B's cancelled one isn't.
        $bonus = app(BonusDesk::class);
        foreach (['agent_a', 'agent_b'] as $agent) {
            $bonus->credit($this->staff[$agent], 5000, 'Commission', $this->staff['super_admin']);
        }
        $pendingBonus = $bonus->request($this->staff['agent_a'], 1000, null);
        $approvedBonus = $bonus->approve($bonus->request($this->staff['agent_b'], 1500, null), null, $this->staff['admin']);
        $bonus->cancel($bonus->request($this->staff['agent_b'], 600, null), $this->staff['agent_b']);

        $this->assertContract([
            'super_admin' => ['bookings' => 4, 'quotations' => 2, 'documents' => 2, 'air_inquiries' => 3, 'hotel_inquiries' => 2, 'support' => 1, 'leave_requests' => 1, 'bonus_withdrawals' => 2, 'staff_documents' => 2],
            'admin' => ['bookings' => 4, 'quotations' => 2, 'documents' => 2, 'air_inquiries' => 3, 'hotel_inquiries' => 2, 'support' => 1, 'leave_requests' => 1, 'bonus_withdrawals' => 2, 'staff_documents' => 2],
            'accountant' => ['bookings' => 4, 'quotations' => 2, 'documents' => 2, 'support' => 1],
            'tour_operator' => ['bookings' => 4, 'documents' => 2, 'support' => 1],
            'agent_a' => ['bookings' => 3, 'quotations' => 1, 'documents' => 2, 'air_inquiries' => 2, 'hotel_inquiries' => 2, 'support' => 1],
            'agent_b' => ['bookings' => 3, 'quotations' => 1, 'documents' => 1, 'air_inquiries' => 2, 'hotel_inquiries' => 1, 'support' => 1],
            'cms_only' => [],
        ]);

        // Agent A claims from both pools: A's counts stay, B loses the pool items.
        $this->actingAsApi($this->staff['agent_a'])->postJson("/api/v1/admin/bookings/{$pool1->id}/claim")->assertOk();
        $this->actingAsApi($this->staff['agent_a'])->postJson("/api/v1/admin/air-inquiries/{$stalePool->id}/claim")->assertOk();
        $this->assertContract([
            'admin' => ['bookings' => 4, 'quotations' => 2, 'documents' => 2, 'air_inquiries' => 3, 'hotel_inquiries' => 2, 'support' => 1, 'leave_requests' => 1, 'bonus_withdrawals' => 2, 'staff_documents' => 2],
            'agent_a' => ['bookings' => 3, 'quotations' => 1, 'documents' => 2, 'air_inquiries' => 2, 'hotel_inquiries' => 2, 'support' => 1],
            'agent_b' => ['bookings' => 2, 'quotations' => 1, 'documents' => 0, 'air_inquiries' => 1, 'hotel_inquiries' => 1, 'support' => 1],
        ]);

        // B quotes their stale enquiry and withdraws their expiring quotation; a pool booking is cancelled; an admin
        // hands A's booking to B.
        $this->actingAsApi($this->staff['agent_b'])->postJson("/api/v1/admin/air-inquiries/{$staleOfB->id}/quoted")->assertOk();
        $this->actingAsApi($this->staff['agent_b'])->postJson("/api/v1/admin/hotel-inquiries/{$staleHotelPool->id}/reply", ['text' => 'Sea-view rooms at ৳ 9,500 a night.'])->assertOk();
        $this->actingAsApi($this->staff['agent_b'])->postJson("/api/v1/admin/quotations/{$expiringOfB->id}/withdraw")->assertOk();
        DB::table('bookings')->where('id', $pool2->id)->update(['status' => 'cancelled']);
        $this->actingAsApi($this->staff['admin'])->postJson("/api/v1/admin/bookings/{$ofA->id}/assign", ['staff_id' => $this->staff['agent_b']->id, 'reason' => 'Rebalance'])->assertOk();
        $this->actingAsApi($this->staff['accountant'])->postJson("/api/v1/admin/support-tickets/{$overdueTicket->id}/replies", ['body' => 'The invoice now carries your company name.'])->assertOk();
        $this->actingAsApi($this->staff['admin'])->postJson("/api/v1/admin/staff-documents/{$expiredOfA->id}/archive", ['reason' => 'Renewed; the new passport is on file'])->assertOk();
        $this->actingAsApi($this->staff['admin'])->postJson("/api/v1/admin/leave-requests/{$pendingLeave->id}/approve", ['paid' => true])->assertOk();
        // A's request is rejected; B's approved one stays waiting until it is paid.
        $this->actingAsApi($this->staff['admin'])->postJson("/api/v1/admin/bonus-withdrawals/{$pendingBonus->id}/reject", ['note' => 'Next month'])->assertOk();
        $this->assertSame('approved', $approvedBonus->fresh()->status);
        $this->assertContract([
            'admin' => ['bookings' => 3, 'quotations' => 1, 'documents' => 2, 'air_inquiries' => 2, 'hotel_inquiries' => 2, 'support' => 0, 'leave_requests' => 0, 'bonus_withdrawals' => 1, 'staff_documents' => 1],
            'agent_a' => ['bookings' => 1, 'quotations' => 1, 'documents' => 1, 'air_inquiries' => 2, 'hotel_inquiries' => 1, 'support' => 0],
            'agent_b' => ['bookings' => 2, 'quotations' => 0, 'documents' => 1, 'air_inquiries' => 0, 'hotel_inquiries' => 1, 'support' => 0],
            'tour_operator' => ['bookings' => 3, 'documents' => 2, 'support' => 0],
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

    /** A sent quotation valid until today + $days (Dhaka), owned by $owner. */
    private function quotation(Staff $owner, int $days, string $status = Quotation::SENT): Quotation
    {
        $customer = Customer::query()->first() ?? $this->customer();

        $quotation = Quotation::query()->create([
            'number' => 'QT-'.Str::upper(Str::random(6)), 'customer_id' => $customer->id, 'package_title_en' => 'Mustang Valley Adventure',
            'pax_count' => 2, 'list_price' => 75000, 'unit_price' => 75000, 'subtotal_amount' => 150000, 'total_amount' => 150000,
            'valid_until' => now('Asia/Dhaka')->addDays($days)->toDateString(), 'status' => $status, 'share_token' => Str::random(40),
            'assigned_staff_id' => $owner->id, 'created_by_staff_id' => $owner->id,
        ])->forceFill(['sent_at' => $status === Quotation::DRAFT ? null : now()->subDays(3)]);
        $quotation->save();

        return $quotation;
    }

    private function upload(Booking $booking, string $status = TravellerDocument::UPLOADED): void
    {
        $traveller = BookingTraveller::query()->create(['booking_id' => $booking->id, 'is_lead' => true, 'full_name' => 'Tanvir Hasan']);
        TravellerDocument::query()->create([
            'booking_traveller_id' => $traveller->id, 'kind' => TravellerDocument::PHOTO, 'status' => $status,
            'disk' => 'local', 'path' => 'traveller-documents/test.enc', 'mime' => 'image/jpeg', 'bytes' => 1, 'source' => 'portal', 'uploaded_at' => now(),
        ]);
    }

    /** A staff document expiring $daysLeft days from today in Dhaka (negative: already expired). */
    private function staffDocument(Staff $owner, int $daysLeft): StaffDocument
    {
        return StaffDocument::query()->create([
            'staff_id' => $owner->id, 'type' => 'passport', 'expires_on' => now('Asia/Dhaka')->addDays($daysLeft)->toDateString(),
            'disk' => 'local', 'path' => 'staff-documents/test.enc', 'mime' => 'application/pdf', 'bytes' => 1,
        ]);
    }

    private function ticket(int $hoursWaiting, string $status = SupportTicket::OPEN): SupportTicket
    {
        static $number = 0;
        $number++;

        return SupportTicket::query()->create([
            'number' => sprintf('ST-%04d', $number), 'customer_id' => (Customer::query()->first() ?? $this->customer())->id,
            'subject' => 'Company name on the invoice', 'status' => $status, 'last_customer_message_at' => now()->subHours($hoursWaiting),
        ]);
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

    private function hotelInquiry(int $hoursOld): Inquiry
    {
        static $phone = 500;
        $phone++;
        $customer = Customer::query()->create(['name' => "Guest {$phone}", 'phone' => '8801811000'.$phone, 'stage' => 'lead', 'source' => 'website_form']);
        $inquiry = Inquiry::query()->create([
            'type' => InquiryType::HotelQuote, 'name' => "Guest {$phone}", 'phone' => '8801811000'.$phone, 'pax' => 2, 'locale' => 'en', 'customer_id' => $customer->id,
            'details' => ['location' => "Cox's Bazar", 'checkIn' => '2026-12-10', 'checkOut' => '2026-12-12', 'hotelCategory' => '4', 'note' => null],
        ]);
        DB::table('inquiries')->where('id', $inquiry->id)->update(['created_at' => now()->subHours($hoursOld)]);

        return $inquiry->fresh();
    }
}
