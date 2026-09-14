<?php

namespace Tests\Feature;

use App\Mail\NotificationMail;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\NotificationMessage;
use App\Models\Quotation;
use App\Models\Staff;
use App\Models\TourPackage;
use App\Services\Notifications\NotificationDelivery;
use App\Services\Notifications\WhatsApp\FakeWhatsAppGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\SendsNotifications;
use Tests\TestCase;

/**
 * docs/phase-5-admin-core.md §4.5: quotations priced like the website and frozen, sent by WhatsApp with the PDF and by
 * email (never SMS), expired by the Dhaka date, revised under a new number, converted into a booking once.
 */
class QuotationTest extends TestCase
{
    use RefreshDatabase, SendsNotifications;

    private const MUSTANG = 'nepal-mustang-adventure-tour-8-days-7-nights';

    protected function setUp(): void
    {
        parent::setUp();
        $this->sendNotifications();
    }

    #[Test]
    public function a_draft_is_priced_like_the_website_and_a_total_the_editor_did_not_show_is_refused(): void
    {
        $agent = $this->staff('sales_agent');
        $lead = $this->lead();

        $this->actingAsApi($agent)->postJson('/api/v1/admin/quotations', ['expected_total' => 150000] + $this->payload($lead))
            ->assertStatus(409)->assertJsonPath('code', 'price_changed')->assertJsonPath('quote.total', 153000);

        $quotation = $this->actingAsApi($agent)->postJson('/api/v1/admin/quotations', $this->payload($lead))->assertCreated()
            ->assertJsonPath('data.number', 'QT-0001')->assertJsonPath('data.status', 'draft')->assertJsonPath('data.total_amount', 153000)
            ->assertJsonPath('data.amounts.vat', 3000)->assertJsonPath('data.assigned_staff.id', $agent->id)
            ->assertJsonPath('data.inputs.package_slug', self::MUSTANG)->assertJsonPath('data.public_url', null)->json('data');
        $this->assertSame([['package', 2, 75000, 150000]], array_map(fn ($l) => [$l['kind'], $l['quantity'], $l['unit_price'], $l['amount']], $quotation['lines']));
        $this->assertSame($agent->id, $lead->fresh()->assigned_staff_id, 'an unowned lead comes with the quotation');
        $this->assertSame('new', $this->leadState($agent, $lead), 'a draft has not reached the customer');

        // A total VAT rate that isn't offered, and a validity that isn't 3, 7 or 14 days, are refused.
        $this->actingAsApi($agent)->postJson('/api/v1/admin/quotations', ['vat_rate' => 3, 'validity_days' => 10] + $this->payload($lead))
            ->assertUnprocessable()->assertJsonValidationErrors(['vat_rate', 'validity_days']);

        // Editing a draft re-prices it: single rooms (+12 % = 9,000 each), a 5,000 discount and 5 % VAT → 1,63,000 + 8,150.
        $this->actingAsApi($agent)->putJson("/api/v1/admin/quotations/{$quotation['id']}", ['room' => 'single', 'discount' => 5000, 'vat_rate' => 5, 'expected_total' => 171150] + array_diff_key($this->payload($lead), ['customer_id' => 1]))
            ->assertOk()->assertJsonPath('data.amounts.single_supplement', 18000)->assertJsonPath('data.amounts.discount', 5000)->assertJsonPath('data.total_amount', 171150);

        $this->actingAsApi($this->staff('accountant'))->postJson('/api/v1/admin/quotations', $this->payload($lead))->assertForbidden();
        $this->actingAsApi($this->staff('accountant'))->getJson("/api/v1/admin/quotations/{$quotation['id']}")->assertOk()->assertJsonPath('data.actions.send', false);
        $this->actingAsApi($this->staff('tour_operator'))->getJson('/api/v1/admin/quotations')->assertForbidden();
    }

    #[Test]
    public function sending_starts_the_validity_freezes_the_price_and_goes_by_whatsapp_with_the_pdf_and_by_email_never_sms(): void
    {
        $agent = $this->staff('sales_agent');
        $lead = $this->lead(['email' => 'rahim@example.test']);
        $id = $this->draft($agent, $lead);

        $sent = $this->actingAsApi($agent)->postJson("/api/v1/admin/quotations/{$id}/send")->assertOk()
            ->assertJsonPath('data.status', 'sent')->assertJsonPath('data.valid_until', now('Asia/Dhaka')->addDays(7)->toDateString())->json('data');
        $this->actingAsApi($agent)->postJson("/api/v1/admin/quotations/{$id}/send")->assertStatus(409)->assertJsonPath('code', 'not_draft');

        $this->assertSame([['quote_sent', 'whatsapp', 'sent'], ['quote_sent', 'email', 'sent']], $this->notificationRows());
        $whatsApp = FakeWhatsAppGateway::$sent[0];
        $this->assertSame('8801711000321', $whatsApp['to']);
        $this->assertStringContainsString('QT-0001.pdf <', $whatsApp['document']);
        $this->assertStringContainsString('৳ ১,৫৩,০০০', $whatsApp['text']);
        Mail::assertSent(NotificationMail::class, fn (NotificationMail $mail) => $mail->hasTo('rahim@example.test') && $mail->filename === 'QT-0001.pdf' && count($mail->attachments()) === 1);
        $this->assertSame('quoted', $this->leadState($agent, $lead));

        // The link WhatsApp fetches the PDF from works once sent; a draft has none.
        $token = Quotation::query()->findOrFail($id)->share_token;
        $this->get("/api/v1/public/quotations/{$token}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $draft = Quotation::query()->findOrFail($this->draft($agent, $lead));
        $this->get("/api/v1/public/quotations/{$draft->share_token}/pdf")->assertNotFound();

        // A later price rise changes nothing already given.
        TourPackage::query()->where('slug', self::MUSTANG)->update(['regular_price' => 90000, 'sale_price' => null]);
        $this->actingAsApi($agent)->getJson("/api/v1/admin/quotations/{$id}")->assertJsonPath('data.total_amount', 153000)->assertJsonPath('data.public_url', $sent['public_url']);
    }

    #[Test]
    public function expiry_follows_the_dhaka_date_and_the_badge_counts_what_the_expiring_filter_lists(): void
    {
        $agent = $this->staff('sales_agent');
        $lead = $this->lead();
        [$today, $tomorrow, $inTwoDays, $yesterday] = [$this->sent($agent, $lead), $this->sent($agent, $lead), $this->sent($agent, $lead), $this->sent($agent, $lead)];

        // 01:00 in Dhaka on 1 October is still 30 September in UTC: a quotation valid until 30 September has expired.
        Carbon::setTestNow(Carbon::parse('2026-10-01 01:00', 'Asia/Dhaka'));
        foreach ([$today => '2026-10-01', $tomorrow => '2026-10-02', $inTwoDays => '2026-10-03', $yesterday => '2026-09-30'] as $id => $date) {
            DB::table('quotations')->where('id', $id)->update(['valid_until' => $date]);
        }

        $list = fn (string $status) => collect($this->actingAsApi($agent)->getJson("/api/v1/admin/quotations?status={$status}")->assertOk()->json('data'))->pluck('id')->sort()->values()->all();
        // Ends within 48 hours: valid until today (23 h left) or tomorrow (47 h); not the day after (71 h).
        $this->assertSame([$today, $tomorrow], $list('expiring'));
        $this->assertSame([$yesterday], $list('expired'));
        $this->assertSame([$today, $tomorrow, $inTwoDays], $list('sent'));
        $this->actingAsApi($agent)->getJson('/api/v1/admin/nav-counts')->assertJsonPath('data.quotations', ['count' => 2, 'filter' => ['status' => 'expiring']]);
        $this->actingAsApi($agent)->getJson('/api/v1/admin/quotations/summary')->assertJsonPath('data.expiring', 2)->assertJsonPath('data.open.count', 3);

        $this->actingAsApi($agent)->getJson("/api/v1/admin/quotations/{$yesterday}")->assertJsonPath('data.display_status', 'expired')
            ->assertJsonPath('data.actions.accept', false)->assertJsonPath('data.actions.convert', false)->assertJsonPath('data.actions.revise', true);
        $this->actingAsApi($agent)->postJson("/api/v1/admin/quotations/{$yesterday}/accept")->assertStatus(409)->assertJsonPath('code', 'expired');
        $this->actingAsApi($agent)->postJson("/api/v1/admin/quotations/{$yesterday}/convert", ['travellers' => [['name' => 'A'], ['name' => 'B']]])
            ->assertStatus(409)->assertJsonPath('code', 'expired');

        // Accepted in time keeps the price after the date.
        $this->actingAsApi($agent)->postJson("/api/v1/admin/quotations/{$today}/accept")->assertOk()->assertJsonPath('data.status', 'accepted');
        Carbon::setTestNow(Carbon::parse('2026-10-05 10:00', 'Asia/Dhaka'));
        $this->actingAsApi($agent)->getJson("/api/v1/admin/quotations/{$today}")->assertJsonPath('data.display_status', 'accepted')->assertJsonPath('data.actions.convert', true);
    }

    #[Test]
    public function a_revision_takes_a_new_number_and_sending_it_withdraws_the_original(): void
    {
        $agent = $this->staff('sales_agent');
        $lead = $this->lead();
        $original = $this->sent($agent, $lead);

        $revision = $this->actingAsApi($agent)->postJson("/api/v1/admin/quotations/{$original}/revise")->assertCreated()
            ->assertJsonPath('data.number', 'QT-0002')->assertJsonPath('data.status', 'draft')->assertJsonPath('data.revision_of.number', 'QT-0001')
            ->assertJsonPath('data.total_amount', 153000)->json('data.id');
        $this->actingAsApi($agent)->postJson("/api/v1/admin/quotations/{$original}/revise")->assertOk()->assertJsonPath('data.id', $revision);
        $this->actingAsApi($agent)->postJson("/api/v1/admin/quotations/{$revision}/revise")->assertStatus(409)->assertJsonPath('code', 'revise_draft');

        // Three travellers now: the revision is re-priced (at the three-person slab) when saved, then sent.
        $threeOf = fn (int $total) => ['pax' => 3, 'expected_total' => $total] + array_diff_key($this->payload($lead), ['customer_id' => 1]);
        $total = $this->actingAsApi($agent)->putJson("/api/v1/admin/quotations/{$revision}", $threeOf(0))->assertStatus(409)->json('quote.total');
        $this->assertGreaterThan(153000, $total);
        $this->actingAsApi($agent)->putJson("/api/v1/admin/quotations/{$revision}", $threeOf($total))->assertOk()->assertJsonPath('data.total_amount', $total)->assertJsonPath('data.pax_count', 3);
        $this->actingAsApi($agent)->postJson("/api/v1/admin/quotations/{$revision}/send")->assertOk();
        $this->actingAsApi($agent)->getJson("/api/v1/admin/quotations/{$original}")->assertJsonPath('data.status', 'withdrawn')
            ->assertJsonPath('data.revisions.0.number', 'QT-0002');
        $this->assertTrue(AuditLog::query()->where('action', 'quotation.withdrawn')->where('changes->reason', 'revised')->exists());

        // A quote_sent message still waiting when its quotation is withdrawn is dropped, not delivered.
        $pending = NotificationMessage::query()->create([
            'event' => 'quote_sent', 'channel' => 'whatsapp', 'to_address' => $lead->phone, 'recipient_type' => 'customer', 'recipient_id' => $lead->id,
            'related_type' => 'quotation', 'related_id' => $original, 'locale' => 'bn', 'body' => 'x', 'status' => 'pending', 'provider' => 'whatsapp',
            'attachment_path' => "quotation:{$original}", 'scheduled_for' => now(), 'dedupe_key' => 'test:withdrawn',
        ]);
        $delivered = app(NotificationDelivery::class)->deliver($pending->id);
        $this->assertSame(['cancelled', 'quotation_withdrawn'], [$delivered->status->value, $delivered->skipped_reason]);
    }

    #[Test]
    public function converting_books_at_the_frozen_price_once_for_the_quotations_owner(): void
    {
        [$agent, $admin] = [$this->staff('sales_agent'), $this->staff('admin')];
        $lead = $this->lead();
        $id = $this->sent($agent, $lead, travelDate: null);
        TourPackage::query()->where('slug', self::MUSTANG)->update(['regular_price' => 90000, 'sale_price' => null]);

        $travellers = ['travellers' => [['name' => 'Rahim Uddin'], ['name' => 'Karima Begum']]];
        $this->actingAsApi($admin)->postJson("/api/v1/admin/quotations/{$id}/convert", $travellers)->assertUnprocessable()->assertJsonValidationErrors('travel_date');
        $this->actingAsApi($admin)->postJson("/api/v1/admin/quotations/{$id}/convert", ['travellers' => [['name' => 'Only one']]] + ['travel_date' => $this->travelDate()])
            ->assertUnprocessable()->assertJsonValidationErrors('travellers');

        $converted = $this->actingAsApi($admin)->postJson("/api/v1/admin/quotations/{$id}/convert", $travellers + ['travel_date' => $this->travelDate()])->assertCreated()
            ->assertJsonPath('data.quotation.status', 'converted')->json('data');
        $booking = Booking::query()->with('lines')->findOrFail($converted['booking']['id']);
        $this->assertSame([153000.0, $id, $agent->id, $admin->id, 'inquiry'], [(float) $booking->total_amount, $booking->quotation_id, $booking->assigned_staff_id, $booking->created_by_staff_id, $booking->status->value]);
        $this->assertSame([['package', 2, '75000.00']], $booking->lines->map(fn ($l) => [$l->kind, $l->quantity, $l->unit_price])->all());
        $this->assertSame('converted', $this->leadState($agent, $lead));

        // Twice is still one booking.
        $this->actingAsApi($admin)->postJson("/api/v1/admin/quotations/{$id}/convert", $travellers + ['travel_date' => $this->travelDate()])->assertOk()
            ->assertJsonPath('data.booking.id', $booking->id);
        $this->assertSame(1, Booking::query()->count());

        // Deleting a mistaken conversion puts the quotation back to accepted, ready to convert again.
        $this->actingAsApi($admin)->deleteJson("/api/v1/admin/bookings/{$booking->id}")->assertNoContent();
        $this->actingAsApi($admin)->getJson("/api/v1/admin/quotations/{$id}")->assertJsonPath('data.status', 'accepted')->assertJsonPath('data.converted_booking', null);
        $again = $this->actingAsApi($agent)->postJson("/api/v1/admin/quotations/{$id}/convert", $travellers + ['travel_date' => $this->travelDate()])->assertCreated()->json('data.booking.id');
        $this->assertNotSame($booking->id, $again);
    }

    #[Test]
    public function a_sales_agent_sees_and_works_only_their_own_quotations(): void
    {
        [$agentA, $agentB, $admin] = [$this->staff('sales_agent'), $this->staff('sales_agent'), $this->staff('admin')];
        $lead = $this->lead();
        $ofA = $this->sent($agentA, $lead);
        $draftOfA = $this->draft($agentA, $lead);

        $this->actingAsApi($agentB)->getJson("/api/v1/admin/quotations/{$ofA}")->assertNotFound();
        $this->actingAsApi($agentB)->postJson("/api/v1/admin/quotations/{$ofA}/withdraw")->assertNotFound();
        $this->actingAsApi($agentB)->getJson('/api/v1/admin/quotations')->assertJsonPath('meta.total', 0);
        $this->actingAsApi($agentB)->getJson('/api/v1/admin/search?q=QT-0001')->assertJsonPath('data.quotations', []);
        $this->actingAsApi($agentA)->getJson('/api/v1/admin/search?q=QT-0001')->assertJsonPath('data.quotations.0.id', $ofA);
        $this->actingAsApi($agentB)->postJson("/api/v1/admin/quotations/{$ofA}/assign", ['staff_id' => $agentB->id, 'reason' => 'Mine now'])->assertForbidden();

        // Delete only a draft; a customer with quotations stays.
        $this->actingAsApi($agentA)->deleteJson("/api/v1/admin/quotations/{$ofA}")->assertStatus(409)->assertJsonPath('code', 'delete_not_draft');
        $this->actingAsApi($agentA)->deleteJson("/api/v1/admin/quotations/{$draftOfA}")->assertNoContent();
        $this->actingAsApi($admin)->deleteJson("/api/v1/admin/customers/{$lead->id}")->assertStatus(409)->assertJsonPath('code', 'has_quotations');

        // An admin hands the quotation to B (audited): B works it, A no longer sees it.
        $this->actingAsApi($admin)->postJson("/api/v1/admin/quotations/{$ofA}/assign", ['staff_id' => $agentB->id, 'reason' => 'A on leave'])->assertOk();
        $this->assertTrue(AuditLog::query()->where('action', 'quotation.reassigned')->exists());
        $this->actingAsApi($agentB)->postJson("/api/v1/admin/quotations/{$ofA}/decline", ['reason' => 'Chose another agency'])->assertOk()->assertJsonPath('data.status', 'declined');
        $this->actingAsApi($agentA)->getJson("/api/v1/admin/quotations/{$ofA}")->assertNotFound();
    }

    #[Test]
    public function the_printed_quotation_uses_the_invoice_letterhead_shows_validity_and_no_payment_or_passport_block(): void
    {
        $agent = $this->staff('sales_agent');
        $id = $this->sent($agent, $this->lead());

        $html = $this->actingAsApi($agent)->get("/api/v1/admin/quotations/{$id}/print?lang=en")->assertOk()->getContent();
        $this->assertStringContainsString('<h1>QUOTATION</h1>', $html);
        $this->assertStringContainsString('data-quotation="QT-0001"', $html);
        $this->assertStringContainsString('This price is honoured until', $html);
        $this->assertStringContainsString('৳ 1,53,000', $html);
        foreach (['Balance due', 'Payment · পেমেন্ট', 'Travellers · যাত্রী', 'data-invoice'] as $absent) {
            $this->assertStringNotContainsString($absent, $html);
        }
    }

    private function lead(array $attributes = []): Customer
    {
        return Customer::query()->create(['name' => 'Rahim Uddin', 'phone' => '8801711000321', 'stage' => 'lead', 'source' => 'facebook', ...$attributes]);
    }

    private function payload(Customer $customer, ?string $travelDate = 'default'): array
    {
        return [
            'customer_id' => $customer->id,
            'package_slug' => self::MUSTANG,
            'travel_date' => $travelDate === 'default' ? $this->travelDate() : $travelDate,
            'pax' => 2,
            'room' => 'twin',
            'addons' => [],
            'discount' => 0,
            'vat_rate' => 2,
            'validity_days' => 7,
            'locale' => 'bn',
            'notes' => null,
            'expected_total' => 153000,
        ];
    }

    private function travelDate(): string
    {
        return now('Asia/Dhaka')->addDays(40)->toDateString();
    }

    private function draft(Staff $staff, Customer $customer, ?string $travelDate = 'default'): int
    {
        return $this->actingAsApi($staff)->postJson('/api/v1/admin/quotations', $this->payload($customer, $travelDate))->assertCreated()->json('data.id');
    }

    private function sent(Staff $staff, Customer $customer, ?string $travelDate = 'default'): int
    {
        $id = $this->draft($staff, $customer, $travelDate);
        $this->actingAsApi($staff)->postJson("/api/v1/admin/quotations/{$id}/send")->assertOk();

        return $id;
    }

    private function leadState(Staff $staff, Customer $customer): string
    {
        return $this->actingAsApi($staff)->getJson("/api/v1/admin/customers/{$customer->id}")->assertOk()->json('data.lead_state');
    }
}
