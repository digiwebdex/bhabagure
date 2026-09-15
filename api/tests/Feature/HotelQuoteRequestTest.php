<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Inquiry;
use App\Models\NotificationMessage;
use App\Services\Notifications\NotificationSettings;
use App\Services\Notifications\WhatsApp\FakeWhatsAppGateway;
use App\Support\Admin\NavBadges;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\SendsNotifications;
use Tests\TestCase;

/**
 * docs/phase-8-visa-quotes-pricing-downloads.md §4.B: the home page's hotel quotation request, its alert, the Hotel
 * requests queue, and the reply staff send the customer from it (also on Air ticketing).
 */
class HotelQuoteRequestTest extends TestCase
{
    use RefreshDatabase, SendsNotifications;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sendNotifications();
    }

    #[Test]
    public function the_form_stores_the_request_makes_the_customer_a_lead_and_alerts_the_list(): void
    {
        $agent = $this->verifiedStaff('sales_agent', '8801811000222');
        NotificationSettings::saveAlertRecipients(['hotel_quote_alert' => [$agent->id]], $agent);

        $this->postJson('/api/v1/public/hotel-quotes', $this->form())->assertAccepted()->assertJsonPath('data.status', 'received');

        $inquiry = Inquiry::query()->sole();
        $this->assertSame('hotel_quote', $inquiry->type->value);
        $this->assertEquals(['location' => "Cox's Bazar", 'checkIn' => $this->day(10), 'checkOut' => $this->day(13), 'hotelCategory' => '4', 'note' => 'Sea view, near Kolatoli'], $inquiry->details);
        $this->assertSame([3, '8801711000501', 'en'], [$inquiry->pax, $inquiry->phone, $inquiry->locale]);
        $customer = Customer::query()->sole();
        $this->assertSame(['Shirin Akter', 'lead', 'website_form', $customer->id], [$customer->name, $customer->stage, $customer->source->value ?? $customer->source, $inquiry->customer_id]);

        // Only the hotel alert, to the list — not the general new-lead alert.
        $this->assertSame([['hotel_quote_alert', 'whatsapp'], ['hotel_quote_alert', 'email']],
            NotificationMessage::query()->orderBy('id')->get()->map(fn ($m) => [$m->event->value, $m->channel->value])->all());
        $whatsApp = NotificationMessage::query()->where('channel', 'whatsapp')->sole();
        $this->assertSame([$agent->id, '8801811000222'], [$whatsApp->recipient_id, $whatsApp->to_address]);
        // Staff messages are in the staff member's language (Bangla by default).
        $this->assertStringContainsString('নতুন হোটেল কোটেশন অনুরোধ: Shirin Akter 01711000501', $whatsApp->body);
        $this->assertStringContainsString("Cox's Bazar", $whatsApp->body);
        $this->assertStringContainsString('(৩ রাত)', $whatsApp->body);
        $this->assertStringContainsString('৪ তারকা · ৩ জন', $whatsApp->body);
        $this->assertStringContainsString('/hotel-requests', NotificationMessage::query()->where('channel', 'email')->sole()->body);
    }

    #[Test]
    public function with_nobody_on_the_alert_list_the_super_admins_and_admins_are_told(): void
    {
        $super = $this->staff('super_admin', ['email' => 'owner@example.test']);
        $admin = $this->staff('admin', ['email' => 'admin@example.test']);
        $this->staff('sales_agent', ['email' => 'agent@example.test']);

        $this->postJson('/api/v1/public/hotel-quotes', $this->form())->assertAccepted();

        $this->assertEqualsCanonicalizing([$super->id, $admin->id],
            NotificationMessage::query()->where('event', 'hotel_quote_alert')->where('channel', 'email')->pluck('recipient_id')->all());
        $this->assertEqualsCanonicalizing(['owner@example.test', 'admin@example.test'],
            NotificationMessage::query()->where('event', 'hotel_quote_alert')->where('channel', 'email')->pluck('to_address')->all());
    }

    #[Test]
    public function the_form_checks_dates_category_and_contact_and_a_bot_learns_nothing(): void
    {
        $this->postJson('/api/v1/public/hotel-quotes', $this->form([
            'check_in' => now('Asia/Dhaka')->subDay()->toDateString(), 'hotel_category' => '2', 'guests' => 0, 'phone' => '12345', 'location' => '',
        ]))->assertUnprocessable()->assertJsonValidationErrors(['check_in', 'hotel_category', 'guests', 'phone', 'location']);
        $this->postJson('/api/v1/public/hotel-quotes', $this->form(['check_out' => $this->day(10)]))
            ->assertUnprocessable()->assertJsonPath('errors.check_out.0', 'The check-out date must be after the check-in date.');

        $this->postJson('/api/v1/public/hotel-quotes', $this->form(['company' => 'Acme SEO']))->assertAccepted();
        $this->assertSame(0, Inquiry::query()->count());
    }

    #[Test]
    public function the_queue_shows_the_request_flags_it_after_24_hours_and_counts_it_on_the_badge(): void
    {
        $agent = $this->staff('sales_agent');
        $this->postJson('/api/v1/public/hotel-quotes', $this->form())->assertAccepted();
        DB::table('inquiries')->update(['created_at' => now()->subHours(26)]);
        // An air enquiry never shows here.
        $this->postJson('/api/v1/public/air-quotes', [
            'from_place' => 'Dhaka', 'to_place' => 'Bangkok', 'depart_on' => $this->day(20), 'passengers' => 1, 'cabin_class' => 'economy', 'name' => 'Air Person', 'phone' => '01711000777',
        ])->assertAccepted();

        $row = $this->actingAsApi($agent)->getJson('/api/v1/admin/hotel-inquiries')->assertOk()->assertJsonPath('meta.total', 1)->json('data.0');
        $this->assertSame(["Cox's Bazar", $this->day(10), $this->day(13), '4', 3, 'Sea view, near Kolatoli', true, 0],
            [$row['location'], $row['check_in'], $row['check_out'], $row['hotel_category'], $row['guests'], $row['note'], $row['stale'], $row['replies_count']]);
        $this->assertSame(['claim' => true, 'mark_quoted' => true, 'undo_quoted' => false, 'assign' => false, 'reply' => true], $row['actions']);
        $this->assertSame(1, NavBadges::for($agent)['hotel_inquiries']['count']);

        // Roles that don't work the queue can't open it.
        foreach (['tour_operator', 'accountant'] as $role) {
            $this->actingAsApi($this->staff($role))->getJson('/api/v1/admin/hotel-inquiries')->assertForbidden();
        }
        $this->actingAsApi($agent)->getJson('/api/v1/admin/hotel-inquiries/'.Inquiry::query()->where('type', 'air_quote')->value('id'))->assertNotFound();
    }

    #[Test]
    public function a_reply_goes_to_the_customer_by_whatsapp_and_email_claims_the_request_and_can_mark_it_quoted(): void
    {
        $agent = $this->staff('sales_agent', ['name' => 'Farhana Akter']);
        $this->postJson('/api/v1/public/hotel-quotes', $this->form())->assertAccepted();
        $inquiry = Inquiry::query()->sole();
        NotificationMessage::query()->delete();

        $this->actingAsApi($agent)->postJson("/api/v1/admin/hotel-inquiries/{$inquiry->id}/reply", ['text' => ''])->assertUnprocessable()->assertJsonValidationErrors('text');

        $detail = $this->actingAsApi($agent)->postJson("/api/v1/admin/hotel-inquiries/{$inquiry->id}/reply", [
            'text' => "Sea Pearl, 4-star, sea-view twin: ৳ 9,500 a night.\nBook by Friday to hold the rate.", 'mark_quoted' => true,
        ])->assertOk()->json('data');

        $this->assertSame(['quoted', $agent->id, $agent->id, 1], [$detail['status'], $detail['assigned_staff']['id'], $detail['quoted_by']['id'], $detail['replies_count']]);
        $replies = NotificationMessage::query()->where('event', 'inquiry_reply')->orderBy('id')->get();
        $this->assertSame([['whatsapp', '8801711000501'], ['email', 'shirin@example.test']], $replies->map(fn ($m) => [$m->channel->value, $m->to_address])->all());
        $this->assertSame([$agent->id, $agent->id], $replies->pluck('triggered_by_staff_id')->all());
        // The customer wrote in English; the sender line heads the WhatsApp.
        $this->assertStringStartsWith("ভবঘুরে হলিডেজ · Bhabaghure Holidays\nDear Shirin Akter,\na reply to your Cox's Bazar hotel request:\nSea Pearl", $replies[0]->body);
        $this->assertSame("Reply to your request · Cox's Bazar hotel", $replies[1]->title);
        $this->assertCount(1, FakeWhatsAppGateway::$sent);
        // The detail lists only the replies, each with its channels' status, and previews the WhatsApp around the typed text.
        $this->assertSame(['inquiry_reply'], collect($detail['notification_groups'])->pluck('event')->all());
        $this->assertSame("Dear Shirin Akter,\na reply to your Cox's Bazar hotel request:\n{{reply}}", $detail['reply_template']);

        $this->assertSame(['inquiry.claimed', 'inquiry.replied', 'inquiry.quoted'], AuditLog::query()->where('auditable_id', $inquiry->id)->where('action', 'like', 'inquiry.%')->orderBy('id')->pluck('action')->all());
        $this->assertSame(0, AuditLog::query()->where('changes', 'like', '%Sea Pearl%')->count(), 'the reply text stays out of the audit log');
    }

    #[Test]
    public function a_reply_still_goes_by_email_when_whatsapp_cannot_send_and_air_ticketing_replies_the_same_way(): void
    {
        $this->sendNotifications(null);
        $admin = $this->staff('admin');
        $this->postJson('/api/v1/public/air-quotes', [
            'from_place' => 'Dhaka', 'to_place' => 'Bangkok', 'depart_on' => $this->day(20), 'passengers' => 1, 'cabin_class' => 'economy',
            'name' => 'Rafiq Islam', 'phone' => '01711000888', 'email' => 'rafiq@example.test', 'locale' => 'bn',
        ])->assertAccepted();
        $inquiry = Inquiry::query()->sole();

        $this->actingAsApi($admin)->postJson("/api/v1/admin/air-inquiries/{$inquiry->id}/reply", ['text' => 'ঢাকা–ব্যাংকক রিটার্ন ৳ ৩৮,৫০০।'])->assertOk()
            ->assertJsonPath('data.status', 'new')->assertJsonPath('data.replies_count', 1);

        $replies = NotificationMessage::query()->where('event', 'inquiry_reply')->orderBy('id')->get()->keyBy(fn ($m) => $m->channel->value);
        $this->assertNotSame('sent', $replies['whatsapp']->status->value, 'no notifications number published');
        $this->assertSame('sent', $replies['email']->status->value);
        $this->assertStringContainsString('আপনার Dhaka → Bangkok এয়ার টিকেট অনুরোধের উত্তর', $replies['email']->body);
        $this->assertSame([], FakeWhatsAppGateway::$sent);

        // An enquiry from before every enquiry got a lead record: replying makes the lead, and the reply goes to it.
        $legacy = Inquiry::query()->create([
            'type' => 'air_quote', 'name' => 'Old Enquiry', 'phone' => '8801711000999', 'email' => 'old@example.test', 'pax' => 1, 'locale' => 'en',
            'details' => ['from' => 'Dhaka', 'to' => 'Dubai', 'departOn' => $this->day(30), 'returnOn' => null, 'cabinClass' => 'economy'],
        ]);
        $this->actingAsApi($admin)->postJson("/api/v1/admin/air-inquiries/{$legacy->id}/reply", ['text' => 'Dubai return from ৳ 62,000.'])->assertOk();
        $lead = Customer::query()->where('phone', '8801711000999')->sole();
        $this->assertSame([$lead->id, 'lead'], [$legacy->fresh()->customer_id, $lead->stage]);
        $this->assertSame(2, NotificationMessage::query()->where('event', 'inquiry_reply')->where('related_id', $legacy->id)->where('recipient_id', $lead->id)->count());

        // Without permission to message customers there is no reply.
        $operator = $this->staff('tour_operator');
        $operator->givePermissionTo('air_inquiries.view', 'air_inquiries.manage');
        $this->actingAsApi($operator)->postJson("/api/v1/admin/air-inquiries/{$inquiry->id}/reply", ['text' => 'Hello'])->assertForbidden();
    }

    /** @return array<string, mixed> */
    private function form(array $overrides = []): array
    {
        return $overrides + [
            'location' => "Cox's Bazar", 'check_in' => $this->day(10), 'check_out' => $this->day(13), 'hotel_category' => '4', 'guests' => 3,
            'note' => 'Sea view, near Kolatoli', 'name' => 'Shirin Akter', 'phone' => '01711-000501', 'email' => 'shirin@example.test', 'locale' => 'en',
        ];
    }

    private function day(int $fromToday): string
    {
        return now('Asia/Dhaka')->addDays($fromToday)->toDateString();
    }
}
