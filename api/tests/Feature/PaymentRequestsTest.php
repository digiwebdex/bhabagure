<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\PaymentRequest;
use App\Models\SiteSetting;
use App\Models\Transaction;
use Database\Seeders\ContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCoupons;
use Tests\TestCase;

/**
 * docs/payment-requests.md: a customer who paid by bank transfer or bKash sends the amount, the account, the date, a
 * reference and a screenshot, from the booking page or the portal; staff approve it — the payment is recorded with the
 * screenshot as its receipt and an inquiry is confirmed — or reject it with a reason the customer reads.
 */
class PaymentRequestsTest extends TestCase
{
    use CreatesCoupons;
    use RefreshDatabase;

    private const MTB = ['bankName' => 'Mutual Trust Bank', 'accountName' => 'Example Holidays', 'accountNumber' => '1310000000001', 'branch' => 'Mirpur', 'routingNumber' => '145260001', 'transferType' => 'NPSB'];

    private const BRAC = ['bankName' => 'BRAC Bank', 'accountName' => 'Example Holidays', 'accountNumber' => '2020000000002', 'branch' => 'Gulshan', 'routingNumber' => '060260002', 'transferType' => 'NPSB'];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(ContentSeeder::class);
        SiteSetting::query()->updateOrCreate(['key' => 'payment'], ['value' => ['banks' => [self::MTB, self::BRAC], 'link' => null, 'bkash' => ['number' => '+8801613000000', 'chargePercent' => 1.3]]]);
    }

    #[Test]
    public function the_customer_sends_a_bank_payment_with_a_screenshot_and_approving_records_it_and_confirms_the_booking(): void
    {
        $created = $this->book()->assertCreated();
        $token = $created->json('data.accessToken');
        $booking = $this->bookingFrom($created);
        $this->assertSame([BookingStatus::Inquiry, '153000.00'], [$booking->status, $booking->due_amount]);
        $bank = ['method' => 'bank_transfer', 'account_number' => self::BRAC['accountNumber'], 'amount' => '50000', 'paid_on' => now('Asia/Dhaka')->toDateString(), 'reference' => 'NPSB-778899'];

        // Only the booking's own customer; a screenshot always; one of the office's accounts; no more than is due.
        $this->send($booking->reference, $bank, null)->assertNotFound();
        $this->send($booking->reference, $bank, $token, withScreenshot: false)->assertUnprocessable()->assertJsonValidationErrors('screenshot');
        $this->send($booking->reference, ['account_number' => '999'] + $bank, $token)->assertUnprocessable()->assertJsonPath('code', 'unknown_account');
        $this->send($booking->reference, ['amount' => '200000'] + $bank, $token)->assertUnprocessable()->assertJsonPath('code', 'over_due');
        $this->send($booking->reference, ['paid_on' => now('Asia/Dhaka')->addDay()->toDateString()] + $bank, $token)->assertUnprocessable()->assertJsonValidationErrors('paid_on');

        $this->send($booking->reference, $bank, $token)->assertCreated()
            ->assertJsonPath('data.0.status', 'pending')->assertJsonPath('data.0.bankName', 'BRAC Bank')->assertJsonPath('data.0.amount', 50000);
        $this->send($booking->reference, $bank, $token)->assertUnprocessable()->assertJsonPath('code', 'duplicate_reference');
        $request = PaymentRequest::query()->sole();
        $this->assertSame(['booking_page', 'pending'], [$request->via, $request->status]);
        Storage::disk('local')->assertExists($request->evidence_path);
        $this->getJson("/api/v1/public/bookings/{$booking->reference}", ['X-Booking-Token' => $token])->assertJsonPath('data.paymentRequests.0.status', 'pending');

        // Staff who record payments see it, with its screenshot; others don't.
        $admin = $this->staff('admin');
        $this->actingAsApi($this->staff('sales_agent'))->getJson('/api/v1/admin/payment-requests')->assertForbidden();
        $this->actingAsApi($admin)->getJson('/api/v1/admin/payment-requests')->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.booking.reference', $booking->reference)->assertJsonPath('data.0.reference', 'NPSB-778899');
        $this->actingAsApi($admin)->get("/api/v1/admin/payment-requests/{$request->id}/file")->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->actingAsApi($admin)->getJson("/api/v1/admin/bookings/{$booking->id}")->assertJsonPath('data.payment_requests.0.id', $request->id)
            ->assertJsonPath('data.actions.decide_payment_requests', true);

        // Approved: the payment recorded with the screenshot as its receipt, the invoice issued, the booking confirmed.
        $this->actingAsApi($admin)->postJson("/api/v1/admin/payment-requests/{$request->id}/approve", ['amount' => 50000])->assertOk()
            ->assertJsonPath('data.status', 'approved')->assertJsonPath('data.approved_amount', 50000);
        $booking->refresh();
        $this->assertSame([BookingStatus::Confirmed, '50000.00', '103000.00', 'partial'], [$booking->status, $booking->paid_amount, $booking->due_amount, $booking->payment_status]);
        $payment = Transaction::query()->findOrFail($request->fresh()->transaction_id);
        $this->assertSame(['bank_transfer', '50000.00', $request->evidence_path, 'NPSB-778899'], [$payment->method, $payment->amount, $payment->evidence_path, $payment->external_ref]);
        $this->assertSame(1, Invoice::query()->where('booking_id', $booking->id)->where('status', Invoice::ISSUED)->count());
        $this->actingAsApi($admin)->postJson("/api/v1/admin/payment-requests/{$request->id}/approve", ['amount' => 50000])->assertConflict()->assertJsonPath('code', 'already_decided');
        $this->getJson("/api/v1/public/bookings/{$booking->reference}", ['X-Booking-Token' => $token])
            ->assertJsonPath('data.paymentRequests.0.status', 'approved')->assertJsonPath('data.due', 103000);
    }

    #[Test]
    public function from_the_portal_a_bkash_payment_with_its_charge_is_approved_and_a_wrong_one_is_rejected_with_a_reason(): void
    {
        $booking = $this->bookingFrom($this->book()->assertCreated());
        $customer = Customer::query()->findOrFail($booking->customer_id);
        $bkash = ['method' => 'bkash', 'amount' => '10000', 'paid_on' => now('Asia/Dhaka')->toDateString()];

        // A bKash payment needs its transaction ID.
        $this->actingAsApi($customer)->post("/api/v1/public/bookings/{$booking->reference}/payment-requests", $bkash + ['screenshot' => self::screenshot()], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('reference');
        $this->actingAsApi($customer)->post("/api/v1/public/bookings/{$booking->reference}/payment-requests", $bkash + ['reference' => 'BK7Q2X', 'screenshot' => self::screenshot()], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.0.bankName', 'bKash');
        $this->actingAsApi($customer)->post("/api/v1/public/bookings/{$booking->reference}/payment-requests", ['amount' => '500', 'reference' => 'BK-WRONG'] + $bkash + ['screenshot' => self::screenshot()], ['Accept' => 'application/json'])
            ->assertCreated();
        [$good, $wrong] = PaymentRequest::query()->orderBy('id')->get()->all();
        $this->assertSame('portal', $good->via);

        // Approved with the bKash charge the customer sent on top: booked as a charge, not as payment for the tour.
        $accountant = $this->staff('accountant');
        $this->actingAsApi($accountant)->postJson("/api/v1/admin/payment-requests/{$good->id}/approve", ['amount' => 10000, 'bkash_charge' => true])->assertOk();
        $booking->refresh();
        $this->assertSame([BookingStatus::Confirmed, '10000.00'], [$booking->status, $booking->paid_amount]);
        $this->assertSame(2, Transaction::query()->where('booking_id', $booking->id)->where('method', 'bkash')->count());

        // Rejected with a reason the customer reads.
        $this->actingAsApi($accountant)->postJson("/api/v1/admin/payment-requests/{$wrong->id}/reject", ['reason' => 'No'])->assertUnprocessable();
        $this->actingAsApi($accountant)->postJson("/api/v1/admin/payment-requests/{$wrong->id}/reject", ['reason' => 'Not found in our bKash statement'])->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->assertSame('10000.00', $booking->fresh()->paid_amount);
        $mine = collect($this->actingAsApi($customer)->getJson("/api/v1/public/bookings/{$booking->reference}")->json('data.paymentRequests'))->firstWhere('id', $wrong->id);
        $this->assertSame(['rejected', 'Not found in our bKash statement'], [$mine['status'], $mine['note']]);
        $this->actingAsApi($accountant)->getJson('/api/v1/admin/payment-requests?status=all&search=BK7Q2X')->assertJsonPath('meta.total', 1);
    }

    /** @param array<string, string> $fields */
    private function send(string $reference, array $fields, ?string $token, bool $withScreenshot = true): TestResponse
    {
        return $this->post("/api/v1/public/bookings/{$reference}/payment-requests", $fields + ($withScreenshot ? ['screenshot' => self::screenshot()] : []),
            ['Accept' => 'application/json'] + ($token ? ['X-Booking-Token' => $token] : []));
    }

    private static function screenshot(): UploadedFile
    {
        return UploadedFile::fake()->create('transfer.png', 120, 'image/png');
    }
}
