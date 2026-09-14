<?php

namespace Tests\Feature;

use App\Enums\PaymentAttemptStatus;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\JournalLine;
use App\Models\PackageDeparture;
use App\Models\PaymentAttempt;
use App\Models\SeatHold;
use App\Models\SiteSetting;
use App\Models\TourPackage;
use App\Models\Transaction;
use App\Services\Payments\SslCommerz\SslCommerzGateway;
use Database\Seeders\ContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * docs/phase-3-booking.md §4. SSLCommerz can't reach a test machine, so its answers are faked with the response
 * shapes from its developer documentation (session, validation, transaction query). Before go-live, repeat these
 * flows once against the sandbox store with a public tunnel.
 */
class SslCommerzPaymentTest extends TestCase
{
    use RefreshDatabase;

    private const MUSTANG = 'nepal-mustang-adventure-tour-8-days-7-nights';

    private const SANDBOX = 'https://sandbox.sslcommerz.com';

    /** Validation API answers by val_id, set per test. */
    private array $validations = [];

    /** Transaction-query answers by tran_id. */
    private array $queries = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ContentSeeder::class);
        config([
            'bhabaghure.sslcommerz.mode' => 'sandbox',
            'bhabaghure.sslcommerz.store_id' => 'bhaba0test',
            'bhabaghure.sslcommerz.store_password' => 'bhaba0test@ssl',
            'bhabaghure.web_url' => 'https://bhabaghure.test',
        ]);
        $this->app->forgetInstance(SslCommerzGateway::class);

        Http::fake(function (HttpRequest $request) {
            $url = $request->url();
            if (str_starts_with($url, self::SANDBOX.'/gwprocess/v4/api.php')) {
                return Http::response([
                    'status' => 'SUCCESS', 'failedreason' => '', 'sessionkey' => 'F650E87F4F2E2C0C1F0B77F6B4BCFD5E',
                    'GatewayPageURL' => self::SANDBOX.'/EasyCheckOut/testcdef650e87f4f2e2c0c1f0b77f6b4bcfd5e',
                ]);
            }
            if (str_starts_with($url, self::SANDBOX.'/validator/api/validationserverAPI.php')) {
                return Http::response($this->validations[$request->data()['val_id'] ?? ''] ?? ['status' => 'INVALID_TRANSACTION']);
            }
            if (str_starts_with($url, self::SANDBOX.'/validator/api/merchantTransIDvalidationAPI.php')) {
                return Http::response($this->queries[$request->data()['tran_id'] ?? ''] ?? ['APIConnect' => 'DONE', 'no_of_trans_found' => 0, 'element' => []]);
            }

            return Http::response('unexpected', 500);
        });
    }

    #[Test]
    public function a_guest_books_and_only_the_private_token_opens_the_booking(): void
    {
        [$reference, $token] = $this->createBooking();

        $this->getJson("/api/v1/public/bookings/{$reference}")->assertNotFound();
        $this->getJson("/api/v1/public/bookings/{$reference}", ['X-Booking-Token' => str_repeat('x', 48)])->assertNotFound();

        $response = $this->getJson("/api/v1/public/bookings/{$reference}", ['X-Booking-Token' => $token])->assertOk()
            ->assertJsonPath('data.status', 'inquiry')
            ->assertJsonPath('data.paymentStatus', 'unpaid')
            ->assertJsonPath('data.total', 153000)
            ->assertJsonPath('data.payment.canPay', true);
        $this->assertStringNotContainsString('A01234567', $response->getContent(), 'passport numbers never leave the API');
    }

    #[Test]
    public function browsers_may_send_the_booking_token_header_cross_origin(): void
    {
        config(['cors.allowed_origins' => ['https://bhabaghure.test']]);

        $this->call('OPTIONS', '/api/v1/public/bookings/BH-2609-001/payments', server: [
            'HTTP_ORIGIN' => 'https://bhabaghure.test',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type,x-booking-token,x-locale',
        ])->assertNoContent()->assertHeader('Access-Control-Allow-Origin', 'https://bhabaghure.test');

        $allowed = strtolower((string) $this->call('OPTIONS', '/api/v1/public/bookings/BH-2609-001', server: [
            'HTTP_ORIGIN' => 'https://bhabaghure.test', 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET', 'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'x-booking-token',
        ])->headers->get('Access-Control-Allow-Headers'));
        $this->assertStringContainsString('x-booking-token', $allowed);
    }

    #[Test]
    public function a_total_the_customer_did_not_see_is_answered_with_the_new_quote(): void
    {
        $this->postJson('/api/v1/public/bookings', $this->bookingPayload(['expected_total' => 150000]))
            ->assertStatus(409)
            ->assertJsonPath('code', 'price_changed')
            ->assertJsonPath('quote.total', 153000);
        $this->assertSame(0, Booking::query()->count());
    }

    #[Test]
    public function a_validated_payment_settles_once_however_many_times_the_callbacks_arrive(): void
    {
        [$reference, $token] = $this->createBooking();
        $attempt = $this->startPayment($reference, $token, 'bkash');

        Http::assertSent(fn (HttpRequest $request) => str_contains($request->url(), '/gwprocess/v4/api.php')
            && $request['store_id'] === 'bhaba0test' && $request['total_amount'] === '153000.00' && $request['currency'] === 'BDT'
            && $request['tran_id'] === $attempt->tran_id && $request['multi_card_name'] === 'bkash'
            && str_ends_with($request['ipn_url'], '/api/v1/payments/sslcommerz/ipn'));

        $this->validations['VAL-1'] = $this->valid($attempt, '153000.00');

        $this->post('/api/v1/payments/sslcommerz/success', ['tran_id' => $attempt->tran_id, 'val_id' => 'VAL-1', 'amount' => '1.00'])
            ->assertStatus(303)->assertRedirect("https://bhabaghure.test/en/booking/{$reference}");
        // The browser redirect and the IPN race, and SSLCommerz retries the IPN.
        $this->post('/api/v1/payments/sslcommerz/success', ['tran_id' => $attempt->tran_id, 'val_id' => 'VAL-1'])->assertStatus(303);
        $this->post('/api/v1/payments/sslcommerz/ipn', $this->signed(['tran_id' => $attempt->tran_id, 'val_id' => 'VAL-1', 'status' => 'VALID', 'amount' => '153000.00']))->assertOk();

        $booking = Booking::query()->where('reference', $reference)->firstOrFail();
        $this->assertSame(['confirmed', 'paid', '153000.00', '0.00'], [$booking->status->value, $booking->payment_status, $booking->paid_amount, $booking->due_amount]);
        $this->assertSame(1, Transaction::query()->where('booking_id', $booking->id)->count(), 'credited exactly once');
        $this->assertSame(PaymentAttemptStatus::Settled, $attempt->fresh()->status);
        $this->assertNotNull($booking->invoice?->invoice_number, 'settling issues the invoice');
        $this->assertSame((float) JournalLine::query()->sum('debit'), (float) JournalLine::query()->sum('credit'));

        $this->getJson("/api/v1/public/bookings/{$reference}", ['X-Booking-Token' => $token])
            ->assertJsonPath('data.paymentStatus', 'paid')->assertJsonPath('data.payment.canPay', false)
            ->assertJsonPath('data.invoice.number', $booking->invoice->invoice_number);
    }

    #[Test]
    public function posted_fields_are_never_trusted(): void
    {
        [$reference, $token] = $this->createBooking();
        $attempt = $this->startPayment($reference, $token);

        // A forged success post: SSLCommerz doesn't know this val_id.
        $this->post('/api/v1/payments/sslcommerz/success', ['tran_id' => $attempt->tran_id, 'val_id' => 'FORGED', 'status' => 'VALID', 'amount' => '153000.00'])->assertStatus(303);
        // A real val_id for a different transaction.
        $this->validations['OTHER'] = ['status' => 'VALID', 'tran_id' => 'SOMEONE-ELSE', 'amount' => '153000.00', 'currency' => 'BDT', 'risk_level' => '0'];
        $this->post('/api/v1/payments/sslcommerz/success', ['tran_id' => $attempt->tran_id, 'val_id' => 'OTHER'])->assertStatus(303);

        $this->assertSame(0, Transaction::query()->count());
        $this->assertSame('unpaid', Booking::query()->value('payment_status'));
        $this->assertTrue($attempt->fresh()->status->isOpen());
    }

    #[Test]
    public function less_money_than_expected_or_a_risky_payment_goes_to_review_without_crediting(): void
    {
        [$reference, $token] = $this->createBooking();
        $attempt = $this->startPayment($reference, $token);
        $this->validations['LOW'] = $this->valid($attempt, '15300.00');

        $this->post('/api/v1/payments/sslcommerz/success', ['tran_id' => $attempt->tran_id, 'val_id' => 'LOW'])->assertStatus(303);

        $this->assertSame([PaymentAttemptStatus::NeedsReview, 'amount_below_expected'], [$attempt->fresh()->status, $attempt->fresh()->failure_reason]);
        $this->assertSame(0, Transaction::query()->count());
        $this->assertSame('inquiry', Booking::query()->firstOrFail()->status->value);
    }

    #[Test]
    public function by_default_the_gateway_fee_is_a_business_cost_and_the_customer_pays_exactly_the_booking_total(): void
    {
        [$reference, $token] = $this->createBooking();
        // Nothing added: the website shows 1,53,000 and SSLCommerz is asked for 1,53,000.
        $this->getJson("/api/v1/public/bookings/{$reference}", ['X-Booking-Token' => $token])
            ->assertJsonPath('data.payment.online', ['amount' => 153000, 'chargePercent' => 0, 'charge' => 0, 'total' => 153000]);
        $attempt = $this->startPayment($reference, $token, 'card');
        Http::assertSent(fn (HttpRequest $request) => str_contains($request->url(), '/gwprocess/') && $request['total_amount'] === '153000.00');

        // As on the sandbox store: the customer pays 1,53,000; SSLCommerz settles 1,49,175 after its 2.5% fee.
        $this->validations['VAL'] = $this->valid($attempt, '153000.00', storeAmount: '149175.00');
        $this->post('/api/v1/payments/sslcommerz/success', ['tran_id' => $attempt->tran_id, 'val_id' => 'VAL'])->assertStatus(303);

        $booking = Booking::query()->firstOrFail();
        $this->assertSame(['153000.00', '0.00', 'paid'], [$booking->paid_amount, $booking->due_amount, $booking->payment_status]);
        $this->assertSame(['0.00', '3825.00', '0.00'], [$attempt->fresh()->online_charge, $attempt->fresh()->gateway_fee, $attempt->fresh()->gateway_surcharge]);
        $this->assertSame([['customer_payment', 'in', '153000.00'], ['gateway_fee', 'out', '3825.00']],
            Transaction::query()->orderBy('id')->get()->map(fn ($t) => [$t->category, $t->direction->value, $t->amount])->all());
        $this->assertSame(3825.0, $this->balance(Account::GATEWAY_FEES));
        $this->assertSame(149175.0, $this->balance(Account::SSLCOMMERZ_CLEARING), 'the clearing account expects what SSLCommerz will settle');
        $this->assertSame((float) JournalLine::query()->sum('debit'), (float) JournalLine::query()->sum('credit'));
    }

    #[Test]
    public function a_configured_online_payment_charge_is_shown_charged_and_booked_as_its_own_line(): void
    {
        $this->setOnlineCharge(2.5);
        [$reference, $token] = $this->createBooking();

        $this->getJson("/api/v1/public/bookings/{$reference}", ['X-Booking-Token' => $token])
            ->assertJsonPath('data.total', 153000)
            ->assertJsonPath('data.payment.online', ['amount' => 153000, 'chargePercent' => 2.5, 'charge' => 3825, 'total' => 156825]);

        // A total the customer didn't see is refused before anything is sent to SSLCommerz.
        $this->postJson("/api/v1/public/bookings/{$reference}/payments", ['method' => 'card', 'expected_total' => 153000], ['X-Booking-Token' => $token])
            ->assertStatus(409)->assertJsonPath('code', 'price_changed')->assertJsonPath('payment.total', 156825);
        Http::assertNotSent(fn (HttpRequest $request) => str_contains($request->url(), '/gwprocess/'));

        $attempt = $this->startPayment($reference, $token, 'card', expectedTotal: 156825);
        Http::assertSent(fn (HttpRequest $request) => str_contains($request->url(), '/gwprocess/') && $request['total_amount'] === '156825.00');

        $this->validations['VAL'] = $this->valid($attempt, '156825.00', storeAmount: '152904.38');
        $this->post('/api/v1/payments/sslcommerz/success', ['tran_id' => $attempt->tran_id, 'val_id' => 'VAL'])->assertStatus(303);

        $booking = Booking::query()->firstOrFail();
        $this->assertSame(['153000.00', 'paid'], [$booking->paid_amount, $booking->payment_status], 'the booking gets exactly its amount');
        $this->assertSame(['3825.00', '3920.62'], [$attempt->fresh()->online_charge, $attempt->fresh()->gateway_fee]);
        $this->assertSame(-3825.0, $this->balance(Account::ONLINE_PAYMENT_CHARGES));
        $this->assertSame(3920.62, $this->balance(Account::GATEWAY_FEES));
        $this->assertSame((float) JournalLine::query()->sum('debit'), (float) JournalLine::query()->sum('credit'));
    }

    #[Test]
    public function a_gateway_that_collects_more_than_was_shown_is_flagged_for_staff(): void
    {
        [$reference, $token] = $this->createBooking();
        $attempt = $this->startPayment($reference, $token, 'card');
        $this->validations['VAL'] = $this->valid($attempt, '156825.00', storeAmount: '153000.00');

        $this->post('/api/v1/payments/sslcommerz/success', ['tran_id' => $attempt->tran_id, 'val_id' => 'VAL'])->assertStatus(303);

        // The money was taken, so the booking is paid — but staff are told to refund the difference and fix the store.
        $this->assertSame('paid', Booking::query()->value('payment_status'));
        $this->assertSame(['3825.00', 'gateway_surcharge'], [$attempt->fresh()->gateway_surcharge, $attempt->fresh()->failure_reason]);
        $this->assertTrue(AuditLog::query()->where('action', 'payment.gateway_surcharge')->exists());
        $this->assertSame(['153000.00'], Transaction::query()->pluck('amount')->all(), 'the surcharge is not booked as the company\'s money');
    }

    #[Test]
    public function a_failed_or_cancelled_payment_keeps_the_booking_as_an_unpaid_inquiry_and_frees_the_seats(): void
    {
        $package = TourPackage::query()->where('slug', self::MUSTANG)->firstOrFail();
        PackageDeparture::query()->create(['tour_package_id' => $package->id, 'departs_on' => '2026-10-31', 'seats_total' => 10, 'status' => 'scheduled']);
        [$reference, $token] = $this->createBooking();
        $attempt = $this->startPayment($reference, $token);

        $this->post('/api/v1/payments/sslcommerz/fail', ['tran_id' => $attempt->tran_id, 'status' => 'FAILED'])
            ->assertStatus(303)->assertRedirect("https://bhabaghure.test/en/booking/{$reference}");

        $this->assertSame(PaymentAttemptStatus::Failed, $attempt->fresh()->status);
        $this->assertSame(['inquiry', 'unpaid'], [Booking::query()->firstOrFail()->status->value, Booking::query()->value('payment_status')]);
        $this->assertSame(0, SeatHold::query()->active()->count());

        // Try again from the booking link: a new attempt, the seats held again.
        $retry = $this->startPayment($reference, $token);
        $this->assertNotSame($attempt->tran_id, $retry->tran_id);
        $this->assertSame(1, SeatHold::query()->active()->count());
        $this->post('/api/v1/payments/sslcommerz/cancel', ['tran_id' => $retry->tran_id])->assertStatus(303);
        $this->assertSame(PaymentAttemptStatus::Cancelled, $retry->fresh()->status);
        $this->assertSame(1, Booking::query()->count());
    }

    #[Test]
    public function a_fail_callback_for_money_that_was_in_fact_taken_still_credits_it(): void
    {
        [$reference, $token] = $this->createBooking();
        $attempt = $this->startPayment($reference, $token);
        $this->validations['LATE'] = $this->valid($attempt, '153000.00');
        $this->queries[$attempt->tran_id] = ['APIConnect' => 'DONE', 'no_of_trans_found' => 1, 'element' => [['status' => 'VALID', 'val_id' => 'LATE', 'tran_id' => $attempt->tran_id]]];

        $this->post('/api/v1/payments/sslcommerz/fail', ['tran_id' => $attempt->tran_id])->assertStatus(303);

        $this->assertSame(PaymentAttemptStatus::Settled, $attempt->fresh()->status);
        $this->assertSame('paid', Booking::query()->value('payment_status'));
    }

    #[Test]
    public function reconciliation_expires_attempts_nobody_came_back_from(): void
    {
        [$reference, $token] = $this->createBooking();
        $attempt = $this->startPayment($reference, $token);
        PaymentAttempt::query()->whereKey($attempt->id)->update(['expires_at' => now()->subMinute()]);

        $this->artisan('payments:reconcile')->assertSuccessful();

        $this->assertSame([PaymentAttemptStatus::Expired, 'no_callback'], [$attempt->fresh()->status, $attempt->fresh()->failure_reason]);
        $this->assertSame(1, Booking::query()->count());
    }

    #[Test]
    public function reconciliation_without_store_credentials_is_quiet_until_an_attempt_is_left_open(): void
    {
        [$reference, $token] = $this->createBooking();
        $this->storeCredentials(null);

        // Before the client's store is set up no payment can have started: nothing to do, and no error every ten minutes.
        $this->artisan('payments:reconcile')->expectsOutputToContain('not configured')->assertSuccessful();

        $this->storeCredentials('bhaba0test');
        $attempt = $this->startPayment($reference, $token);
        PaymentAttempt::query()->whereKey($attempt->id)->update(['expires_at' => now()->subMinute()]);
        $this->assertSame(PaymentAttemptStatus::Redirected, $attempt->fresh()->status);
        $this->storeCredentials(null);

        // Credentials removed while a customer's payment is unresolved: only SSLCommerz can say whether money was taken,
        // so the attempt stays open and the command fails loudly instead of expiring it.
        $this->artisan('payments:reconcile')->expectsOutputToContain('cannot be reconciled')->assertFailed();
        $this->assertSame(PaymentAttemptStatus::Redirected, $attempt->fresh()->status);
    }

    private function storeCredentials(?string $storeId): void
    {
        config(['bhabaghure.sslcommerz.store_id' => $storeId]);
        $this->app->forgetInstance(SslCommerzGateway::class);
    }

    #[Test]
    public function an_ipn_with_a_bad_signature_is_ignored(): void
    {
        [$reference, $token] = $this->createBooking();
        $attempt = $this->startPayment($reference, $token);
        $this->validations['VAL-1'] = $this->valid($attempt, '153000.00');

        $post = $this->signed(['tran_id' => $attempt->tran_id, 'val_id' => 'VAL-1', 'status' => 'VALID', 'amount' => '153000.00']);
        $post['amount'] = '1.00';
        $this->post('/api/v1/payments/sslcommerz/ipn', $post)->assertStatus(400);

        $this->assertSame(0, Transaction::query()->count());
    }

    #[Test]
    public function live_mode_is_refused_outside_production_and_missing_credentials_disable_online_payment(): void
    {
        config(['bhabaghure.sslcommerz.mode' => 'live']);
        $this->app->forgetInstance(SslCommerzGateway::class);
        $this->expectException(RuntimeException::class);
        try {
            app(SslCommerzGateway::class);
        } finally {
            config(['bhabaghure.sslcommerz.mode' => 'sandbox', 'bhabaghure.sslcommerz.store_id' => null]);
            $this->app->forgetInstance(SslCommerzGateway::class);
            [$reference, $token] = $this->createBooking();
            $this->postJson("/api/v1/public/bookings/{$reference}/payments", ['method' => 'bkash', 'expected_total' => 153000], ['X-Booking-Token' => $token])
                ->assertStatus(409)->assertJsonPath('code', 'payment_unavailable');
        }
    }

    /** @return array{0: string, 1: string} */
    private function createBooking(): array
    {
        $data = $this->postJson('/api/v1/public/bookings', $this->bookingPayload())->assertCreated()->json('data');

        return [$data['reference'], $data['accessToken']];
    }

    private function startPayment(string $reference, string $token, string $method = 'bkash', int|float $expectedTotal = 153000): PaymentAttempt
    {
        $this->postJson("/api/v1/public/bookings/{$reference}/payments", ['method' => $method, 'expected_total' => $expectedTotal], ['X-Booking-Token' => $token])
            ->assertOk()->assertJsonPath('data.amount', 153000)->assertJsonPath('data.total', $expectedTotal);

        return PaymentAttempt::query()->latest('id')->firstOrFail();
    }

    private function setOnlineCharge(float $percent): void
    {
        $settings = SiteSetting::get('pricing', []);
        SiteSetting::query()->findOrFail('pricing')->update(['value' => ['onlinePaymentChargePercent' => $percent] + $settings]);
    }

    /** Debit-positive balance of one account. */
    private function balance(string $code): float
    {
        return (float) JournalLine::query()->whereHas('account', fn ($query) => $query->where('code', $code))->selectRaw('COALESCE(SUM(debit - credit), 0) AS balance')->value('balance');
    }

    private function bookingPayload(array $overrides = []): array
    {
        return array_replace([
            'package_slug' => self::MUSTANG,
            'travel_date' => '2026-10-31',
            'pax' => 2,
            'room' => 'twin',
            'addons' => [],
            'travellers' => [
                ['name' => 'Tanvir Hasan', 'passport_number' => 'A01234567', 'date_of_birth' => '1990-04-12', 'passport_expiry' => '2030-01-31', 'phone' => '01711-000001', 'email' => 'tanvir@example.test'],
                ['name' => 'Nusrat Jahan', 'passport_number' => 'b0765 4321', 'date_of_birth' => '1992-08-03', 'passport_expiry' => '2031-05-01'],
            ],
            'expected_total' => 153000,
            'terms_accepted' => true,
            'locale' => 'en',
        ], $overrides);
    }

    /** A Validation API answer in SSLCommerz's documented shape. */
    private function valid(PaymentAttempt $attempt, string $amount, ?string $storeAmount = null): array
    {
        return [
            'status' => 'VALID', 'tran_id' => $attempt->tran_id, 'val_id' => 'VAL', 'amount' => $amount, 'store_amount' => $storeAmount ?? $amount,
            'currency' => 'BDT', 'bank_tran_id' => '2609131621451XyZ', 'card_type' => 'BKASH-BKash', 'card_issuer' => 'BKash Mobile Banking',
            'currency_type' => 'BDT', 'currency_amount' => $amount, 'currency_rate' => '1.0000', 'value_a' => $attempt->booking->reference,
            'risk_title' => 'Safe', 'risk_level' => '0', 'APIConnect' => 'DONE', 'validated_on' => now()->toDateTimeString(),
        ];
    }

    /** Signs an IPN post the way SSLCommerz does (verify_key / verify_sign). */
    private function signed(array $post): array
    {
        $keys = array_keys($post);
        $data = $post + ['store_passwd' => md5('bhaba0test@ssl')];
        ksort($data);
        $post['verify_key'] = implode(',', $keys);
        $post['verify_sign'] = md5(implode('&', array_map(fn ($k, $v) => "{$k}={$v}", array_keys($data), $data)));

        return $post;
    }
}
