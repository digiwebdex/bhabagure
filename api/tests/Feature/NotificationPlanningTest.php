<?php

namespace Tests\Feature;

use App\Enums\NotificationStatus;
use App\Mail\NotificationMail;
use App\Models\Booking;
use App\Models\NotificationMessage;
use App\Models\PackageDeparture;
use App\Models\PaymentAttempt;
use App\Models\TourPackage;
use App\Services\Booking\BookingCreator;
use App\Services\Booking\BookingRequest;
use App\Services\Booking\BookingStateMachine;
use App\Services\Invoices\InvoiceIssuer;
use App\Services\Ledger\LedgerService;
use App\Services\Notifications\NotificationSettings;
use App\Services\Notifications\WhatsApp\FakeWhatsAppGateway;
use App\Services\Payments\PaymentService;
use App\Services\Payments\SslCommerz\SslCommerzGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\SendsNotifications;
use Tests\TestCase;

/** docs/phase-4-whatsapp.md §2: which messages go to whom, on which channel, and when. */
class NotificationPlanningTest extends TestCase
{
    use RefreshDatabase, SendsNotifications;

    private const MUSTANG = 'nepal-mustang-adventure-tour-8-days-7-nights';

    private const SENDER_LINE = "ভবঘুরে হলিডেজ · Bhabaghure Holidays\n";

    protected function setUp(): void
    {
        parent::setUp();
        $this->sendNotifications();
    }

    #[Test]
    public function a_website_booking_messages_the_customer_by_whatsapp_and_email_and_alerts_the_sales_team(): void
    {
        $agent = $this->verifiedStaff('sales_agent', '8801811000222');
        NotificationSettings::saveAlertRecipients(['new_booking_alert' => [$agent->id], 'new_lead_alert' => [], 'low_seat_alert' => []], $agent);

        $booking = $this->postJson('/api/v1/public/bookings', $this->payload())->assertCreated()->json('data');

        $this->assertSame([
            ['booking_created', 'whatsapp', 'sent'], ['booking_created', 'email', 'sent'],
            ['new_booking_alert', 'whatsapp', 'sent'], ['new_booking_alert', 'email', 'sent'],
        ], $this->notificationRows());

        // Customer WhatsApp: identifies the sender on its own first line, and carries no link in a first message.
        [$toCustomer, $toAgent] = FakeWhatsAppGateway::$sent;
        $this->assertSame('8801711000001', $toCustomer['to']);
        $this->assertStringStartsWith(self::SENDER_LINE, $toCustomer['text']);
        $this->assertStringContainsString($booking['reference'], $toCustomer['text']);
        $this->assertStringContainsString('৳ 1,53,000', $toCustomer['text']);
        $this->assertStringNotContainsString('http', $toCustomer['text']);

        // The sales alert goes to the agent's verified number.
        $this->assertSame('8801811000222', $toAgent['to']);
        $this->assertStringStartsWith(self::SENDER_LINE, $toAgent['text']);

        // The email carries the private link, and names the WhatsApp number messages come from.
        Mail::assertSent(NotificationMail::class, function (NotificationMail $mail) use ($booking) {
            $html = $mail->render();

            return $mail->hasTo('tanvir@example.test')
                && str_contains($mail->notification->body, "https://bhabaghure.test/en/booking/{$booking['reference']}#t={$booking['accessToken']}")
                && str_contains($html, '01911000111') && str_contains($html, 'Bhabaghure Holidays');
        });
    }

    #[Test]
    public function nothing_goes_out_on_whatsapp_to_customers_until_the_notifications_number_is_published_but_email_does(): void
    {
        $this->sendNotifications(notificationsNumber: null);

        $this->postJson('/api/v1/public/bookings', $this->payload())->assertCreated();

        $this->assertSame([['booking_created', 'whatsapp', 'skipped'], ['booking_created', 'email', 'sent']], $this->notificationRows());
        $this->assertSame('notifications_number_not_published', NotificationMessage::query()->where('channel', 'whatsapp')->value('skipped_reason'));
        $this->assertSame([], FakeWhatsAppGateway::$sent);
    }

    #[Test]
    public function an_online_payment_that_confirms_the_booking_sends_one_confirmation_with_the_invoice_and_schedules_the_trip(): void
    {
        config(['bhabaghure.sslcommerz.mode' => 'fake']);
        $this->app->forgetInstance(SslCommerzGateway::class);
        $reference = $this->postJson('/api/v1/public/bookings', $this->payload())->json('data.reference');
        $booking = Booking::query()->where('reference', $reference)->firstOrFail();
        FakeWhatsAppGateway::$sent = [];

        $attempt = app(PaymentService::class)->start($booking, 'bkash', 153000);
        app(PaymentService::class)->settle($attempt->tran_id, "FAKE-{$attempt->tran_id}-paid", 'redirect');

        $confirmation = NotificationMessage::query()->where('event', 'booking_confirmed')->where('channel', 'whatsapp')->firstOrFail();
        $this->assertSame(NotificationStatus::Sent, $confirmation->status);
        $this->assertStringContainsString('INV-0001.pdf <', FakeWhatsAppGateway::$sent[0]['document']);
        $this->assertStringContainsString('/api/v1/public/invoices/', FakeWhatsAppGateway::$sent[0]['document']);
        Mail::assertSent(NotificationMail::class, fn (NotificationMail $mail) => $mail->notification->event->value === 'booking_confirmed' && count($mail->attachments()) === 1);

        // One message for one payment: no separate "payment received".
        $this->assertFalse(NotificationMessage::query()->where('event', 'payment_received')->exists());

        // Trip messages wait for their time (all passport numbers are in, so no documents reminder).
        $scheduled = NotificationMessage::query()->where('status', 'pending')->where('channel', 'whatsapp')->orderBy('scheduled_for')->get();
        $this->assertSame(['pre_trip_reminder', 'departure_today', 'trip_completed'], $scheduled->pluck('event')->map->value->all());
        $this->assertSame('2026-10-29 10:00', $scheduled[0]->scheduled_for->setTimezone('Asia/Dhaka')->format('Y-m-d H:i'));
        $this->assertSame('2026-10-31 06:00', $scheduled[1]->scheduled_for->setTimezone('Asia/Dhaka')->format('Y-m-d H:i'));
        $this->assertSame('2026-11-09 11:00', $scheduled[2]->scheduled_for->setTimezone('Asia/Dhaka')->format('Y-m-d H:i'));
        $this->assertTrue(PaymentAttempt::query()->whereKey($attempt->id)->where('status', 'settled')->exists());
    }

    #[Test]
    public function a_payment_recorded_by_staff_sends_payment_received_and_confirming_later_sends_the_confirmation(): void
    {
        $booking = Booking::query()->where('reference', $this->postJson('/api/v1/public/bookings', $this->payload())->json('data.reference'))->firstOrFail();
        $admin = $this->staff('admin');

        $this->actingAsApi($admin)->postJson("/api/v1/admin/bookings/{$booking->id}/invoice")->assertOk();
        Storage::fake('local');
        $this->actingAsApi($admin)->postJson("/api/v1/admin/bookings/{$booking->id}/payments", ['amount' => 50000, 'method' => 'cash', 'evidence' => $this->receipt()])->assertOk();

        $payment = NotificationMessage::query()->where('event', 'payment_received')->where('channel', 'whatsapp')->firstOrFail();
        $this->assertStringContainsString('৳ 50,000', $payment->body);
        $this->assertStringContainsString('৳ 1,03,000', $payment->body);
        $this->assertTrue(NotificationMessage::query()->where('event', 'payment_received')->where('channel', 'email')->where('status', 'sent')->exists());

        $this->actingAsApi($admin)->postJson("/api/v1/admin/bookings/{$booking->id}/confirm")->assertOk();
        $this->assertTrue(NotificationMessage::query()->where('event', 'booking_confirmed')->where('channel', 'whatsapp')->where('status', 'sent')->exists());
    }

    #[Test]
    public function a_missing_passport_is_chased_seven_days_out_only_if_it_is_still_missing(): void
    {
        $missing = $this->confirmedBooking(passportForSecond: null);
        $fixed = $this->confirmedBooking(passportForSecond: null);
        $row = NotificationMessage::query()->where('related_id', $missing->id)->where('event', 'documents_pending')->where('channel', 'whatsapp')->firstOrFail();
        $this->assertSame('2026-10-24 10:00', $row->scheduled_for->setTimezone('Asia/Dhaka')->format('Y-m-d H:i'));

        // The second booking's passport arrives before the reminder is due.
        $fixed->travellers()->where('is_lead', false)->first()->forceFill(['passport_number' => 'BX4471228'])->save();

        $this->travelTo(Carbon::parse('2026-10-24 10:01', 'Asia/Dhaka'));
        FakeWhatsAppGateway::$sent = [];
        $this->artisan('notifications:dispatch')->assertSuccessful();

        $this->assertSame('sent', $row->fresh()->status->value);
        $this->assertStringContainsString('Nusrat Jahan', $row->fresh()->body);
        $this->assertStringContainsString('01743939300', $row->fresh()->body, 'points the customer to the main office line');
        $this->assertSame('no_longer_needed', NotificationMessage::query()->where('related_id', $fixed->id)->where('event', 'documents_pending')->where('channel', 'whatsapp')->value('skipped_reason'));
    }

    #[Test]
    public function cancelling_a_booking_cancels_its_waiting_trip_messages(): void
    {
        $booking = $this->confirmedBooking(passportForSecond: 'BX4471228');
        app(BookingStateMachine::class)->cancel($booking, 'Customer changed plans', $this->staff('admin'));

        $this->assertSame(0, NotificationMessage::query()->where('related_id', $booking->id)->where('status', 'pending')->count());
        // Pre-trip reminder by WhatsApp and email; departure day by WhatsApp and always SMS; review request WhatsApp only.
        $this->assertSame(
            [['pre_trip_reminder', 'whatsapp'], ['pre_trip_reminder', 'email'], ['departure_today', 'whatsapp'], ['departure_today', 'sms'], ['trip_completed', 'whatsapp']],
            NotificationMessage::query()->where('related_id', $booking->id)->where('status', 'cancelled')->orderBy('id')->get()->map(fn ($r) => [$r->event->value, $r->channel->value])->all(),
        );
    }

    #[Test]
    public function website_forms_alert_the_sales_team_and_a_nearly_full_departure_alerts_once(): void
    {
        $agent = $this->verifiedStaff('sales_agent', '8801811000222');
        NotificationSettings::saveAlertRecipients(['new_booking_alert' => [], 'new_lead_alert' => [$agent->id], 'low_seat_alert' => [$agent->id]], $agent);

        $this->postJson('/api/v1/public/air-quotes', [
            'from_place' => 'Dhaka', 'to_place' => 'Kathmandu', 'depart_on' => '2026-10-12', 'passengers' => 2, 'cabin_class' => 'economy',
            'name' => 'Rafiq Islam', 'phone' => '01911223344',
        ])->assertAccepted();
        $lead = NotificationMessage::query()->where('event', 'new_lead_alert')->where('channel', 'whatsapp')->firstOrFail();
        $this->assertStringContainsString('Rafiq Islam 01911223344', $lead->body);
        $this->assertStringContainsString('Dhaka → Kathmandu', $lead->body);

        $package = TourPackage::query()->where('slug', self::MUSTANG)->firstOrFail();
        PackageDeparture::query()->create(['tour_package_id' => $package->id, 'departs_on' => '2026-10-31', 'seats_total' => 5, 'status' => 'scheduled']);
        $this->postJson('/api/v1/public/bookings', $this->payload())->assertCreated();
        $this->postJson('/api/v1/public/bookings', $this->payload(['travellers' => [$this->traveller('Second Group', '8801711000009'), $this->traveller('Another Person')]]))->assertStatus(201);

        $alerts = NotificationMessage::query()->where('event', 'low_seat_alert')->where('channel', 'whatsapp')->get();
        $this->assertCount(1, $alerts, 'once per departure');
        // Staff messages are in the staff member's language (Bangla by default).
        $this->assertStringContainsString('আর মাত্র ৩টি সিট খালি', $alerts[0]->body);
    }

    private function confirmedBooking(?string $passportForSecond): Booking
    {
        $created = app(BookingCreator::class)->create(new BookingRequest(self::MUSTANG, '2026-10-31', 2, 'twin', [], [
            ['name' => 'Tanvir Hasan', 'passportNumber' => 'A01234567', 'dateOfBirth' => '1990-04-12', 'passportExpiry' => '2030-01-31', 'phone' => '8801711000001', 'email' => 'tanvir@example.test'],
            ['name' => 'Nusrat Jahan', 'passportNumber' => $passportForSecond, 'dateOfBirth' => '1992-08-03', 'passportExpiry' => '2031-05-01', 'phone' => null, 'email' => null],
        ], 153000, 'en', 'website', true));
        $booking = $created['booking'];
        $staff = $this->staff('admin');
        app(InvoiceIssuer::class)->issueForBooking($booking, $staff);
        DB::transaction(fn () => app(LedgerService::class)->recordPayment($booking, 153000, 'cash', 'Full', null, $staff));

        return app(BookingStateMachine::class)->confirm($booking, $staff);
    }

    private function traveller(string $name, ?string $phone = null): array
    {
        return ['name' => $name, 'passport_number' => 'BW0912345', 'date_of_birth' => '1990-04-12', 'passport_expiry' => '2031-03-12', 'phone' => $phone];
    }

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'package_slug' => self::MUSTANG, 'travel_date' => '2026-10-31', 'pax' => 2, 'room' => 'twin', 'addons' => [],
            'travellers' => [
                ['name' => 'Tanvir Hasan', 'passport_number' => 'A01234567', 'date_of_birth' => '1990-04-12', 'passport_expiry' => '2030-01-31', 'phone' => '01711000001', 'email' => 'tanvir@example.test'],
                ['name' => 'Nusrat Jahan', 'passport_number' => 'B07654321', 'date_of_birth' => '1992-08-03', 'passport_expiry' => '2031-05-01'],
            ],
            'expected_total' => 153000, 'terms_accepted' => true, 'locale' => 'en',
        ], $overrides);
    }
}
