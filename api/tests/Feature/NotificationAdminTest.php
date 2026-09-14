<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\NotificationMessage;
use App\Models\NotificationTemplate;
use App\Models\SiteSetting;
use App\Services\Booking\BookingCreator;
use App\Services\Booking\BookingRequest;
use App\Services\Notifications\WhatsApp\FakeWhatsAppGateway;
use App\Support\Numerals;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\SendsNotifications;
use Tests\TestCase;

/** docs/phase-4-whatsapp.md §5: staff sending (numbers from records only), verification, templates and alert lists. */
class NotificationAdminTest extends TestCase
{
    use RefreshDatabase, SendsNotifications;

    private Booking $booking;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sendNotifications();
        $this->booking = app(BookingCreator::class)->create(new BookingRequest('nepal-mustang-adventure-tour-8-days-7-nights', '2026-10-31', 1, 'twin', [], [
            ['name' => 'Tanvir Hasan', 'passportNumber' => 'A01234567', 'dateOfBirth' => '1990-04-12', 'passportExpiry' => '2030-01-31', 'phone' => '8801711000001', 'email' => 'tanvir@example.test'],
        ], 76500, 'bn', 'website', true))['booking'];
        FakeWhatsAppGateway::$sent = [];
    }

    #[Test]
    public function staff_message_a_booking_they_can_see_and_the_number_always_comes_from_the_record(): void
    {
        $admin = $this->staff('admin');
        $url = '/api/v1/admin/notifications/whatsapp';

        // A typed number is refused outright — for admins too.
        $this->actingAsApi($admin)->postJson($url, ['booking_id' => $this->booking->id, 'text' => 'Hello', 'to' => '8801999999999'])
            ->assertUnprocessable()->assertJsonValidationErrors('to');
        $this->actingAsApi($admin)->postJson($url, ['booking_id' => $this->booking->id, 'text' => 'Hello', 'phone' => '01999999999'])
            ->assertUnprocessable()->assertJsonValidationErrors('phone');

        $this->actingAsApi($admin)->postJson($url, ['booking_id' => $this->booking->id, 'text' => 'Your visa appointment is on Sunday.'])
            ->assertCreated()->assertJsonPath('data.event', 'staff_message')->assertJsonPath('data.status', 'sent')->assertJsonPath('data.to', '01711•••001');

        $this->assertSame('8801711000001', FakeWhatsAppGateway::$sent[0]['to']);
        $this->assertSame("ভবঘুরে হলিডেজ · Bhabaghure Holidays\nYour visa appointment is on Sunday.", FakeWhatsAppGateway::$sent[0]['text']);
        $this->assertTrue(AuditLog::query()->where('action', 'notification.staff_whatsapp')->exists());
    }

    #[Test]
    public function permissions_visibility_opt_out_and_limits_are_enforced(): void
    {
        $url = '/api/v1/admin/notifications/whatsapp';
        $this->actingAsApi($this->staff('tour_operator'))->postJson($url, ['booking_id' => $this->booking->id, 'text' => 'Hi'])->assertForbidden();

        $agent = $this->staff('sales_agent');
        $this->actingAsApi($agent)->postJson($url, ['booking_id' => $this->booking->id, 'text' => 'Hi'])->assertNotFound();
        Booking::query()->whereKey($this->booking->id)->update(['assigned_staff_id' => $agent->id]);
        $this->actingAsApi($agent)->postJson($url, ['booking_id' => $this->booking->id, 'text' => 'Hi'])->assertCreated();

        $this->booking->customer->forceFill(['whatsapp_opted_out_at' => now()])->save();
        $this->actingAsApi($agent)->postJson($url, ['booking_id' => $this->booking->id, 'text' => 'Hi'])->assertStatus(409)->assertJsonPath('code', 'opted_out');
        $this->booking->customer->forceFill(['whatsapp_opted_out_at' => null])->save();

        for ($i = 2; $i <= 10; $i++) {
            $this->actingAsApi($agent)->postJson($url, ['booking_id' => $this->booking->id, 'text' => "Message {$i}"])->assertCreated();
        }
        $this->actingAsApi($agent)->postJson($url, ['booking_id' => $this->booking->id, 'text' => 'One too many'])->assertStatus(429);

        $this->sendNotifications(notificationsNumber: null);
        $this->actingAsApi($this->staff('admin'))->postJson($url, ['booking_id' => $this->booking->id, 'text' => 'Hi'])
            ->assertStatus(409)->assertJsonPath('code', 'notifications_number_not_published');
    }

    #[Test]
    public function a_staff_whatsapp_number_counts_only_after_the_code_sent_to_it_is_confirmed(): void
    {
        $agent = $this->staff('sales_agent');
        $admin = $this->staff('admin');

        $this->actingAsApi($agent)->postJson('/api/v1/admin/profile/whatsapp', ['number' => '01811-000222'])->assertAccepted()
            ->assertJsonPath('data.verified', false)->assertJsonPath('data.code_pending', true);
        preg_match('/(\d{6})/', FakeWhatsAppGateway::$sent[0]['text'], $m);
        $this->assertSame('8801811000222', FakeWhatsAppGateway::$sent[0]['to']);
        // Once sent, the code is gone from the stored message and from the admin log.
        $this->assertStringNotContainsString($m[1], NotificationMessage::query()->where('event', 'whatsapp_verification')->value('body'));
        $this->assertStringNotContainsString($m[1], $this->actingAsApi($admin)->getJson('/api/v1/admin/notifications')->assertOk()->getContent());

        // Only verified staff can be put on an alert list.
        $this->actingAsApi($admin)->putJson('/api/v1/admin/notification-settings', ['alert_recipients' => ['new_booking_alert' => [$agent->id]]])
            ->assertUnprocessable();

        $this->actingAsApi($agent)->postJson('/api/v1/admin/profile/whatsapp/verify', ['code' => $m[1] === '000000' ? '111111' : '000000'])->assertUnprocessable();
        $this->actingAsApi($agent)->postJson('/api/v1/admin/profile/whatsapp/verify', ['code' => $m[1]])->assertOk()->assertJsonPath('data.verified', true);

        $this->actingAsApi($admin)->putJson('/api/v1/admin/notification-settings', ['alert_recipients' => ['new_booking_alert' => [$agent->id], 'low_seat_alert' => [$agent->id]]])
            ->assertOk()->assertJsonPath('data.alert_recipients.new_booking_alert', [$agent->id])
            ->assertJsonPath('data.eligible_staff.0.id', $agent->id);
        $this->actingAsApi($agent)->getJson('/api/v1/admin/notification-settings')->assertForbidden();
    }

    #[Test]
    public function templates_reject_variables_their_event_does_not_have_and_test_sends_go_only_to_the_signed_in_staff(): void
    {
        $admin = $this->staff('admin');
        $template = NotificationTemplate::query()->where('event', 'payment_received')->where('channel', 'whatsapp')->firstOrFail();
        $url = "/api/v1/admin/notification-templates/{$template->id}";

        $this->actingAsApi($admin)->putJson($url, ['body_bn' => '{{name}} {{seats}}', 'body_en' => '{{name}}', 'is_enabled' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('body_bn');
        $this->actingAsApi($admin)->putJson($url, ['body_bn' => '{{name}}', 'body_en' => '{{name}}', 'subject_en' => 'x', 'is_enabled' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('subject_en');
        $this->actingAsApi($admin)->putJson($url, ['body_bn' => 'প্রিয় {{name}}, {{amount}} পাওয়া গেছে।', 'body_en' => 'Dear {{name}}, we received {{amount}}.', 'is_enabled' => true])
            ->assertOk()->assertJsonPath('data.variables', ['name', 'package', 'ref', 'amount', 'paid', 'due', 'link', 'short_link']);

        // The preview is the server's own rendering of unsaved text: sender line, real values, unknown variables listed.
        $this->actingAsApi($admin)->postJson("{$url}/preview", ['locale' => 'bn', 'body' => 'প্রিয় {{name}}, {{amount}} পাওয়া গেছে। {{seats}}'])->assertOk()
            ->assertJsonPath('data.body', "ভবঘুরে হলিডেজ · Bhabaghure Holidays\nপ্রিয় Tanvir Hasan, ".Numerals::bdt(0, 'bn').' পাওয়া গেছে।')
            ->assertJsonPath('data.unknown_variables', ['seats'])
            ->assertJsonPath('data.sample', $this->booking->reference);
        $email = NotificationTemplate::query()->where('event', 'booking_created')->where('channel', 'email')->firstOrFail();
        $this->actingAsApi($admin)->postJson("/api/v1/admin/notification-templates/{$email->id}/preview", ['locale' => 'en', 'body' => 'Open {{link}}', 'subject' => 'Booking {{ref}}'])->assertOk()
            ->assertJsonPath('data.body', "Open https://bhabaghure.test/booking/{$this->booking->reference}#t=…")
            ->assertJsonPath('data.subject', "Booking {$this->booking->reference}");

        $this->actingAsApi($admin)->postJson("{$url}/test", ['locale' => 'en'])->assertStatus(409)->assertJsonPath('code', 'no_verified_number');

        $admin->forceFill(['whatsapp_number' => '8801811000999', 'whatsapp_verified_at' => now()])->save();
        $this->actingAsApi($admin)->postJson("{$url}/test", ['locale' => 'en'])->assertCreated();
        $this->assertSame('8801811000999', FakeWhatsAppGateway::$sent[0]['to']);
        $this->assertStringStartsWith("ভবঘুরে হলিডেজ · Bhabaghure Holidays\nDear Tanvir Hasan", FakeWhatsAppGateway::$sent[0]['text']);
    }

    #[Test]
    public function the_overview_never_reveals_the_key_and_staff_can_turn_a_customers_whatsapp_off(): void
    {
        config(['bhabaghure.notifications.whatsapp.api_key' => 'secret-session-key-ab12']);
        $admin = $this->staff('admin');

        $response = $this->actingAsApi($admin)->getJson('/api/v1/admin/notifications/overview?refresh=1')->assertOk()
            ->assertJsonPath('data.whatsapp.key_hint', '…ab12')
            ->assertJsonPath('data.whatsapp.session.status', 'connected')
            ->assertJsonPath('data.numbers.notifications', self::NOTIFICATIONS_NUMBER);
        $this->assertStringNotContainsString('secret-session-key', $response->getContent());

        $customer = $this->booking->customer;
        $this->actingAsApi($this->staff('accountant'))->postJson("/api/v1/admin/customers/{$customer->id}/whatsapp-opt-out", ['opted_out' => true])->assertForbidden();
        $this->actingAsApi($admin)->postJson("/api/v1/admin/customers/{$customer->id}/whatsapp-opt-out", ['opted_out' => true])->assertOk();
        $this->assertNotNull($customer->fresh()->whatsapp_opted_out_at);

        $this->actingAsApi($admin)->getJson("/api/v1/admin/bookings/{$this->booking->id}")->assertOk()
            ->assertJsonPath('data.customer.email', 'tanvir@example.test')
            ->assertJsonPath('data.customer.whatsapp_opted_out', true)
            ->assertJsonPath('data.actions.send_whatsapp', false)
            ->assertJsonPath('data.notifications_number_published', true);
        $this->assertGreaterThan(0, NotificationMessage::query()->count());
    }

    #[Test]
    public function the_notifications_number_must_differ_from_the_main_lines(): void
    {
        $contact = SiteSetting::get('contact');
        $save = fn (string $number) => $this->actingAsApi($this->staff('admin'))->putJson('/api/v1/admin/settings/contact', ['value' => ['notificationsWhatsapp' => $number] + $contact]);

        $save($contact['phone'])->assertUnprocessable()->assertJsonValidationErrors('value.notificationsWhatsapp');
        $save($contact['whatsapp'])->assertUnprocessable()->assertJsonValidationErrors('value.notificationsWhatsapp');
        $save('01911000222')->assertUnprocessable()->assertJsonValidationErrors('value.notificationsWhatsapp');
        $save('+8801911000222')->assertOk();
        $this->assertSame('+8801911000222', SiteSetting::get('contact')['notificationsWhatsapp']);
    }
}
