<?php

namespace Tests\Feature;

use App\Enums\NotificationChannel;
use App\Enums\NotificationEvent;
use App\Jobs\LinkWhatsAppMessageId;
use App\Mail\AdminAlertMail;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\NotificationMessage;
use App\Models\NotificationTemplate;
use App\Services\Booking\BookingCreator;
use App\Services\Booking\BookingRequest;
use App\Services\Booking\BookingStateMachine;
use App\Services\Invoices\InvoiceIssuer;
use App\Services\Ledger\LedgerService;
use App\Services\Notifications\NotificationPlanner;
use App\Services\Notifications\NotificationSettings;
use App\Services\Notifications\NotificationVariables;
use App\Services\Notifications\Sms\BulkSmsBdGateway;
use App\Services\Notifications\Sms\DisabledSmsGateway;
use App\Services\Notifications\Sms\FakeSmsGateway;
use App\Services\Notifications\Sms\SmsGateway;
use App\Services\Notifications\WhatsApp\FakeWhatsAppGateway;
use App\Services\Notifications\WhatsApp\WaSenderGateway;
use App\Services\Notifications\WhatsApp\WhatsAppGateway;
use App\Support\Sms\SmsParts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\SendsNotifications;
use Tests\TestCase;

/** docs/phase-4-whatsapp.md §10: SMS through bulksmsbd.net as a fallback and for departure day — never a parallel copy. */
class SmsChannelTest extends TestCase
{
    use RefreshDatabase, SendsNotifications;

    private const URL = 'https://bulksmsbd.net/api/smsapi';

    private const KEY = 'test-bulksmsbd-key-9f3a';

    protected function setUp(): void
    {
        parent::setUp();
        $this->sendNotifications();
    }

    #[Test]
    public function the_client_posts_the_key_in_the_form_body_over_https_and_reads_their_response_codes(): void
    {
        Http::fake([self::URL => Http::sequence()
            ->push(['response_code' => 202, 'message_id' => 59432678, 'success_message' => 'SMS Submitted Successfully', 'error_message' => ''])
            ->push(['response_code' => 1011, 'success_message' => '', 'error_message' => 'user id not found in this '.self::KEY.' key'])
            ->push(['response_code' => 1007, 'success_message' => '', 'error_message' => 'Balance Insufficient'])
            ->push(['response_code' => 1002, 'success_message' => '', 'error_message' => 'sender id not correct'])
            ->push(['response_code' => 1001, 'success_message' => '', 'error_message' => 'Invalid Number'])
            ->push(['response_code' => 1005, 'success_message' => '', 'error_message' => 'Internal Error'])
            ->push('<html>Bad gateway</html>', 502)
            ->push(['response_code' => 1999, 'error_message' => 'something new']),
        ]);
        $gateway = new BulkSmsBdGateway(self::URL, self::KEY, 'Bhabaghure');

        $sent = $gateway->send('8801711000001', 'বুকিং BH-2610-001 নিশ্চিত।');
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'POST' && $r->url() === self::URL
            && ! str_contains($r->url(), self::KEY) && $r->isForm()
            && $r['api_key'] === self::KEY && $r['number'] === '8801711000001' && $r['senderid'] === 'Bhabaghure'
            && $r['type'] === 'text' && $r['message'] === 'বুকিং BH-2610-001 নিশ্চিত।');
        $this->assertSame(['sent', '59432678'], [$sent->outcome, $sent->messageId]);

        $auth = $gateway->send('8801711000001', 'x');
        $this->assertSame(['retry', 'sms_auth_failed', 900], [$auth->outcome, $auth->error, $auth->retryAfterSeconds]);
        $this->assertStringNotContainsString(self::KEY, (string) $auth->error, 'the provider echoes the key back; only the code is kept');

        $this->assertSame(['retry', 'sms_balance_low'], [($r = $gateway->send('8801711000001', 'x'))->outcome, $r->error]);
        $this->assertSame(['retry', 'sms_sender_id_rejected'], [($r = $gateway->send('8801711000001', 'x'))->outcome, $r->error]);
        $this->assertSame(['failed', 'sms_invalid_number'], [($r = $gateway->send('8801711000001', 'x'))->outcome, $r->error]);
        $this->assertSame(['retry', 'sms_provider_error_1005'], [($r = $gateway->send('8801711000001', 'x'))->outcome, $r->error]);
        $this->assertSame(['retry', 'sms_provider_error_502'], [($r = $gateway->send('8801711000001', 'x'))->outcome, $r->error]);
        $this->assertSame(['failed', 'sms_provider_code_1999'], [($r = $gateway->send('8801711000001', 'x'))->outcome, $r->error]);
        Http::assertSentCount(8);
        Http::assertNotSent(fn (HttpRequest $r) => $r->method() === 'GET' || str_starts_with($r->url(), 'http://'));
    }

    #[Test]
    public function a_tls_failure_is_retried_and_alerted_but_never_sent_over_http_and_a_timeout_is_not_repeated(): void
    {
        Http::fake(function () {
            static $call = 0;
            $call++;
            throw new ConnectionException($call === 1
                ? 'cURL error 60: SSL certificate problem: unable to get local issuer certificate'
                : 'cURL error 28: Operation timed out after 15001 milliseconds with 0 bytes received');
        });
        $gateway = new BulkSmsBdGateway(self::URL, self::KEY, 'Bhabaghure');

        $this->assertSame(['retry', 'sms_tls_failed'], [($r = $gateway->send('8801711000001', 'x'))->outcome, $r->error]);
        $this->assertSame(['failed', 'sms_timeout_unknown'], [($r = $gateway->send('8801711000001', 'x'))->outcome, $r->error]);
        Http::assertNotSent(fn (HttpRequest $r) => str_starts_with($r->url(), 'http://'));

        $this->assertSame('sms_insecure_url', (new BulkSmsBdGateway('http://bulksmsbd.net/api/smsapi', self::KEY, 'Bhabaghure'))->send('8801711000001', 'x')->error);
    }

    #[Test]
    public function without_an_approved_sender_id_or_over_http_sms_is_disabled_and_nothing_is_sent(): void
    {
        Http::fake();
        foreach ([
            [['api_key' => self::KEY, 'sender_id' => ''], 'sms_sender_id_missing'],
            [['api_key' => '', 'sender_id' => 'Bhabaghure'], 'sms_not_configured'],
            [['api_key' => self::KEY, 'sender_id' => 'Bhabaghure', 'url' => 'http://bulksmsbd.net/api/smsapi'], 'sms_insecure_url'],
        ] as [$settings, $reason]) {
            config(['bhabaghure.notifications.sms' => array_merge(config('bhabaghure.notifications.sms'), ['mode' => 'live'], $settings)]);
            $this->app->forgetInstance(SmsGateway::class);
            $gateway = app(SmsGateway::class);
            $this->assertInstanceOf(DisabledSmsGateway::class, $gateway);
            $this->assertSame($reason, $gateway->reason());
        }

        // The departure-day SMS is recorded as not sent, with the reason — no request goes out.
        config(['bhabaghure.notifications.sms.url' => self::URL, 'bhabaghure.notifications.sms.sender_id' => '']);
        $this->app->forgetInstance(SmsGateway::class);
        $booking = $this->confirmedBooking();
        $this->travelTo(Carbon::parse('2026-10-31 06:01', 'Asia/Dhaka'));
        $this->artisan('notifications:dispatch')->assertSuccessful();

        $sms = NotificationMessage::query()->where('related_id', $booking->id)->where('event', 'departure_today')->where('channel', 'sms')->firstOrFail();
        $this->assertSame(['skipped', 'sms_sender_id_missing'], [$sms->status->value, $sms->skipped_reason]);
        Http::assertNothingSent();
    }

    #[Test]
    public function the_departure_day_message_always_goes_by_sms_as_well_and_the_review_request_never_does(): void
    {
        $booking = $this->confirmedBooking();
        $this->travelTo(Carbon::parse('2026-10-31 06:01', 'Asia/Dhaka'));
        $this->artisan('notifications:dispatch')->assertSuccessful();

        $this->assertCount(1, FakeSmsGateway::$sent);
        $this->assertSame('8801711000001', FakeSmsGateway::$sent[0]['to']);
        $this->assertSame("Your trip {$booking->reference} starts today. Have a great journey! Help: 01743939300", FakeSmsGateway::$sent[0]['text']);
        $sms = NotificationMessage::query()->where('event', 'departure_today')->where('channel', 'sms')->firstOrFail();
        $this->assertSame([1, 'gsm7', '0.35'], [$sms->sms_parts, $sms->sms_encoding, (string) $sms->cost]);
        $this->assertSame('sent', NotificationMessage::query()->where('event', 'departure_today')->where('channel', 'whatsapp')->value('status')?->value);
        $this->assertSame($sms->group_key, NotificationMessage::query()->where('event', 'departure_today')->where('channel', 'whatsapp')->value('group_key'));

        $this->travelTo(Carbon::parse('2026-11-10 12:00', 'Asia/Dhaka'));
        $this->artisan('notifications:dispatch')->assertSuccessful();
        $this->assertSame('sent', NotificationMessage::query()->where('event', 'trip_completed')->where('channel', 'whatsapp')->value('status')?->value);
        $this->assertFalse(NotificationMessage::query()->where('event', 'trip_completed')->where('channel', 'sms')->exists());
        $this->assertCount(1, FakeSmsGateway::$sent);
    }

    #[Test]
    public function while_whatsapp_is_disconnected_the_confirmation_goes_by_sms_at_once_and_whatsapp_catches_up_without_a_second_sms(): void
    {
        $this->staff('admin');
        FakeWhatsAppGateway::$status = 'logged_out';
        $booking = $this->confirmedBooking();

        $whatsApp = NotificationMessage::query()->where('event', 'booking_confirmed')->where('channel', 'whatsapp')->firstOrFail();
        $sms = NotificationMessage::query()->where('event', 'booking_confirmed')->where('channel', 'sms')->firstOrFail();
        $this->assertSame(['pending', 'session_disconnected'], [$whatsApp->status->value, $whatsApp->last_error]);
        $this->assertSame(['sent', $whatsApp->id, $whatsApp->group_key], [$sms->status->value, $sms->fallback_of_id, $sms->group_key]);
        $this->assertSame('sent', NotificationMessage::query()->where('event', 'booking_confirmed')->where('channel', 'email')->value('status')?->value);

        $invoice = Invoice::query()->where('booking_id', $booking->id)->firstOrFail();
        $this->assertMatchesRegularExpression('#^Booking '.$booking->reference.' confirmed\. Travel 31 October 2026, balance due BDT 0\. Invoice: https://api\.bhabaghure\.test/i/[A-Za-z0-9]{10}$#', $sms->body);
        $this->assertStringEndsWith($invoice->fresh()->short_code, $sms->body);
        $this->assertStringNotContainsString($invoice->share_token, $sms->body);
        Mail::assertSent(AdminAlertMail::class, fn (AdminAlertMail $m) => str_contains($m->alert, 'logged_out') || str_contains($m->alert, 'disconnected'));

        // Reconnected: WhatsApp sends on its next attempt; the SMS isn't repeated.
        FakeWhatsAppGateway::$status = 'connected';
        $this->travel(301)->seconds();
        $this->artisan('notifications:dispatch')->assertSuccessful();
        $this->assertSame('sent', $whatsApp->fresh()->status->value);
        $this->assertSame(1, NotificationMessage::query()->where('event', 'booking_confirmed')->where('channel', 'sms')->count());
        $this->assertCount(1, array_filter(FakeSmsGateway::$sent, fn ($m) => str_contains($m['text'], 'confirmed')));
    }

    #[Test]
    public function a_payment_whatsapp_that_fails_later_because_the_number_has_no_whatsapp_falls_back_to_sms_once(): void
    {
        $booking = Booking::query()->where('reference', $this->postJson('/api/v1/public/bookings', $this->payload())->json('data.reference'))->firstOrFail();
        $admin = $this->staff('admin');
        $this->actingAsApi($admin)->postJson("/api/v1/admin/bookings/{$booking->id}/invoice")->assertOk();
        Storage::fake('local');
        $this->actingAsApi($admin)->postJson("/api/v1/admin/bookings/{$booking->id}/payments", ['amount' => 50000, 'method' => 'cash', 'evidence' => $this->receipt()])->assertOk();
        $this->assertSame([], FakeSmsGateway::$sent, 'WhatsApp accepted it: no SMS yet');

        $failure = ['event' => 'message.sent', 'data' => ['success' => false, 'error' => 'Failed to send message: Invalid number JID: +8801711000001']];
        $this->postJson('/api/v1/webhooks/wasender', $failure, ['X-Webhook-Signature' => self::WEBHOOK_SECRET])->assertOk();
        $this->postJson('/api/v1/webhooks/wasender', $failure, ['X-Webhook-Signature' => self::WEBHOOK_SECRET])->assertOk();

        $whatsApp = NotificationMessage::query()->where('event', 'payment_received')->where('channel', 'whatsapp')->firstOrFail();
        $this->assertSame(['failed', 'not_on_whatsapp'], [$whatsApp->status->value, $whatsApp->last_error]);
        // The number has no WhatsApp, so the earlier "booking received" didn't arrive either; it isn't money-critical,
        // so it gets no SMS. The repeated webhook changes nothing and sends nothing more.
        $this->assertSame('not_on_whatsapp', NotificationMessage::query()->where('event', 'booking_created')->where('channel', 'whatsapp')->value('last_error'));
        $this->assertCount(1, FakeSmsGateway::$sent);
        $this->assertStringStartsWith("{$booking->reference}: payment of BDT 50,000 received. Balance due BDT 1,03,000. Invoice: https://api.bhabaghure.test/i/", FakeSmsGateway::$sent[0]['text']);
    }

    #[Test]
    public function a_delivery_error_reported_by_messages_update_also_falls_back(): void
    {
        $this->confirmedBooking();
        $whatsApp = NotificationMessage::query()->where('event', 'booking_confirmed')->where('channel', 'whatsapp')->firstOrFail();
        FakeSmsGateway::$sent = [];

        $this->postJson('/api/v1/webhooks/wasender', ['event' => 'messages.update', 'data' => [
            ['key' => ['id' => $whatsApp->provider_whatsapp_id, 'fromMe' => true], 'update' => ['status' => 'error']],
        ]], ['X-Webhook-Signature' => self::WEBHOOK_SECRET])->assertOk();

        $this->assertSame('failed', $whatsApp->fresh()->status->value);
        $this->assertCount(1, FakeSmsGateway::$sent);
    }

    #[Test]
    public function if_the_failure_webhook_never_arrives_the_status_check_after_sending_still_falls_back(): void
    {
        $this->confirmedBooking();
        $whatsApp = NotificationMessage::query()->where('event', 'booking_confirmed')->where('channel', 'whatsapp')->firstOrFail();
        FakeSmsGateway::$sent = [];
        Http::fake(['www.wasenderapi.com/api/messages/*/info' => Http::response(['success' => true, 'data' => ['msgId' => 1, 'key' => ['id' => 'WA-ABC'], 'status' => 0]])]);

        (new LinkWhatsAppMessageId($whatsApp->id))->handle(new WaSenderGateway('https://www.wasenderapi.com/api', 'session-key', 5), app(NotificationPlanner::class));

        $this->assertSame(['failed', 'whatsapp_error'], [$whatsApp->fresh()->status->value, $whatsApp->fresh()->last_error]);
        $this->assertCount(1, FakeSmsGateway::$sent);
    }

    #[Test]
    public function with_whatsapp_switched_off_only_money_critical_customer_messages_fall_back_and_a_stop_reply_is_respected(): void
    {
        config(['bhabaghure.notifications.whatsapp.mode' => 'off']);
        $this->app->forgetInstance(WhatsAppGateway::class);
        $agent = $this->verifiedStaff('sales_agent', '8801811000222');
        NotificationSettings::saveAlertRecipients(['new_booking_alert' => [$agent->id], 'new_lead_alert' => [], 'low_seat_alert' => []], $agent);

        $this->confirmedBooking();
        $channels = NotificationMessage::query()->where('channel', 'sms')->where('status', 'sent')->pluck('event')->map(fn ($e) => $e->value)->all();
        $this->assertSame(['booking_confirmed'], $channels, 'no SMS for booking received or the sales alert (the departure-day SMS waits for its day)');

        // A customer who replied STOP to WhatsApp isn't chased by SMS instead.
        Customer::query()->update(['whatsapp_opted_out_at' => now()]);
        config(['bhabaghure.notifications.whatsapp.mode' => 'fake']);
        $this->app->forgetInstance(WhatsAppGateway::class);
        $second = $this->confirmedBooking(reference: 'second');
        $this->assertSame('opted_out', NotificationMessage::query()->where('related_id', $second->id)->where('event', 'booking_confirmed')->where('channel', 'whatsapp')->value('skipped_reason'));
        $this->assertFalse(NotificationMessage::query()->where('related_id', $second->id)->where('channel', 'sms')->where('event', 'booking_confirmed')->exists());
    }

    #[Test]
    public function with_a_masking_sender_id_sms_can_be_forced_into_bangla_for_an_english_booking(): void
    {
        config(['bhabaghure.notifications.sms.locale' => 'bn']);
        $booking = $this->confirmedBooking();
        $this->travelTo(Carbon::parse('2026-10-31 06:01', 'Asia/Dhaka'));
        $this->artisan('notifications:dispatch')->assertSuccessful();

        $this->assertSame("আজ আপনার যাত্রা ({$booking->reference})। শুভ যাত্রা! প্রয়োজনে: 01743939300", FakeSmsGateway::$sent[0]['text']);
        // Only SMS is forced: the WhatsApp message stays in the booking's language.
        $this->assertStringContainsString('Dear Tanvir Hasan', (string) NotificationMessage::query()->where('event', 'departure_today')->where('channel', 'whatsapp')->value('body'));
        $this->assertSame('ucs2', NotificationMessage::query()->where('event', 'departure_today')->where('channel', 'sms')->value('sms_encoding'));
    }

    #[Test]
    public function an_sms_longer_than_the_maximum_is_not_sent(): void
    {
        config(['bhabaghure.notifications.sms.max_parts' => 2]);
        NotificationTemplate::query()->where('event', 'booking_confirmed')->where('channel', 'sms')->update(['body_en' => str_repeat('Booking {{ref}} confirmed. ', 20)]);
        FakeWhatsAppGateway::$status = 'logged_out';
        $this->confirmedBooking();

        $sms = NotificationMessage::query()->where('event', 'booking_confirmed')->where('channel', 'sms')->firstOrFail();
        $this->assertSame(['failed', 'sms_too_long', 'gsm7'], [$sms->status->value, $sms->last_error, $sms->sms_encoding]);
        $this->assertGreaterThan(2, $sms->sms_parts);
        $this->assertSame([], FakeSmsGateway::$sent);
    }

    #[Test]
    public function a_short_invoice_link_redirects_to_the_share_page_and_unknown_codes_are_404(): void
    {
        FakeWhatsAppGateway::$status = 'logged_out';
        $booking = $this->confirmedBooking();
        $invoice = Invoice::query()->where('booking_id', $booking->id)->firstOrFail();

        $this->get("/i/{$invoice->short_code}")->assertRedirect(url("/api/v1/public/invoices/{$invoice->share_token}"))
            ->assertHeader('Referrer-Policy', 'no-referrer');
        $this->get('/i/AAAAAAAAAA')->assertNotFound();
        $this->get('/i/short')->assertNotFound();
    }

    #[Test]
    public function the_editor_shows_parts_and_cost_the_overview_shows_monthly_costs_and_the_booking_card_reports_each_channel(): void
    {
        $admin = $this->staff('admin');
        FakeWhatsAppGateway::$status = 'logged_out';
        $booking = $this->confirmedBooking();
        $template = NotificationTemplate::query()->where('event', 'booking_confirmed')->where('channel', 'sms')->firstOrFail();

        $this->actingAsApi($admin)->postJson("/api/v1/admin/notification-templates/{$template->id}/preview", ['locale' => 'bn', 'body' => $template->body_bn])->assertOk()
            ->assertJsonPath('data.sms.encoding', 'ucs2')
            ->assertJsonPath('data.sms.parts', 2)
            ->assertJsonPath('data.sms.cost', 0.7)
            ->assertJsonPath('data.sms.warn', false);
        $this->actingAsApi($admin)->postJson("/api/v1/admin/notification-templates/{$template->id}/preview", ['locale' => 'bn', 'body' => str_repeat('ক', 250)])->assertOk()
            ->assertJsonPath('data.sms.parts', 4)
            ->assertJsonPath('data.sms.warn', true)
            ->assertJsonPath('data.sms.cost', 1.4);

        $this->actingAsApi($admin)->getJson('/api/v1/admin/notifications/overview')->assertOk()
            ->assertJsonPath('data.sms.provider', 'fake-sms')
            ->assertJsonPath('data.sms.disabled_reason', null)
            ->assertJsonPath('data.sms.cost_per_part', 0.35)
            ->assertJsonPath('data.costs.this_month.channels.sms.messages', 1)
            ->assertJsonPath('data.costs.this_month.channels.sms.cost', 0.35)
            ->assertJsonPath('data.costs.this_month.channels.email.cost', 0);

        $this->actingAsApi($admin)->getJson('/api/v1/admin/notifications?channel=sms&status=sent')->assertOk()
            ->assertJsonPath('data.0.cost', 0.35)->assertJsonPath('data.0.sms_parts', 1);

        $groups = collect($this->actingAsApi($admin)->getJson("/api/v1/admin/bookings/{$booking->id}")->assertOk()->json('data.notification_groups'));
        $confirmation = $groups->firstWhere('event', 'booking_confirmed');
        $this->assertSame(['pending', 'sent', 'sent'], [$confirmation['channels']['whatsapp']['status'], $confirmation['channels']['email']['status'], $confirmation['channels']['sms']['status']]);
        $this->assertSame($confirmation['channels']['whatsapp']['id'], $confirmation['channels']['sms']['fallback_of_id']);
        $created = $groups->firstWhere('event', 'booking_created');
        $this->assertNull($created['channels']['sms']);
    }

    #[Test]
    public function money_reads_bdt_in_sms_only_and_the_taka_sign_in_whatsapp_and_email_in_both_languages(): void
    {
        $booking = $this->confirmedBooking();
        $variables = app(NotificationVariables::class);
        $amounts = fn (string $locale, NotificationChannel $channel) => array_intersect_key(
            $variables->for(NotificationEvent::PaymentReceived, $booking, $locale, $channel, ['amount' => 50000]),
            array_flip(['amount', 'due']));

        $this->assertSame(['amount' => 'BDT 50,000', 'due' => 'BDT 0'], $amounts('en', NotificationChannel::Sms));
        $this->assertSame(['amount' => 'BDT ৫০,০০০', 'due' => 'BDT ০'], $amounts('bn', NotificationChannel::Sms));
        foreach ([NotificationChannel::WhatsApp, NotificationChannel::Email] as $channel) {
            $this->assertSame(['amount' => '৳ 50,000', 'due' => '৳ 0'], $amounts('en', $channel), $channel->value);
            $this->assertSame(['amount' => '৳ ৫০,০০০', 'due' => '৳ ০'], $amounts('bn', $channel), $channel->value);
        }

        // Why: the English SMS stays GSM-7 (160 characters a part); the same text with "৳" is Unicode (70).
        $due = $amounts('en', NotificationChannel::Sms)['due'];
        $this->assertSame('gsm7', SmsParts::count("Booking {$booking->reference} confirmed. Balance due {$due}.")['encoding']);
        $this->assertSame('ucs2', SmsParts::count("Booking {$booking->reference} confirmed. Balance due ৳ 0.")['encoding']);
    }

    private function confirmedBooking(string $reference = 'first'): Booking
    {
        $created = app(BookingCreator::class)->create(new BookingRequest('nepal-mustang-adventure-tour-8-days-7-nights', '2026-10-31', 1, 'twin', [], [
            ['name' => 'Tanvir Hasan', 'passportNumber' => 'A01234567', 'dateOfBirth' => '1990-04-12', 'passportExpiry' => '2030-01-31', 'phone' => '8801711000001', 'email' => "tanvir.{$reference}@example.test"],
        ], 76500, 'en', 'website', true));
        $booking = $created['booking'];
        $staff = $this->staff('admin');
        app(InvoiceIssuer::class)->issueForBooking($booking, $staff);
        DB::transaction(fn () => app(LedgerService::class)->recordPayment($booking, 76500, 'cash', 'Full', null, $staff));

        return app(BookingStateMachine::class)->confirm($booking, $staff);
    }

    private function payload(): array
    {
        return [
            'package_slug' => 'nepal-mustang-adventure-tour-8-days-7-nights', 'travel_date' => '2026-10-31', 'pax' => 2, 'room' => 'twin', 'addons' => [],
            'travellers' => [
                ['name' => 'Tanvir Hasan', 'passport_number' => 'A01234567', 'date_of_birth' => '1990-04-12', 'passport_expiry' => '2030-01-31', 'phone' => '01711000001', 'email' => 'tanvir@example.test'],
                ['name' => 'Nusrat Jahan', 'passport_number' => 'B07654321', 'date_of_birth' => '1992-08-03', 'passport_expiry' => '2031-05-01'],
            ],
            'expected_total' => 153000, 'terms_accepted' => true, 'locale' => 'en',
        ];
    }
}
