<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Transaction;
use App\Services\Booking\BookingCreator;
use App\Services\Booking\BookingRequest;
use Database\Seeders\ContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** The admin booking screen's API: draft quote, issue, record payment, confirm — and nothing that sets money or status. */
class AdminBookingTest extends TestCase
{
    use RefreshDatabase;

    private Booking $booking;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(ContentSeeder::class);
        $this->booking = app(BookingCreator::class)->create(new BookingRequest(
            'nepal-mustang-adventure-tour-8-days-7-nights', '2026-10-31', 2, 'twin', ['airport-pickup'],
            [
                ['name' => 'Tanvir Hasan', 'passportNumber' => 'A01234567', 'dateOfBirth' => '1990-04-12', 'passportExpiry' => '2030-01-31', 'phone' => '8801711000001', 'email' => null],
                ['name' => 'Nusrat Jahan', 'passportNumber' => 'B07654321', 'dateOfBirth' => '1992-08-03', 'passportExpiry' => '2031-05-01', 'phone' => null, 'email' => null],
            ],
            // 150,000 + pickup 800 = 150,800; 2% = 3,016.
            153816, 'bn', 'website', true,
        ))['booking'];
    }

    #[Test]
    public function the_draft_quote_uses_the_shared_formula_with_vat_after_discount(): void
    {
        $admin = $this->staff('admin');
        $url = "/api/v1/admin/bookings/{$this->booking->id}/quote";

        // 3 pax: slab −3% → 72,750 × 3 = 218,250 + pickup 800 = 219,050; −5,000 discount = 214,050; 5% VAT = 10,703 (rounded).
        $this->actingAsApi($admin)->putJson($url, ['pax' => 3, 'room' => 'twin', 'discount' => 5000, 'vat_rate' => 5, 'expected_total' => 999])
            ->assertStatus(409)->assertJsonPath('code', 'price_changed')->assertJsonPath('quote.total', 224753);

        $this->actingAsApi($admin)->putJson($url, ['pax' => 3, 'room' => 'twin', 'discount' => 5000, 'vat_rate' => 5, 'expected_total' => 224753])
            ->assertOk()
            ->assertJsonPath('data.total_amount', 224753)
            ->assertJsonPath('data.vat_amount', 10703)
            ->assertJsonPath('data.discount_amount', 5000)
            ->assertJsonPath('data.actions.record_payment', false);

        $this->actingAsApi($admin)->putJson($url, ['pax' => 3, 'room' => 'twin', 'discount' => 0, 'vat_rate' => 3, 'expected_total' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('vat_rate');
    }

    #[Test]
    public function payments_are_recorded_against_the_issued_invoice_and_the_status_is_derived(): void
    {
        $accountant = $this->staff('accountant');
        $admin = $this->staff('admin');
        $id = $this->booking->id;

        $this->actingAsApi($accountant)->postJson("/api/v1/admin/bookings/{$id}/payments", ['amount' => 50000, 'method' => 'cash'])
            ->assertStatus(409)->assertJsonPath('code', 'no_invoice');

        $this->actingAsApi($accountant)->postJson("/api/v1/admin/bookings/{$id}/invoice")->assertOk()
            ->assertJsonPath('data.invoices.0.status', 'issued')
            ->assertJsonPath('data.actions.edit_quote', false)
            ->assertJsonPath('data.actions.record_payment', true);

        $this->actingAsApi($accountant)->putJson("/api/v1/admin/bookings/{$id}/quote", ['pax' => 2, 'room' => 'twin', 'discount' => 0, 'vat_rate' => 2, 'expected_total' => 153816])
            ->assertForbidden();
        $this->actingAsApi($admin)->putJson("/api/v1/admin/bookings/{$id}/quote", ['pax' => 2, 'room' => 'twin', 'discount' => 1000, 'vat_rate' => 2, 'expected_total' => 152796])
            ->assertStatus(409)->assertJsonPath('code', 'quote_locked');

        // A typed paid amount or status is simply not accepted anywhere.
        $this->actingAsApi($accountant)->postJson("/api/v1/admin/bookings/{$id}/payments", ['amount' => 76908, 'method' => 'bkash', 'reference' => '9XK2M4', 'paid_amount' => 153816, 'payment_status' => 'paid'])
            ->assertOk()
            ->assertJsonPath('data.paid_amount', 76908)
            ->assertJsonPath('data.payment_status', 'partial')
            ->assertJsonPath('data.invoices.0.payment_status', 'partial');

        $this->actingAsApi($accountant)->postJson("/api/v1/admin/bookings/{$id}/payments", ['amount' => 100, 'method' => 'bkash', 'reference' => '9XK2M4'])
            ->assertUnprocessable()->assertJsonPath('code', 'duplicate_reference');
        $this->actingAsApi($accountant)->postJson("/api/v1/admin/bookings/{$id}/payments", ['amount' => 76909, 'method' => 'cash'])
            ->assertUnprocessable()->assertJsonPath('code', 'exceeds_balance');

        $this->actingAsApi($admin)->postJson("/api/v1/admin/bookings/{$id}/confirm")->assertOk()->assertJsonPath('data.status', 'confirmed');

        $paymentId = Transaction::query()->where('booking_id', $id)->value('id');
        $this->actingAsApi($accountant)->postJson("/api/v1/admin/transactions/{$paymentId}/reverse", ['reason' => 'Wrong customer'])
            ->assertOk()->assertJsonPath('data.paid_amount', 0)->assertJsonPath('data.payment_status', 'unpaid');

        // No route updates or deletes a ledger row.
        $this->actingAsApi($admin)->putJson("/api/v1/admin/transactions/{$paymentId}", ['amount' => 1])->assertNotFound();
        $this->actingAsApi($admin)->deleteJson("/api/v1/admin/transactions/{$paymentId}")->assertNotFound();
    }

    #[Test]
    public function a_payment_dated_today_is_accepted_after_midnight_in_dhaka_and_a_backdated_one_is_journalled_on_its_day(): void
    {
        $accountant = $this->staff('accountant');
        $id = $this->booking->id;
        $this->actingAsApi($accountant)->postJson("/api/v1/admin/bookings/{$id}/invoice")->assertOk();

        // 02:00 in Dhaka is still the previous day in UTC.
        $this->travelTo(Carbon::parse('2026-09-14 02:00', 'Asia/Dhaka'));
        $this->actingAsApi($accountant)->postJson("/api/v1/admin/bookings/{$id}/payments", ['amount' => 1000, 'method' => 'cash', 'occurred_at' => '2026-09-14'])->assertOk();
        $this->actingAsApi($accountant)->postJson("/api/v1/admin/bookings/{$id}/payments", ['amount' => 1000, 'method' => 'cash', 'occurred_at' => '2026-09-15'])
            ->assertUnprocessable()->assertJsonValidationErrors('occurred_at');
        $this->actingAsApi($accountant)->postJson("/api/v1/admin/bookings/{$id}/payments", ['amount' => 2000, 'method' => 'bkash', 'reference' => 'BK77', 'occurred_at' => '2026-09-10'])->assertOk();

        $today = Transaction::query()->where('booking_id', $id)->where('amount', 1000)->firstOrFail();
        $backdated = Transaction::query()->where('booking_id', $id)->where('amount', 2000)->firstOrFail();
        $this->assertSame('2026-09-14', $today->occurred_at->setTimezone('Asia/Dhaka')->toDateString());
        $this->assertSame('2026-09-10', $backdated->occurred_at->setTimezone('Asia/Dhaka')->toDateString());
        $this->assertSame('2026-09-10', JournalEntry::query()->where('source_type', 'transaction')->where('source_id', $backdated->id)->firstOrFail()->entry_date->toDateString());
        $this->assertSame('2026-09-14', JournalEntry::query()->where('source_type', 'transaction')->where('source_id', $today->id)->firstOrFail()->entry_date->toDateString());
    }

    #[Test]
    public function sales_agents_see_only_their_bookings_and_cannot_touch_money(): void
    {
        $agent = $this->staff('sales_agent');

        $this->actingAsApi($agent)->getJson('/api/v1/admin/bookings')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAsApi($agent)->getJson("/api/v1/admin/bookings/{$this->booking->id}")->assertNotFound();

        Booking::query()->whereKey($this->booking->id)->update(['assigned_staff_id' => $agent->id]);
        $this->actingAsApi($agent)->getJson("/api/v1/admin/bookings/{$this->booking->id}")->assertOk()
            ->assertJsonPath('data.actions.issue_invoice', false)->assertJsonPath('data.actions.record_payment', false);
        $this->actingAsApi($agent)->postJson("/api/v1/admin/bookings/{$this->booking->id}/invoice")->assertForbidden();
    }

    #[Test]
    public function the_invoice_print_view_previews_before_issue_and_voiding_allows_a_reissue(): void
    {
        $admin = $this->staff('admin');
        $id = $this->booking->id;

        $this->actingAsApi($admin)->get("/api/v1/admin/bookings/{$id}/invoice/print?header=0")
            ->assertOk()->assertSee('DRAFT')->assertSee('Tanvir Hasan')->assertDontSee('Bhabaghure Holidays Aviation</strong>', false);

        $this->actingAsApi($admin)->postJson("/api/v1/admin/bookings/{$id}/invoice")->assertOk();
        $invoice = Invoice::query()->sole();
        $this->actingAsApi($admin)->get("/api/v1/admin/bookings/{$id}/invoice/print")->assertOk()->assertSee($invoice->invoice_number);

        $this->get("/api/v1/public/invoices/{$invoice->share_token}")->assertOk()->assertSee($invoice->invoice_number)
            ->assertDontSee('A01234567')->assertSee('A0•••••67');
        $this->get('/api/v1/public/invoices/'.str_repeat('x', 40))->assertNotFound();

        $this->actingAsApi($admin)->postJson("/api/v1/admin/invoices/{$invoice->id}/void", ['reason' => 'Wrong address'])
            ->assertOk()->assertJsonPath('data.invoices.0.status', 'void')->assertJsonPath('data.actions.edit_quote', true);
        $this->actingAsApi($admin)->postJson("/api/v1/admin/bookings/{$id}/invoice")->assertOk()->assertJsonPath('data.invoices.0.invoice_number', 'INV-0002');
    }
}
