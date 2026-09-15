<?php

namespace Tests\Feature;

use App\Enums\NotificationChannel;
use App\Enums\NotificationEvent;
use App\Models\Booking;
use App\Models\Invoice;
use App\Models\NotificationTemplate;
use App\Models\SiteSetting;
use App\Models\Transaction;
use App\Services\Invoices\InvoicePdf;
use App\Services\Ledger\LedgerService;
use App\Services\Notifications\MessageRenderer;
use App\Services\Notifications\NotificationVariables;
use App\Support\Payments\PaymentOptions;
use Database\Seeders\ContentSeeder;
use Database\Seeders\NotificationTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * docs/phase-8-visa-quotes-pricing-downloads.md §4.F: NPSB bank transfer, the SSLCommerz payment link while the built-in
 * checkout is off, and bKash with its charge, from Admin → Site settings → Payment to every place a customer pays.
 */
class PaymentOptionsTest extends TestCase
{
    use RefreshDatabase;

    private const PAYMENT = [
        'bank' => ['bankName' => 'Example Trust Bank', 'accountName' => 'Example Holidays', 'accountNumber' => '1310000000001', 'branch' => 'Mirpur', 'routingNumber' => '145260001', 'transferType' => 'NPSB'],
        'link' => 'https://invoice.sslcommerz.com/invoice-form?refer=EXAMPLE',
        'bkash' => ['number' => '+8801613000000', 'chargePercent' => 1.3],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ContentSeeder::class);
    }

    #[Test]
    public function staff_save_the_payment_details_in_site_settings_and_each_method_is_checked(): void
    {
        $admin = $this->staff('admin');

        $this->actingAsApi($admin)->putJson('/api/v1/admin/settings/payment', ['value' => [
            'bank' => ['bankName' => 'X', 'accountName' => 'Y', 'accountNumber' => '13-10', 'branch' => 'Z', 'routingNumber' => '14526', 'transferType' => 'NPSB'],
            'link' => 'http://invoice.sslcommerz.com/x',
            'bkash' => ['number' => '01613000000', 'chargePercent' => 12],
        ]])->assertUnprocessable()->assertJsonValidationErrors(['value.bank.accountNumber', 'value.bank.routingNumber', 'value.link', 'value.bkash.number', 'value.bkash.chargePercent']);

        $this->actingAsApi($admin)->putJson('/api/v1/admin/settings/payment', ['value' => self::PAYMENT + ['extra' => 'dropped']])->assertOk();
        $this->assertEquals(self::PAYMENT, SiteSetting::get('payment'));
        $this->actingAsApi($admin)->getJson('/api/v1/admin/settings')->assertJsonPath('data.payment.bank.routingNumber', '145260001');

        // Only bKash: the other methods are saved empty. The details never appear in the public settings.
        $this->actingAsApi($admin)->putJson('/api/v1/admin/settings/payment', ['value' => ['bank' => null, 'link' => null, 'bkash' => self::PAYMENT['bkash']]])->assertOk();
        $this->assertEquals(['bank' => null, 'link' => null, 'bkash' => self::PAYMENT['bkash']], SiteSetting::get('payment'));
        $this->getJson('/api/v1/public/settings')->assertJsonMissingPath('data.payment');

        $this->actingAsApi($this->staff('sales_agent', ['email' => 'agent@example.test', 'phone' => '8801711000099']))->putJson('/api/v1/admin/settings/payment', ['value' => self::PAYMENT])->assertForbidden();
    }

    #[Test]
    public function the_built_in_checkout_counts_only_when_it_can_take_real_payments_here(): void
    {
        $set = function (string $mode, bool $credentials, string $env): void {
            config(['bhabaghure.sslcommerz.mode' => $mode, 'bhabaghure.sslcommerz.store_id' => $credentials ? 'store' : '', 'bhabaghure.sslcommerz.store_password' => $credentials ? 'secret' : '']);
            $this->app['env'] = $env;
        };

        $cases = [
            ['fake', false, 'testing', true], ['sandbox', true, 'testing', true], ['sandbox', false, 'testing', false],
            ['live', true, 'production', true], ['live', false, 'production', false], ['sandbox', true, 'production', false], ['fake', false, 'production', false],
        ];
        foreach ($cases as [$mode, $credentials, $env, $expected]) {
            $set($mode, $credentials, $env);
            $this->assertSame($expected, PaymentOptions::checkoutAvailable(), "{$mode} / credentials ".($credentials ? 'set' : 'blank')." / {$env}");
        }
        $this->app['env'] = 'testing';
    }

    #[Test]
    public function the_booking_page_gets_each_method_with_the_exact_amount_and_the_link_only_while_the_checkout_is_off(): void
    {
        SiteSetting::query()->updateOrCreate(['key' => 'payment'], ['value' => self::PAYMENT]);
        config(['bhabaghure.sslcommerz.mode' => 'sandbox', 'bhabaghure.sslcommerz.store_id' => '', 'bhabaghure.sslcommerz.store_password' => '']);

        $this->getJson('/api/v1/public/pricing')->assertJsonPath('data.onlineCheckout', false);
        $created = $this->postJson('/api/v1/public/bookings', $this->payload())->assertCreated()->json('data');
        $this->assertFalse($created['payment']['checkout']);
        // 76,500 due; bKash 1.3% = 994.5 → ৳ 995, so ৳ 77,495 to send.
        $this->assertEquals([
            'amount' => 76500, 'bank' => self::PAYMENT['bank'], 'link' => self::PAYMENT['link'],
            'bkash' => ['number' => '+8801613000000', 'chargePercent' => 1.3, 'charge' => 995, 'total' => 77495],
        ], $created['payment']['manual']);

        // The built-in checkout taking payments: the link steps aside; bank and bKash stay.
        config(['bhabaghure.sslcommerz.mode' => 'fake']);
        $manual = $this->getJson("/api/v1/public/bookings/{$created['reference']}", ['X-Booking-Token' => $created['accessToken']])->assertJsonPath('data.payment.checkout', true)->json('data.payment.manual');
        $this->assertNull($manual['link']);
        $this->assertSame(77495, $manual['bkash']['total']);

        // Nothing set: nothing to show.
        SiteSetting::query()->whereKey('payment')->delete();
        $this->getJson("/api/v1/public/bookings/{$created['reference']}", ['X-Booking-Token' => $created['accessToken']])->assertJsonPath('data.payment.manual', null);
    }

    #[Test]
    public function the_booking_received_whatsapp_lists_bank_and_bkash_without_the_link_and_the_email_adds_the_link(): void
    {
        $this->seed(NotificationTemplateSeeder::class);
        SiteSetting::query()->updateOrCreate(['key' => 'payment'], ['value' => self::PAYMENT]);
        config(['bhabaghure.sslcommerz.mode' => 'sandbox', 'bhabaghure.sslcommerz.store_id' => '', 'bhabaghure.sslcommerz.store_password' => '']);
        $booking = Booking::query()->where('reference', $this->postJson('/api/v1/public/bookings', $this->payload())->json('data.reference'))->firstOrFail();
        $variables = app(NotificationVariables::class);

        $whatsApp = $variables->for(NotificationEvent::BookingCreated, $booking, 'en', NotificationChannel::WhatsApp)['how_to_pay'];
        $this->assertStringContainsString("How to pay (write {$booking->reference} as the reference):", $whatsApp);
        $this->assertStringContainsString('• Bank (NPSB): Example Trust Bank, Example Holidays, A/C 1310000000001, Mirpur branch, routing 145260001: ৳ 76,500', $whatsApp);
        $this->assertStringContainsString('• bKash (send money) 01613000000: ৳ 77,495, including the 1.3% bKash charge', $whatsApp);
        $this->assertStringNotContainsString('https://', $whatsApp);

        $email = $variables->for(NotificationEvent::BookingCreated, $booking, 'bn', NotificationChannel::Email)['how_to_pay'];
        $this->assertStringContainsString('কার্ড, মোবাইল ব্যাংকিং বা EMI: https://invoice.sslcommerz.com/invoice-form?refer=EXAMPLE', $email);
        $this->assertStringContainsString('বিকাশ (সেন্ড মানি) 01613000000: ৳ ৭৭,৪৯৫', $email);

        // The shipped template carries it; with nothing set the message closes without a gap.
        $template = NotificationTemplate::query()->where('event', 'booking_created')->where('channel', 'whatsapp')->sole();
        $this->assertStringEndsWith("\n\n{{how_to_pay}}", $template->body_en);
        SiteSetting::query()->whereKey('payment')->delete();
        $filled = app(MessageRenderer::class)->fill("A\n\n{{how_to_pay}}\n\nB", $variables->for(NotificationEvent::BookingCreated, $booking, 'en', NotificationChannel::Email));
        $this->assertSame("A\n\nB", $filled);
    }

    #[Test]
    public function an_open_invoice_says_how_to_pay_its_balance(): void
    {
        SiteSetting::query()->updateOrCreate(['key' => 'payment'], ['value' => self::PAYMENT]);
        $booking = Booking::query()->where('reference', $this->postJson('/api/v1/public/bookings', $this->payload())->json('data.reference'))->firstOrFail();
        $admin = $this->staff('admin');
        $this->actingAsApi($admin)->postJson("/api/v1/admin/bookings/{$booking->id}/invoice")->assertOk();
        $invoice = Invoice::query()->where('booking_id', $booking->id)->where('status', Invoice::ISSUED)->sole();

        $html = app(InvoicePdf::class)->html($invoice, false, 'en');
        $this->assertStringContainsString('data-region="how-to-pay"', $html);
        $this->assertStringContainsString('routing 145260001', $html);
        $this->assertStringContainsString('bKash (send money) 01613000000: ৳ 77,495', $html);
        // The test environment's checkout is on: the link isn't printed.
        $this->assertStringNotContainsString('invoice-form?refer=EXAMPLE', $html);

        // Paid in full: nothing left to pay, no box.
        Storage::fake('local');
        $this->actingAsApi($admin)->postJson("/api/v1/admin/bookings/{$booking->id}/payments", ['amount' => 76500, 'method' => 'cash', 'evidence' => $this->receipt()])->assertOk();
        $this->assertStringNotContainsString('data-region="how-to-pay"', app(InvoicePdf::class)->html($invoice->fresh(), false, 'en'));
    }

    #[Test]
    public function staff_record_a_bkash_payment_with_its_charge_as_two_rows_and_a_reversal_takes_both(): void
    {
        SiteSetting::query()->updateOrCreate(['key' => 'payment'], ['value' => self::PAYMENT]);
        $booking = Booking::query()->where('reference', $this->postJson('/api/v1/public/bookings', $this->payload())->json('data.reference'))->firstOrFail();
        $admin = $this->staff('admin');
        $this->actingAsApi($admin)->postJson("/api/v1/admin/bookings/{$booking->id}/invoice")->assertOk();
        Storage::fake('local');
        $pay = fn (array $data) => $this->actingAsApi($admin)->postJson("/api/v1/admin/bookings/{$booking->id}/payments", $data + ['amount' => 10000, 'evidence' => $this->receipt()]);

        $pay(['method' => 'cash', 'bkash_charge' => true])->assertUnprocessable()->assertJsonValidationErrors('bkash_charge');
        $pay(['method' => 'bkash', 'bkash_charge' => true])->assertUnprocessable()->assertJsonValidationErrors('bkash_charge');
        $pay(['method' => 'bkash', 'reference' => 'TRX9A1B2C3', 'bkash_charge' => true])->assertOk();

        $rows = Transaction::query()->where('booking_id', $booking->id)->orderBy('id')->get();
        $this->assertSame([[LedgerService::CATEGORY_PAYMENT, '10000.00'], [LedgerService::CATEGORY_ONLINE_CHARGE, '130.00']], $rows->map(fn (Transaction $t) => [$t->category, (string) $t->amount])->all());
        $this->assertStringStartsWith('bKash charge · ', $rows[1]->description);
        $this->assertSame('10000.00', (string) $booking->fresh()->paid_amount);

        $this->actingAsApi($admin)->postJson("/api/v1/admin/transactions/{$rows[0]->id}/reverse", ['reason' => 'Sent to the wrong booking'])->assertOk();
        $this->assertSame(2, Transaction::query()->whereIn('reverses_transaction_id', $rows->pluck('id'))->count());
        $this->assertSame('0.00', (string) $booking->fresh()->paid_amount);
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'package_slug' => 'nepal-mustang-adventure-tour-8-days-7-nights', 'travel_date' => now()->addDays(45)->toDateString(), 'pax' => 1, 'room' => 'twin', 'addons' => [],
            'travellers' => [['name' => 'Tanvir Hasan', 'phone' => '01711-000001', 'email' => 'tanvir@example.test']],
            'expected_total' => 76500, 'terms_accepted' => true, 'locale' => 'en',
        ];
    }
}
