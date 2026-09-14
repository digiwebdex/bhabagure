<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\NotificationMessage;
use App\Services\Admin\Ownership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\SendsNotifications;
use Tests\TestCase;

/**
 * docs/phase-6-customer-portal.md §0.3, §3.5: a customer opens a ticket in the portal, optionally about one of their
 * trips; the trip's owner is alerted; a reply from the Support queue goes back by WhatsApp and email and shows in the
 * portal. A ticket waiting more than 24 hours is overdue.
 */
class SupportTicketsTest extends TestCase
{
    use RefreshDatabase, SendsNotifications;

    private const MUSTANG = 'nepal-mustang-adventure-tour-8-days-7-nights';

    protected function setUp(): void
    {
        parent::setUp();
        $this->sendNotifications();
    }

    #[Test]
    public function a_ticket_reaches_the_trip_owner_and_a_reply_goes_back_by_whatsapp_and_email_and_shows_in_the_portal(): void
    {
        $booking = $this->websiteBooking('01711-000001', 'tanvir@example.test');
        $owner = $this->staff('sales_agent', ['name' => 'Farhana Akter', 'email' => 'farhana@example.test']);
        app(Ownership::class)->claim($booking, $owner);
        $me = $booking->customer;
        $someoneElse = $this->websiteBooking('01711-000009', null);

        $this->actingAsApi($me)->postJson('/api/v1/portal/support', ['booking_reference' => $someoneElse->reference, 'subject' => 'Room type', 'body' => 'Can we change?'])->assertNotFound();
        $this->actingAsApi($me)->postJson('/api/v1/portal/support', ['subject' => '', 'body' => 'x'])->assertUnprocessable()->assertJsonValidationErrors(['subject', 'body']);

        $ticket = $this->actingAsApi($me)->postJson('/api/v1/portal/support', [
            'booking_reference' => $booking->reference, 'subject' => 'Can we change the room type?', 'body' => 'Twin to double, please.',
        ])->assertCreated()->assertJsonPath('data.number', 'ST-0001')->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.bookingReference', $booking->reference)->json('data');
        $alert = NotificationMessage::query()->where('event', 'support_ticket_alert')->where('channel', 'email')->sole();
        $this->assertSame(['farhana@example.test', $owner->id], [$alert->to_address, $alert->recipient_id]);
        $this->assertStringContainsString('Twin to double, please.', $alert->body);

        // Other customers and staff without the permission see nothing.
        $this->actingAsApi($someoneElse->customer)->getJson('/api/v1/portal/support/ST-0001')->assertNotFound();
        $this->actingAsApi($someoneElse->customer)->getJson('/api/v1/portal/support')->assertOk()->assertJsonPath('data.tickets', []);
        $this->actingAsApi($this->staff(null))->getJson('/api/v1/admin/support-tickets')->assertForbidden();

        // The queue, a reply from an accountant, and the customer's view of it.
        $accountant = $this->staff('accountant', ['name' => 'Kamal Hossain']);
        $row = $this->actingAsApi($accountant)->getJson('/api/v1/admin/support-tickets')->assertOk()->assertJsonPath('meta.total', 1)->json('data.0');
        $this->assertSame(['ST-0001', false, $owner->id], [$row['number'], $row['overdue'], $row['booking']['assigned_staff']['id']]);
        $this->actingAsApi($accountant)->postJson("/api/v1/admin/support-tickets/{$row['id']}/replies", ['body' => 'Done — no extra charge.'])->assertOk()
            ->assertJsonPath('data.status', 'answered')->assertJsonPath('data.messages.1.staff.name', 'Kamal Hossain');
        $replies = NotificationMessage::query()->where('event', 'support_reply')->orderBy('id')->get();
        $this->assertSame([['whatsapp', $me->phone], ['email', 'tanvir@example.test']], $replies->map(fn ($m) => [$m->channel->value, $m->to_address])->all());
        $this->assertStringContainsString('Done — no extra charge.', $replies[0]->body);
        $this->assertStringContainsString('/support/ST-0001', $replies[1]->body);

        $messages = $this->actingAsApi($me)->getJson('/api/v1/portal/support/ST-0001')->assertOk()->json('data.messages');
        $this->assertSame([['customer', null], ['staff', 'Kamal']], array_map(fn ($m) => [$m['author'], $m['staffName']], $messages));
        $this->assertStringNotContainsString('Hossain', json_encode($messages));

        // The customer writes again, staff close it, and a later message reopens it.
        $this->actingAsApi($me)->postJson('/api/v1/portal/support/ST-0001/messages', ['body' => 'Thank you!'])->assertCreated()->assertJsonPath('data.status', 'open');
        $this->actingAsApi($accountant)->postJson("/api/v1/admin/support-tickets/{$row['id']}/close")->assertOk()->assertJsonPath('data.status', 'closed');
        $this->actingAsApi($me)->postJson('/api/v1/portal/support/ST-0001/messages', ['body' => 'One more question.'])->assertCreated()->assertJsonPath('data.status', 'open');
        $this->assertSame(['support.ticket_opened', 'support.replied', 'support.customer_message', 'support.ticket_closed', 'support.ticket_reopened'],
            AuditLog::query()->where('action', 'like', 'support.%')->orderBy('id')->pluck('action')->all());
        $this->assertSame(0, AuditLog::query()->where('changes', 'like', '%Twin to double%')->count(), 'message text stays out of the audit log');

        // 24 hours without a reply: overdue.
        $this->travel(25)->hours();
        $this->actingAsApi($accountant)->getJson('/api/v1/admin/support-tickets?overdue=1')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.overdue', true);
    }

    private function websiteBooking(string $phone, ?string $email): Booking
    {
        $reference = $this->postJson('/api/v1/public/bookings', [
            'package_slug' => self::MUSTANG,
            'travel_date' => now('Asia/Dhaka')->addDays(30)->toDateString(),
            'pax' => 1,
            'room' => 'single',
            'addons' => [],
            'travellers' => [['name' => 'Tanvir Hasan', 'passport_number' => 'A01234567', 'date_of_birth' => '1990-04-12', 'passport_expiry' => '2031-01-31', 'phone' => $phone, 'email' => $email]],
            'expected_total' => 85680,
            'terms_accepted' => true,
            'locale' => 'en',
        ])->assertCreated()->json('data.reference');

        return Booking::query()->with('customer')->where('reference', $reference)->firstOrFail();
    }
}
