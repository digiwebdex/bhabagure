<?php

namespace Tests\Feature;

use App\Enums\PaymentAttemptStatus;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\NotificationMessage;
use App\Models\PackageDeparture;
use App\Models\PaymentAttempt;
use App\Models\TourPackage;
use App\Services\Booking\BookingCreator;
use App\Services\Booking\BookingRequest;
use App\Services\Invoices\InvoiceIssuer;
use App\Services\Ledger\LedgerService;
use Database\Seeders\ContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * docs/phase-5-admin-core.md §4.2: every Dashboard number computed in Dhaka time — here at 02:00 on 1 October, which is
 * still 30 September in UTC — and each widget only for staff allowed to see it.
 */
class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private const MUSTANG = 'nepal-mustang-adventure-tour-8-days-7-nights';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ContentSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function the_widgets_follow_the_dhaka_calendar_and_the_staff_members_permissions(): void
    {
        [$admin, $accountant, $agent, $operator] = [$this->staff('admin'), $this->staff('accountant'), $this->staff('sales_agent'), $this->staff('tour_operator')];
        $package = TourPackage::query()->where('slug', self::MUSTANG)->firstOrFail();
        Carbon::setTestNow(Carbon::parse('2026-10-01 02:00', 'Asia/Dhaka'));

        // Departures: in 4 days (20 seats), in 35 days (outside the 30-day window), and yesterday.
        $soon = PackageDeparture::query()->create(['tour_package_id' => $package->id, 'departs_on' => '2026-10-05', 'seats_total' => 20, 'status' => 'scheduled']);
        PackageDeparture::query()->create(['tour_package_id' => $package->id, 'departs_on' => '2026-11-05', 'seats_total' => 20, 'status' => 'scheduled']);
        PackageDeparture::query()->create(['tour_package_id' => $package->id, 'departs_on' => '2026-09-30', 'seats_total' => 20, 'status' => 'scheduled']);

        // A confirmed booking on the soon departure, no passports, confirmed at 01:00 today; money on both sides of midnight.
        $trip = $this->booking('2026-10-05');
        $this->confirm($trip, '2026-10-01 01:00');
        $this->assertSame($soon->id, $trip->departure_id);
        DB::transaction(function () use ($trip, $admin) {
            $ledger = app(LedgerService::class);
            $ledger->recordPayment($trip, 50000, 'bkash', 'Advance', 'BK-DASH', $admin, occurredAt: Carbon::parse('2026-09-30 23:00', 'Asia/Dhaka'));
            $ledger->recordPayment($trip, 20000, 'cash', 'At the office', null, $admin, occurredAt: Carbon::parse('2026-10-01 01:30', 'Asia/Dhaka'));
        });
        // Confirmed at 01:00 on 1 September: inside last month's first two hours. One on 30 September is not.
        $this->confirm($this->booking('2026-12-01'), '2026-09-01 01:00');
        $this->confirm($this->booking('2026-12-02'), '2026-09-30 23:30');

        // Leads: one waiting two days, one new tonight, one already contacted.
        $old = Customer::query()->create(['name' => 'Old Lead', 'phone' => '8801711000401', 'stage' => 'lead', 'source' => 'facebook']);
        DB::table('customers')->where('id', $old->id)->update(['created_at' => now()->subDays(2)]);
        Customer::query()->create(['name' => 'New Lead', 'phone' => '8801711000402', 'stage' => 'lead', 'source' => 'website_form']);
        $contacted = Customer::query()->create(['name' => 'Called Lead', 'phone' => '8801711000403', 'stage' => 'lead', 'source' => 'phone_call']);
        CustomerContact::query()->create(['customer_id' => $contacted->id, 'staff_id' => $admin->id, 'channel' => 'call', 'outcome' => 'reached', 'occurred_at' => now()]);

        PaymentAttempt::query()->create(['booking_id' => $trip->id, 'gateway' => 'sslcommerz', 'tran_id' => 'T-DASH', 'amount' => 83000, 'status' => PaymentAttemptStatus::NeedsReview, 'failure_reason' => 'high_risk', 'expires_at' => now()]);
        NotificationMessage::query()->create([
            'event' => 'booking_confirmed', 'channel' => 'whatsapp', 'to_address' => '8801711000999', 'recipient_type' => 'customer', 'recipient_id' => $trip->customer_id,
            'locale' => 'bn', 'body' => 'x', 'status' => 'failed', 'failed_at' => now()->subHours(3), 'provider' => 'whatsapp', 'scheduled_for' => now(), 'dedupe_key' => 'test:dash',
        ]);

        $dashboard = $this->actingAsApi($admin)->getJson('/api/v1/admin/dashboard')->assertOk()->json('data');
        $this->assertSame(['2026-10-01', '2026-10'], [$dashboard['today'], $dashboard['month']]);
        // Collected in October: only the payment after midnight in Dhaka. Invoiced: all three invoices were issued today.
        $this->assertSame(['amount' => 20000, 'invoiced' => 459000], $dashboard['collected']);
        $this->assertSame([['Nepal', 20000]], array_map(fn ($row) => [$row['name_en'], $row['amount']], $dashboard['by_destination']));
        $this->assertSame(['confirmed' => 1, 'previous' => 1, 'awaiting_payment' => 3], $dashboard['bookings']);
        $this->assertSame(['count' => 1, 'next_date' => '2026-10-05', 'seats_left' => 18, 'groups' => 1], $dashboard['departures']);
        $this->assertSame([2, 18], [$dashboard['upcoming'][0]['booked'], $dashboard['upcoming'][0]['seats_left']]);
        // Every upcoming confirmed trip counts (three bookings × two travellers); the alert is only for the one within a week.
        $this->assertSame(6, $dashboard['passports_missing']);
        $this->assertSame(['new' => 2, 'waiting_over_24h' => 1], $dashboard['leads']);
        $this->assertCount(3, $dashboard['recent_bookings']);
        $alerts = collect($dashboard['alerts'])->keyBy('kind');
        $this->assertSame(['payments_review', 'passports_missing', 'leads_unanswered', 'notifications_failed'], $alerts->keys()->all());
        $this->assertSame([2, "/bookings/{$trip->id}", $trip->reference], [$alerts['passports_missing']['count'], $alerts['passports_missing']['link'], $alerts['passports_missing']['reference']]);

        // An accountant sees the money and bookings, not the notification alerts.
        $forAccountant = $this->actingAsApi($accountant)->getJson('/api/v1/admin/dashboard')->json('data');
        $this->assertSame(20000, $forAccountant['collected']['amount']);
        $this->assertNotContains('notifications_failed', array_column($forAccountant['alerts'], 'kind'));

        // A sales agent and a tour operator: no money at all — left out, not zeroed.
        foreach ([$agent, $operator] as $staff) {
            $view = $this->actingAsApi($staff)->getJson('/api/v1/admin/dashboard')->assertOk()->json('data');
            $this->assertArrayNotHasKey('collected', $view);
            $this->assertArrayNotHasKey('by_destination', $view);
            $this->assertNotContains('payments_review', array_column($view['alerts'], 'kind'));
            $this->assertArrayHasKey('departures', $view);
        }
        // The agent's bookings are theirs and the pool: none of the three confirmed bookings is either.
        $this->assertSame(0, $this->actingAsApi($agent)->getJson('/api/v1/admin/dashboard')->json('data.bookings.confirmed'));
    }

    private function booking(string $travelDate): Booking
    {
        static $phone = 500;
        $phone++;

        $booking = app(BookingCreator::class)->create(new BookingRequest(
            self::MUSTANG, $travelDate, 2, 'twin', [],
            [['name' => "Traveller {$phone}", 'passportNumber' => null, 'dateOfBirth' => null, 'passportExpiry' => null, 'phone' => '8801711000'.$phone, 'email' => null], ['name' => 'Second Traveller', 'passportNumber' => null, 'dateOfBirth' => null, 'passportExpiry' => null, 'phone' => null, 'email' => null]],
            153000, 'bn', 'website_form', true,
        ))['booking'];
        app(InvoiceIssuer::class)->issueForBooking($booking);

        return $booking->fresh();
    }

    /** Confirmed at a Dhaka time, as the state machine would leave it (status, time, seats taken from the hold). */
    private function confirm(Booking $booking, string $at): void
    {
        DB::table('bookings')->where('id', $booking->id)->update(['status' => 'confirmed', 'confirmed_at' => Carbon::parse($at, 'Asia/Dhaka')->utc()]);
        DB::table('seat_holds')->where('booking_id', $booking->id)->update(['converted_at' => now()]);
    }
}
