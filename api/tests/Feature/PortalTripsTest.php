<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\NotificationMessage;
use App\Models\PaymentAttempt;
use App\Models\Quotation;
use App\Models\Staff;
use App\Models\Transaction;
use App\Services\Invoices\InvoiceIssuer;
use App\Services\Ledger\LedgerService;
use App\Services\Payments\SslCommerz\SslCommerzGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\SendsNotifications;
use Tests\TestCase;

/**
 * docs/phase-6-customer-portal.md §3.2, §3.4, §5: a signed-in customer sees only bookings and quotations where they are
 * the account holder — someone else's answers 404, like a missing one. Figures come from the ledger; readiness and the
 * countdown are computed in Dhaka time.
 */
class PortalTripsTest extends TestCase
{
    use RefreshDatabase, SendsNotifications;

    private const MUSTANG = 'nepal-mustang-adventure-tour-8-days-7-nights';

    protected function setUp(): void
    {
        parent::setUp();
        $this->sendNotifications();
    }

    #[Test]
    public function trips_are_the_customers_own_with_the_next_trip_its_readiness_and_countdown(): void
    {
        $mine = $this->websiteBooking('01711-000001', 30);
        $past = $this->websiteBooking('01711-000001', 45);
        DB::table('bookings')->where('id', $past->id)->update(['travel_start' => now('Asia/Dhaka')->subDays(40)->toDateString(), 'travel_end' => now('Asia/Dhaka')->subDays(33)->toDateString()]);
        $theirs = $this->websiteBooking('01711-000009', 20);
        DB::table('booking_travellers')->where('booking_id', $mine->id)->where('is_lead', false)->update(['passport_number' => null, 'passport_number_hash' => null]);
        $me = $mine->customer;

        $trips = $this->actingAsApi($me)->getJson('/api/v1/portal/trips')->assertOk()->json('data');
        $this->assertSame([$mine->reference, $past->reference], array_column($trips['trips'], 'reference'));
        $this->assertSame([true, false], array_column($trips['trips'], 'upcoming'));
        $this->assertSame($mine->reference, $trips['next']['reference']);
        $this->assertSame(30, $trips['next']['daysToGo']);
        $this->assertSame([
            ['key' => 'paid', 'done' => false, 'waitingOn' => []],
            ['key' => 'passports', 'done' => false, 'waitingOn' => ['Nusrat Jahan']],
            ['key' => 'documents', 'done' => false, 'waitingOn' => ['Tanvir Hasan', 'Nusrat Jahan']],
        ], $trips['next']['readiness']['checks']);

        $this->actingAsApi($me)->getJson("/api/v1/portal/trips/{$mine->reference}")->assertOk()
            ->assertJsonPath('data.reference', $mine->reference)->assertJsonPath('data.total', 153000)
            ->assertJsonPath('data.itinerary.0.day', 1)->assertJsonPath('data.readiness.done', 0)
            ->assertJsonMissingPath('data.travellers.0.passportNumber');
        $this->actingAsApi($me)->getJson("/api/v1/portal/trips/{$theirs->reference}")->assertNotFound();
        $this->actingAsApi($me)->getJson('/api/v1/portal/trips/BH-NOPE')->assertNotFound();

        // No session, a staff token, or a customer whose portal access staff turned off.
        $this->resetAuthState();
        $this->flushHeaders();
        $this->getJson('/api/v1/portal/trips')->assertUnauthorized();
        $this->actingAsApi($this->staff())->getJson('/api/v1/portal/trips')->assertUnauthorized();
        $me->forceFill(['portal_disabled_at' => now()])->save();
        $this->actingAsApi($me)->getJson('/api/v1/portal/trips')->assertUnauthorized()->assertJsonPath('code', 'session_expired');
    }

    #[Test]
    public function payments_come_from_the_ledger_with_reversals_and_nothing_staff_only(): void
    {
        $booking = $this->websiteBooking('01711-000001', 30);
        $other = $this->websiteBooking('01711-000009', 30);
        $staff = $this->staff('accountant');
        app(InvoiceIssuer::class)->issueForBooking($booking, $staff);
        app(InvoiceIssuer::class)->issueForBooking($other, $staff);
        DB::transaction(function () use ($booking, $other, $staff) {
            $ledger = app(LedgerService::class);
            $ledger->recordPayment($booking, 50000, 'bkash', 'Counter note staff wrote', 'TRX8FK2M4QX', $staff);
            $cash = $ledger->recordPayment($booking, 20000, 'cash', 'Typed on the wrong booking', null, $staff);
            $ledger->reversePayment($cash, 'Wrong booking', $staff);
            $ledger->recordPayment($other, 10000, 'cash', 'Another customer', null, $staff);
        });

        $data = $this->actingAsApi($booking->customer)->getJson('/api/v1/portal/payments')->assertOk()->json('data');
        $this->assertSame([50000, 103000, 1], [$data['paid'], $data['due'], $data['bookings']]);
        $this->assertSame([
            ['reversal', 'cash', -20000, false],
            ['payment', 'cash', 20000, true],
            ['payment', 'bkash', 50000, false],
        ], array_map(fn (array $row) => [$row['kind'], $row['method'], $row['amount'], $row['reversed']], $data['history']));
        $this->assertSame('TRX8FK2M4QX', $data['history'][2]['externalRef']);
        $json = json_encode($data);
        foreach (['Counter note', 'Wrong booking', 'evidence', 'Another customer'] as $staffOnly) {
            $this->assertStringNotContainsString($staffOnly, $json);
        }
    }

    #[Test]
    public function opening_a_quotation_records_viewed_once_and_accepting_tells_its_owner(): void
    {
        $agent = $this->staff('sales_agent', ['email' => 'agent@example.test']);
        $me = Customer::query()->create(['name' => 'Rahim Uddin', 'phone' => '8801711000321', 'stage' => 'lead', 'source' => 'facebook']);
        $someoneElse = Customer::query()->create(['name' => 'Other Lead', 'phone' => '8801711000654', 'stage' => 'lead', 'source' => 'facebook']);
        $sent = $this->sentQuotation($agent, $me);
        $this->draftQuotation($agent, $me);
        $theirs = $this->sentQuotation($agent, $someoneElse);

        $list = $this->actingAsApi($me)->getJson('/api/v1/portal/quotations')->assertOk()->json('data');
        $this->assertSame([[$sent->number, 'sent', null, true]], array_map(fn ($q) => [$q['number'], $q['status'], $q['viewedAt'], $q['canAccept']], $list));

        $this->actingAsApi($me)->getJson("/api/v1/portal/quotations/{$sent->number}")->assertOk()
            ->assertJsonPath('data.total', 153000)->assertJsonPath('data.lines.0.kind', 'package')->assertJsonPath('data.viewedAt', fn ($v) => $v !== null);
        $this->actingAsApi($me)->getJson("/api/v1/portal/quotations/{$sent->number}")->assertOk();
        $this->assertSame(1, AuditLog::query()->where('action', 'quotation.viewed')->count());
        $this->actingAsApi($me)->getJson("/api/v1/portal/quotations/{$theirs->number}")->assertNotFound();
        $this->actingAsApi($me)->postJson("/api/v1/portal/quotations/{$theirs->number}/accept")->assertNotFound();

        $this->actingAsApi($me)->postJson("/api/v1/portal/quotations/{$sent->number}/accept")->assertOk()
            ->assertJsonPath('data.status', 'accepted')->assertJsonPath('data.canAccept', false);
        $this->assertSame(['accepted', 'portal'], [$sent->fresh()->status, $sent->fresh()->accepted_via]);
        $alert = NotificationMessage::query()->where('event', 'quote_accepted_alert')->where('channel', 'email')->firstOrFail();
        $this->assertSame([$agent->getMorphClass(), $agent->id, 'agent@example.test'], [$alert->recipient_type, $alert->recipient_id, $alert->to_address]);
        $this->actingAsApi($me)->postJson("/api/v1/portal/quotations/{$sent->number}/accept")->assertStatus(409)->assertJsonPath('code', 'not_sent');

        // Past its date it can't be accepted any more.
        $late = $this->sentQuotation($agent, $me);
        $this->travel(9)->days();
        $this->actingAsApi($me)->postJson("/api/v1/portal/quotations/{$late->number}/accept")->assertStatus(409)->assertJsonPath('code', 'expired');
    }

    #[Test]
    public function a_sent_quotation_is_reminded_once_on_its_last_day_in_dhaka(): void
    {
        $agent = $this->staff('sales_agent');
        $me = Customer::query()->create(['name' => 'Rahim Uddin', 'phone' => '8801711000321', 'email' => 'rahim@example.test', 'stage' => 'lead', 'source' => 'facebook']);
        $this->travelTo(now('Asia/Dhaka')->setTime(10, 0));
        $quotation = $this->sentQuotation($agent, $me);
        $remind = fn () => $this->artisan('quotations:remind-expiring')->assertSuccessful();
        $reminders = fn () => NotificationMessage::query()->where('event', 'quote_expiring')->orderBy('id')->get()->map(fn ($m) => $m->channel->value)->all();

        // valid_until is 7 days out: nothing on day 6 at 23:30 Dhaka, the reminder from 00:00 on day 7.
        $this->travelTo($quotation->valid_until->copy()->shiftTimezone('Asia/Dhaka')->subMinutes(30));
        $remind();
        $this->assertSame([], $reminders());
        $this->travelTo($quotation->valid_until->copy()->shiftTimezone('Asia/Dhaka')->addMinutes(5));
        $remind();
        $remind();
        $this->assertSame(['whatsapp', 'email'], $reminders());
        $this->assertNotNull($quotation->fresh()->expiry_reminded_at);
    }

    #[Test]
    public function a_payment_started_in_the_portal_returns_to_the_portal_and_a_guest_link_to_the_website(): void
    {
        config(['bhabaghure.sslcommerz.mode' => 'fake', 'bhabaghure.portal_url' => 'https://customer.bhabaghure.test']);
        $this->app->forgetInstance(SslCommerzGateway::class);
        $data = $this->postJson('/api/v1/public/bookings', $this->bookingPayload('01711-000001', 30))->assertCreated()->json('data');
        $customer = Booking::query()->where('reference', $data['reference'])->firstOrFail()->customer;

        $this->actingAsApi($customer)->postJson("/api/v1/public/bookings/{$data['reference']}/payments", ['method' => 'bkash', 'expected_total' => 153000, 'return_to' => 'portal'])->assertOk();
        $this->resetAuthState();
        $this->flushHeaders();
        $this->post('/api/v1/payments/sslcommerz/cancel', ['tran_id' => PaymentAttempt::query()->latest('id')->value('tran_id')])
            ->assertRedirect("https://customer.bhabaghure.test/en/trips/{$data['reference']}");

        // The private link has no portal session behind it: "portal" is ignored.
        $this->postJson("/api/v1/public/bookings/{$data['reference']}/payments", ['method' => 'bkash', 'expected_total' => 153000, 'return_to' => 'portal'], ['X-Booking-Token' => $data['accessToken']])->assertOk();
        $this->post('/api/v1/payments/sslcommerz/cancel', ['tran_id' => PaymentAttempt::query()->latest('id')->value('tran_id')])
            ->assertRedirect("https://bhabaghure.test/en/booking/{$data['reference']}");
    }

    private function websiteBooking(string $phone, int $daysAhead): Booking
    {
        $reference = $this->postJson('/api/v1/public/bookings', $this->bookingPayload($phone, $daysAhead))->assertCreated()->json('data.reference');

        return Booking::query()->where('reference', $reference)->firstOrFail();
    }

    private function bookingPayload(string $phone, int $daysAhead): array
    {
        return [
            'package_slug' => self::MUSTANG,
            'travel_date' => now('Asia/Dhaka')->addDays($daysAhead)->toDateString(),
            'pax' => 2,
            'room' => 'twin',
            'addons' => [],
            'travellers' => [
                ['name' => 'Tanvir Hasan', 'passport_number' => 'A01234567', 'date_of_birth' => '1990-04-12', 'passport_expiry' => '2031-01-31', 'phone' => $phone, 'email' => null],
                ['name' => 'Nusrat Jahan', 'passport_number' => 'B07654321', 'date_of_birth' => '1992-08-03', 'passport_expiry' => '2031-05-01'],
            ],
            'expected_total' => 153000,
            'terms_accepted' => true,
            'locale' => 'en',
        ];
    }

    private function draftQuotation(Staff $agent, Customer $customer): Quotation
    {
        $id = $this->actingAsApi($agent)->postJson('/api/v1/admin/quotations', [
            'customer_id' => $customer->id, 'package_slug' => self::MUSTANG, 'travel_date' => now('Asia/Dhaka')->addDays(40)->toDateString(),
            'pax' => 2, 'room' => 'twin', 'addons' => [], 'discount' => 0, 'vat_rate' => 2, 'validity_days' => 7, 'locale' => 'bn', 'notes' => null,
            'expected_total' => 153000,
        ])->assertCreated()->json('data.id');

        return Quotation::query()->findOrFail($id);
    }

    private function sentQuotation(Staff $agent, Customer $customer): Quotation
    {
        $quotation = $this->draftQuotation($agent, $customer);
        $this->actingAsApi($agent)->postJson("/api/v1/admin/quotations/{$quotation->id}/send")->assertOk();

        return $quotation->fresh();
    }
}
