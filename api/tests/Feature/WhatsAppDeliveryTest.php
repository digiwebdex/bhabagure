<?php

namespace Tests\Feature;

use App\Enums\NotificationStatus;
use App\Mail\AdminAlertMail;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\NotificationMessage;
use App\Models\NotificationTemplate;
use App\Services\Notifications\NotificationDelivery;
use App\Services\Notifications\NotificationSettings;
use App\Services\Notifications\SendResult;
use App\Services\Notifications\WhatsApp\FakeWhatsAppGateway;
use App\Services\Notifications\WhatsApp\WaSenderGateway;
use App\Services\Notifications\WhatsApp\WhatsAppGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\SendsNotifications;
use Tests\TestCase;

/** docs/phase-4-whatsapp.md §3 and §6: sending through WaSenderAPI safely, and what its webhooks change. */
class WhatsAppDeliveryTest extends TestCase
{
    use RefreshDatabase, SendsNotifications;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sendNotifications();
    }

    #[Test]
    public function the_wasender_client_sends_in_its_documented_shape_and_reads_its_answers(): void
    {
        Http::fake([
            'www.wasenderapi.com/api/send-message' => Http::sequence()
                ->push(['success' => true, 'data' => ['msgId' => 100045, 'jid' => '+8801711000001', 'status' => 'in_progress']])
                ->push(['message' => 'You are on a free trial. You can only send 1 message every 1 minute.', 'retry_after' => 60], 429)
                ->push(['success' => false, 'message' => 'Your Whatsapp Session is not connected please connect your session first.'], 400)
                ->push(['success' => false, 'message' => 'Validation failed', 'errors' => ['to' => ['The to field is invalid.']]], 422),
            'www.wasenderapi.com/api/status' => Http::response(['status' => 'need_scan']),
        ]);
        $gateway = new WaSenderGateway('https://www.wasenderapi.com/api', 'session-key-1234', 5);

        $sent = $gateway->sendText('8801711000001', "ভবঘুরে হলিডেজ · Bhabaghure Holidays\nHello");
        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://www.wasenderapi.com/api/send-message'
            && $r->hasHeader('Authorization', 'Bearer session-key-1234') && $r['to'] === '+8801711000001' && str_starts_with($r['text'], 'ভবঘুরে হলিডেজ'));
        $this->assertSame(['sent', '100045'], [$sent->outcome, $sent->messageId]);

        $limited = $gateway->sendText('8801711000001', 'x');
        $this->assertSame(['retry', 'rate_limited', 60], [$limited->outcome, $limited->error, $limited->retryAfterSeconds]);

        $disconnected = $gateway->sendText('8801711000001', 'x');
        $this->assertSame(['retry', 'session_disconnected'], [$disconnected->outcome, $disconnected->error]);

        $invalid = $gateway->sendText('880171', 'x');
        $this->assertSame(['failed', 'Validation failed'], [$invalid->outcome, $invalid->error]);

        $this->assertSame('need_scan', $gateway->sessionStatus());
    }

    #[Test]
    public function whatsapp_sends_are_paced_and_the_waiting_message_goes_out_later(): void
    {
        config(['bhabaghure.notifications.whatsapp.seconds_between_sends' => 5]);
        $first = $this->pendingWhatsApp('Message one');
        $second = $this->pendingWhatsApp('Message two');

        app(NotificationDelivery::class)->deliver($first->id);
        app(NotificationDelivery::class)->deliver($second->id);

        $this->assertSame(NotificationStatus::Sent, $first->fresh()->status);
        $this->assertSame([NotificationStatus::Pending, 'paced'], [$second->fresh()->status, $second->fresh()->last_error]);
        $this->assertTrue($second->fresh()->scheduled_for->isFuture());

        $this->travel(6)->seconds();
        $this->artisan('notifications:dispatch')->assertSuccessful();
        $this->assertSame(NotificationStatus::Sent, $second->fresh()->status);
        $this->assertCount(2, FakeWhatsAppGateway::$sent);
    }

    #[Test]
    public function rate_limits_and_a_disconnected_session_reschedule_and_a_bad_number_fails(): void
    {
        $gateway = new class implements WhatsAppGateway
        {
            public array $results = [];

            public function name(): string
            {
                return 'stub';
            }

            public function sendText(string $to, string $text): SendResult
            {
                return array_shift($this->results);
            }

            public function sendDocument(string $to, string $documentUrl, string $fileName, string $caption): SendResult
            {
                return array_shift($this->results);
            }

            public function sessionStatus(): string
            {
                return 'connected';
            }

            public function messageInfo(string $providerMessageId): ?array
            {
                return null;
            }
        };
        $gateway->results = [SendResult::retry('rate_limited', 60), SendResult::retry('session_disconnected', 300), SendResult::failed('invalid WhatsApp number')];
        $this->app->instance(WhatsAppGateway::class, $gateway);

        $row = $this->pendingWhatsApp('Hello');
        app(NotificationDelivery::class)->deliver($row->id);
        $this->assertSame([NotificationStatus::Pending, 'rate_limited', 1], [$row->fresh()->status, $row->fresh()->last_error, $row->fresh()->attempts]);
        $this->assertEqualsWithDelta(now()->addMinute()->getTimestamp(), $row->fresh()->scheduled_for->getTimestamp(), 2);

        $this->travel(61)->seconds();
        app(NotificationDelivery::class)->deliver($row->id);
        $this->assertSame([NotificationStatus::Pending, 'session_disconnected'], [$row->fresh()->status, $row->fresh()->last_error]);

        $this->travel(301)->seconds();
        app(NotificationDelivery::class)->deliver($row->id);
        $this->assertSame([NotificationStatus::Failed, 'invalid WhatsApp number', 3], [$row->fresh()->status, $row->fresh()->last_error, $row->fresh()->attempts]);
    }

    #[Test]
    public function a_customer_who_opted_out_or_a_disabled_template_is_skipped_at_send_time(): void
    {
        $customer = $this->customer();
        $row = $this->pendingWhatsApp('Hello', $customer);
        $customer->forceFill(['whatsapp_opted_out_at' => now()])->save();
        app(NotificationDelivery::class)->deliver($row->id);
        $this->assertSame([NotificationStatus::Skipped, 'opted_out'], [$row->fresh()->status, $row->fresh()->skipped_reason]);

        $customer->forceFill(['whatsapp_opted_out_at' => null])->save();
        NotificationTemplate::query()->where('event', 'payment_received')->where('channel', 'whatsapp')->update(['is_enabled' => false]);
        $templated = $this->pendingWhatsApp('Hello', $customer, event: 'payment_received');
        app(NotificationDelivery::class)->deliver($templated->id);
        $this->assertSame([NotificationStatus::Skipped, 'template_disabled'], [$templated->fresh()->status, $templated->fresh()->skipped_reason]);
        $this->assertSame([], FakeWhatsAppGateway::$sent);
    }

    #[Test]
    public function webhooks_need_the_secret_and_move_delivery_status_forward_only(): void
    {
        $row = $this->pendingWhatsApp('Hello');
        app(NotificationDelivery::class)->deliver($row->id);
        $whatsAppId = $row->fresh()->provider_whatsapp_id;
        $this->assertNotNull($whatsAppId);

        $update = fn (int $status) => ['event' => 'messages.update', 'sessionId' => 'x', 'data' => ['update' => ['status' => $status], 'key' => ['id' => $whatsAppId, 'remoteJid' => '8801711000001@s.whatsapp.net', 'fromMe' => true]]];

        $this->postJson('/api/v1/webhooks/wasender', $update(3))->assertUnauthorized();
        $this->postJson('/api/v1/webhooks/wasender', $update(3), ['X-Webhook-Signature' => 'wrong'])->assertUnauthorized();
        $this->assertSame(NotificationStatus::Sent, $row->fresh()->status);

        $this->postJson('/api/v1/webhooks/wasender', $update(3), ['X-Webhook-Signature' => self::WEBHOOK_SECRET])->assertOk();
        $this->assertSame(NotificationStatus::Delivered, $row->fresh()->status);
        $this->postJson('/api/v1/webhooks/wasender', $update(4), ['X-Webhook-Signature' => self::WEBHOOK_SECRET])->assertOk();
        $this->postJson('/api/v1/webhooks/wasender', $update(2), ['X-Webhook-Signature' => self::WEBHOOK_SECRET])->assertOk();
        $this->postJson('/api/v1/webhooks/wasender', $update(0), ['X-Webhook-Signature' => self::WEBHOOK_SECRET])->assertOk();
        $this->assertSame(NotificationStatus::Read, $row->fresh()->status);
        $this->assertNotNull($row->fresh()->read_at);

        $this->postJson('/api/v1/webhooks/wasender', ['event' => 'session.status', 'data' => ['status' => 'need_scan']], ['X-Webhook-Signature' => self::WEBHOOK_SECRET])->assertOk();
        $this->assertSame('need_scan', NotificationSettings::sessionStatus()['status']);
    }

    #[Test]
    public function a_dropped_session_is_emailed_to_notification_managers_once_an_hour_even_with_nothing_waiting(): void
    {
        $admin = $this->staff('admin');
        $this->staff('sales_agent');

        $this->artisan('notifications:check-whatsapp')->assertSuccessful();
        Mail::assertNothingSent();

        FakeWhatsAppGateway::$status = 'need_scan';
        $this->artisan('notifications:check-whatsapp')->assertSuccessful();
        $this->postJson('/api/v1/webhooks/wasender', ['event' => 'session.status', 'data' => ['status' => 'logged_out']], ['X-Webhook-Signature' => self::WEBHOOK_SECRET])->assertOk();

        Mail::assertSent(AdminAlertMail::class, 1);
        Mail::assertSent(AdminAlertMail::class, fn (AdminAlertMail $mail) => $mail->hasTo($admin->email) && str_contains($mail->alert, 'need_scan'));
        $this->assertSame('logged_out', NotificationSettings::sessionStatus()['status']);
    }

    #[Test]
    public function a_stop_reply_opts_the_customer_out_with_a_confirmation_and_start_opts_back_in(): void
    {
        $customer = $this->customer(['phone' => '8801711000001']);
        $reply = fn (string $text) => ['event' => 'messages.received', 'data' => ['messages' => [
            'key' => ['id' => 'ABC', 'remoteJid' => '8801711000001@s.whatsapp.net', 'fromMe' => false], 'message' => ['conversation' => $text],
        ]]];

        $this->postJson('/api/v1/webhooks/wasender', $reply(' STOP '), ['X-Webhook-Signature' => self::WEBHOOK_SECRET])->assertOk();
        $this->assertNotNull($customer->fresh()->whatsapp_opted_out_at);
        $this->assertStringContainsString('আমাদের স্বয়ংক্রিয় WhatsApp বার্তা আর পাঠানো হবে না', FakeWhatsAppGateway::$sent[0]['text']);
        $this->assertTrue(AuditLog::query()->where('action', 'customer.whatsapp_opted_out')->exists());

        $this->postJson('/api/v1/webhooks/wasender', $reply('চালু'), ['X-Webhook-Signature' => self::WEBHOOK_SECRET])->assertOk();
        $this->assertNull($customer->fresh()->whatsapp_opted_out_at);

        // Any other text is ignored and not stored.
        $this->postJson('/api/v1/webhooks/wasender', $reply('When is my trip?'), ['X-Webhook-Signature' => self::WEBHOOK_SECRET])->assertOk();
        $this->assertNull($customer->fresh()->whatsapp_opted_out_at);
        $this->assertFalse(NotificationMessage::query()->where('body', 'like', '%When is my trip%')->exists());
    }

    private function pendingWhatsApp(string $text, ?Customer $customer = null, string $event = 'staff_message'): NotificationMessage
    {
        $customer ??= Customer::query()->first() ?? $this->customer();

        return NotificationMessage::query()->create([
            'event' => $event, 'channel' => 'whatsapp', 'to_address' => $customer->phone, 'recipient_type' => 'customer', 'recipient_id' => $customer->id,
            'locale' => 'en', 'body' => "ভবঘুরে হলিডেজ · Bhabaghure Holidays\n{$text}", 'status' => 'pending', 'provider' => 'whatsapp',
            'scheduled_for' => now(), 'dedupe_key' => 'test:'.uniqid('', true),
        ]);
    }
}
