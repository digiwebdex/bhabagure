<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Booking;
use App\Models\BookingTraveller;
use App\Models\CouponRedemption;
use App\Models\Invoice;
use App\Models\JournalEntry;
use Database\Seeders\ContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCoupons;
use Tests\TestCase;

/**
 * docs/coupons.md §2.4–2.5: a coupon through the booking, the invoice, payments, the due and the books, and every use
 * recorded. Mustang for two is ৳1,50,000 + 2% = ৳1,53,000; with 10% off, 1,35,000 + 2,700 = ৳1,37,700.
 */
class CouponBookingTest extends TestCase
{
    use CreatesCoupons, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(ContentSeeder::class);
    }

    #[Test]
    public function a_booking_with_a_coupon_keeps_the_discount_and_the_terms_it_was_given(): void
    {
        $coupon = $this->coupon(['code' => 'TRAVEL10', 'discount_value' => 10, 'max_discount_amount' => 20000]);

        $created = $this->book(['coupon_code' => 'travel10'])->assertCreated()
            ->assertJsonPath('data.discount', 15000)
            ->assertJsonPath('data.coupon', ['code' => 'TRAVEL10', 'discount' => 15000])
            ->assertJsonPath('data.serviceCharge', 2700)
            ->assertJsonPath('data.total', 137700)
            ->assertJsonPath('data.due', 137700);
        $booking = $this->bookingFrom($created);
        $this->assertSame(['15000.00', '15000.00', '2700.00', '137700.00', '137700.00'],
            [$booking->discount_amount, $booking->coupon_discount_amount, $booking->vat_amount, $booking->total_amount, $booking->due_amount]);

        $use = CouponRedemption::query()->sole();
        $this->assertSame(
            [$coupon->id, $booking->id, $booking->customer_id, 'TRAVEL10', 'public', 'percent', '10.00', '20000.00', 'reserved', 'website', null],
            [$use->coupon_id, $use->booking_id, $use->customer_id, $use->code, $use->kind, $use->discount_type, $use->discount_value, $use->max_discount_amount, $use->status, $use->source, $use->applied_by_staff_id],
        );
        $this->assertSame(['150000.00', '15000.00', '153000.00', '137700.00'], [$use->eligible_amount, $use->discount_amount, $use->original_total, $use->final_total]);

        // A later change to the coupon reaches later bookings only.
        $coupon->update(['discount_value' => 50]);
        $this->assertSame('137700.00', $booking->fresh()->total_amount);
        $this->assertSame('10.00', $use->fresh()->discount_value);
    }

    #[Test]
    public function the_browser_cannot_choose_its_own_discount(): void
    {
        $this->coupon(['code' => 'TRAVEL10']);
        $payload = $this->bookingPayload(['coupon_code' => 'TRAVEL10']);

        // A discount sent with the booking is ignored; the API's own is 15,000.
        $this->postJson('/api/v1/public/bookings', $payload + ['discount' => 150000, 'coupon_discount' => 150000, 'discount_amount' => 150000, 'expected_total' => 3000])
            ->assertStatus(409)->assertJsonPath('code', 'price_changed')->assertJsonPath('quote.discount', 15000)->assertJsonPath('quote.total', 137700);
        // Nor does a total that assumes more off book anything, or the discounted total without the code.
        $this->postJson('/api/v1/public/bookings', $payload + ['expected_total' => 100000])->assertStatus(409)->assertJsonPath('code', 'price_changed');
        $this->postJson('/api/v1/public/bookings', $this->bookingPayload() + ['expected_total' => 137700])
            ->assertStatus(409)->assertJsonPath('quote.total', 153000)->assertJsonPath('quote.discount', 0);

        $this->assertSame(0, Booking::query()->count());
        $this->assertSame(0, CouponRedemption::query()->count());
    }

    #[Test]
    public function a_coupon_that_stops_working_before_the_booking_is_made_refuses_it_and_says_why(): void
    {
        $coupon = $this->coupon(['code' => 'TRAVEL10']);
        $this->checkCoupon('TRAVEL10')->assertJsonPath('data.valid', true)->assertJsonPath('data.total', 137700);
        $coupon->update(['is_active' => false]);

        $this->postJson('/api/v1/public/bookings', $this->bookingPayload(['coupon_code' => 'TRAVEL10', 'locale' => 'bn']) + ['expected_total' => 137700])
            ->assertStatus(409)->assertJsonPath('code', 'coupon_invalid')->assertJsonPath('reason', 'inactive')->assertJsonPath('message', 'এই কুপনটি এখন চালু নেই।');
        $this->assertSame(0, Booking::query()->count());
        $this->assertSame(0, CouponRedemption::query()->count());

        // Without it the customer books at the full price.
        $this->book()->assertCreated()->assertJsonPath('data.total', 153000)->assertJsonPath('data.coupon', null);
    }

    #[Test]
    public function the_invoice_payments_due_and_books_follow_the_discounted_total_and_confirming_counts_the_use(): void
    {
        $this->coupon(['code' => 'TRAVEL10']);
        $created = $this->book(['coupon_code' => 'TRAVEL10']);
        $booking = $this->bookingFrom($created);
        $accountant = $this->staff('accountant');
        $admin = $this->staff('admin');
        $url = "/api/v1/admin/bookings/{$booking->id}";

        $this->actingAsApi($accountant)->postJson("{$url}/invoice")->assertOk();
        $invoice = Invoice::query()->where('booking_id', $booking->id)->sole();
        $this->assertSame(['150000.00', '15000.00', 'TRAVEL10', '15000.00', '2700.00', '137700.00'],
            [$invoice->subtotal_amount, $invoice->discount_amount, $invoice->coupon_code, $invoice->coupon_discount_amount, $invoice->vat_amount, $invoice->total_amount]);

        // Printed: Subtotal, the coupon's own line, service charge, and the total — no second discount line.
        $html = $this->actingAsApi($admin)->get("{$url}/invoice/print")->assertOk()->getContent();
        $this->assertStringContainsString('<span>Coupon discount (TRAVEL10)</span><span class="num">− BDT 15,000</span>', $html);
        $this->assertStringContainsString('<span>Total</span><span class="num">BDT 1,37,700</span>', $html);
        $this->assertStringNotContainsString('<span>Discount</span>', $html);

        // The books take the invoice's net total: receivable and sales after the coupon, VAT on what is left.
        $entry = JournalEntry::query()->where('source_type', $invoice->getMorphClass())->where('source_id', $invoice->id)->sole();
        $lines = $entry->lines()->with('account')->get()->mapWithKeys(fn ($line) => [$line->account->code => [(float) $line->debit, (float) $line->credit]])->all();
        $this->assertEquals([Account::RECEIVABLE => [137700.0, 0.0], Account::PACKAGE_SALES => [0.0, 135000.0], Account::VAT_PAYABLE => [0.0, 2700.0]], $lines);

        // Paying: the due is the discounted total, and nothing more can be paid.
        $this->actingAsApi($accountant)->postJson("{$url}/payments", ['amount' => 137701, 'method' => 'cash', 'evidence' => $this->receipt()])
            ->assertUnprocessable()->assertJsonPath('code', 'exceeds_balance');
        $this->actingAsApi($accountant)->postJson("{$url}/payments", ['amount' => 50000, 'method' => 'cash', 'evidence' => $this->receipt()])->assertOk()
            ->assertJsonPath('data.paid_amount', 50000)->assertJsonPath('data.due_amount', 87700)->assertJsonPath('data.payment_status', 'partial')
            ->assertJsonPath('data.invoices.0.balance_due', 87700);
        $this->assertSame(CouponRedemption::RESERVED, CouponRedemption::query()->sole()->status);

        $this->actingAsApi($admin)->postJson("{$url}/confirm")->assertOk();
        $use = CouponRedemption::query()->sole();
        $this->assertSame(CouponRedemption::USED, $use->status);
        $this->assertNotNull($use->used_at);

        $this->actingAsApi($accountant)->postJson("{$url}/payments", ['amount' => 87700, 'method' => 'bkash', 'reference' => 'BK88', 'evidence' => $this->receipt()])->assertOk()
            ->assertJsonPath('data.due_amount', 0)->assertJsonPath('data.payment_status', 'paid');

        // The customer's booking page shows the same, the coupon as its own line.
        $this->getJson("/api/v1/public/bookings/{$booking->reference}", ['X-Booking-Token' => $created->json('data.accessToken')])->assertOk()
            ->assertJsonPath('data.coupon', ['code' => 'TRAVEL10', 'discount' => 15000])->assertJsonPath('data.total', 137700)
            ->assertJsonPath('data.paid', 137700)->assertJsonPath('data.due', 0);
    }

    #[Test]
    public function cancelling_gives_back_an_unconfirmed_bookings_use_but_a_confirmed_one_stays_used(): void
    {
        $this->coupon(['code' => 'ONCE', 'usage_limit' => 1]);
        $admin = $this->staff('admin');
        $first = $this->bookingFrom($this->book(['coupon_code' => 'ONCE']));
        $this->checkCoupon('ONCE', ['phone' => '01811000002'])->assertJsonPath('data.reason', 'used_up');

        $this->actingAsApi($admin)->postJson("/api/v1/admin/bookings/{$first->id}/cancel", ['reason' => 'Changed plans'])->assertOk()
            // The cancelled booking keeps the price it was given, and says which coupon was in it.
            ->assertJsonPath('data.coupon.code', 'ONCE')->assertJsonPath('data.coupon.status', 'released')->assertJsonPath('data.total_amount', 137700);
        $this->assertSame(['released', 'booking_cancelled'], [CouponRedemption::query()->sole()->status, CouponRedemption::query()->sole()->release_reason]);
        $this->checkCoupon('ONCE', ['phone' => '01811000002'])->assertJsonPath('data.valid', true);

        $second = $this->bookingFrom($this->book(['coupon_code' => 'ONCE', 'travellers' => [['name' => 'Rahim Uddin', 'phone' => '01811000002'], []]]));
        foreach ([['invoice', []], ['payments', ['amount' => 10000, 'method' => 'cash', 'evidence' => $this->receipt()]], ['confirm', []], ['cancel', ['reason' => 'Visa refused']]] as [$action, $body]) {
            $this->actingAsApi($admin)->postJson("/api/v1/admin/bookings/{$second->id}/{$action}", $body)->assertOk();
        }
        // Decided 2026-09-24: a confirmed booking's use stays used, cancelled or not.
        $this->assertSame('used', CouponRedemption::query()->where('booking_id', $second->id)->value('status'));
        $this->checkCoupon('ONCE', ['phone' => '01911000003'])->assertJsonPath('data.reason', 'used_up');
    }

    #[Test]
    public function deleting_a_booking_made_by_mistake_gives_its_use_back(): void
    {
        $this->coupon(['code' => 'ONCE', 'usage_limit' => 1]);
        $booking = $this->bookingFrom($this->book(['coupon_code' => 'ONCE']));

        $this->actingAsApi($this->staff('admin'))->deleteJson("/api/v1/admin/bookings/{$booking->id}")->assertNoContent();
        $this->assertSame(['released', 'booking_deleted'], [CouponRedemption::query()->sole()->status, CouponRedemption::query()->sole()->release_reason]);
        $this->checkCoupon('ONCE', ['phone' => '01811000002'])->assertJsonPath('data.valid', true);
    }

    #[Test]
    public function staff_apply_and_remove_a_customers_coupon_on_the_draft_invoice(): void
    {
        $this->coupon(['code' => 'TRAVEL10']);
        $this->coupon(['code' => 'BIGTRIP', 'discount_type' => 'fixed', 'discount_value' => 3000, 'min_booking_amount' => 150000]);
        $admin = $this->staff('admin');
        $booking = $this->bookingFrom($this->book());
        $url = "/api/v1/admin/bookings/{$booking->id}";

        $this->actingAsApi($admin)->getJson($url)->assertOk()->assertJsonPath('data.coupon', null)
            ->assertJsonPath('data.actions.apply_coupon', true)->assertJsonPath('data.actions.remove_coupon', false);
        $this->actingAsApi($admin)->postJson("{$url}/coupon", ['code' => 'nope'])->assertUnprocessable()->assertJsonPath('code', 'coupon_invalid')->assertJsonPath('reason', 'not_found');

        $this->actingAsApi($admin)->postJson("{$url}/coupon", ['code' => 'travel10'])->assertOk()
            ->assertJsonPath('data.coupon.code', 'TRAVEL10')->assertJsonPath('data.coupon.source', 'office')->assertJsonPath('data.coupon.status', 'reserved')
            ->assertJsonPath('data.coupon_discount_amount', 15000)->assertJsonPath('data.discount_amount', 15000)->assertJsonPath('data.total_amount', 137700)
            ->assertJsonPath('data.quote_inputs.coupon', ['type' => 'percent', 'value' => 10, 'maxDiscount' => null, 'minAmount' => null])
            ->assertJsonPath('data.actions.apply_coupon', false)->assertJsonPath('data.actions.remove_coupon', true);
        $this->assertSame($admin->id, CouponRedemption::query()->sole()->applied_by_staff_id);
        $this->actingAsApi($admin)->postJson("{$url}/coupon", ['code' => 'BIGTRIP'])->assertUnprocessable()->assertJsonPath('reason', 'already_applied');

        // Three travellers and the staff's own 1,000 on top: 72,750 × 3 = 2,18,250; 10% = 21,825; 2% of 1,95,425 = 3,909.
        $this->actingAsApi($admin)->putJson("{$url}/quote", ['pax' => 3, 'room' => 'twin', 'discount' => 1000, 'vat_rate' => 2, 'expected_total' => 0])
            ->assertStatus(409)->assertJsonPath('quote.couponDiscount', 21825)->assertJsonPath('quote.discount', 22825)->assertJsonPath('quote.total', 199334);
        $this->actingAsApi($admin)->putJson("{$url}/quote", ['pax' => 3, 'room' => 'twin', 'discount' => 1000, 'vat_rate' => 2, 'expected_total' => 199334])->assertOk()
            ->assertJsonPath('data.coupon_discount_amount', 21825)->assertJsonPath('data.discount_amount', 22825)->assertJsonPath('data.total_amount', 199334);
        $use = CouponRedemption::query()->sole();
        // Without the coupon it would be 2,17,250 + 4,345.
        $this->assertSame(['218250.00', '21825.00', '221595.00', '199334.00'], [$use->eligible_amount, $use->discount_amount, $use->original_total, $use->final_total]);

        // Removed: the use goes back, the staff's own discount stays.
        $this->actingAsApi($admin)->deleteJson("{$url}/coupon")->assertOk()->assertJsonPath('data.coupon', null)
            ->assertJsonPath('data.coupon_discount_amount', 0)->assertJsonPath('data.discount_amount', 1000)->assertJsonPath('data.total_amount', 221595);
        $this->assertSame(['released', 'removed_by_staff'], [$use->fresh()->status, $use->fresh()->release_reason]);

        // A coupon with a minimum: going below it is refused, never quietly undone.
        $this->actingAsApi($admin)->postJson("{$url}/coupon", ['code' => 'BIGTRIP'])->assertOk()->assertJsonPath('data.coupon_discount_amount', 3000);
        $this->actingAsApi($admin)->putJson("{$url}/quote", ['pax' => 1, 'room' => 'twin', 'discount' => 1000, 'vat_rate' => 2, 'expected_total' => 1])
            ->assertUnprocessable()->assertJsonPath('code', 'coupon_invalid')->assertJsonPath('reason', 'below_minimum')
            ->assertJsonPath('message', 'This booking\'s coupon needs at least BDT 1,50,000 before discounts. Remove the coupon first, or keep the booking at BDT 1,50,000 or more.');

        // Once the invoice is issued the coupon is frozen with the rest of the price.
        $this->actingAsApi($admin)->postJson("{$url}/invoice")->assertOk()->assertJsonPath('data.actions.remove_coupon', false);
        $this->actingAsApi($admin)->deleteJson("{$url}/coupon")->assertStatus(409)->assertJsonPath('code', 'quote_locked');

        // Who may: a sales agent claims a pool booking first; an accountant doesn't change bookings.
        $pool = $this->bookingFrom($this->book(['travellers' => [['name' => 'Rahim Uddin', 'phone' => '01811000002'], []]]));
        $this->actingAsApi($this->staff('sales_agent'))->postJson("/api/v1/admin/bookings/{$pool->id}/coupon", ['code' => 'TRAVEL10'])->assertStatus(409)->assertJsonPath('code', 'claim_first');
        $this->actingAsApi($this->staff('accountant'))->postJson("/api/v1/admin/bookings/{$pool->id}/coupon", ['code' => 'TRAVEL10'])->assertForbidden();
    }

    #[Test]
    public function a_passport_coupon_books_when_its_holder_travels_on_the_booking(): void
    {
        $this->coupon(['code' => 'PASSPORT500', 'kind' => 'passport', 'passport_number' => 'B12345678', 'holder_name' => 'Nusrat Jahan',
            'discount_type' => 'fixed', 'discount_value' => 500, 'usage_limit' => 1, 'per_customer_limit' => 1]);

        $this->book(['coupon_code' => 'PASSPORT500', 'travellers' => [['name' => 'Tanvir Hasan', 'phone' => '01711000001', 'passport_number' => 'A01234567'], []]])
            ->assertStatus(409)->assertJsonPath('code', 'coupon_invalid')->assertJsonPath('reason', 'passport');

        // The holder is the second traveller (any traveller counts, decided 2026-09-24): 1,49,500 + 2,990.
        $this->book(['coupon_code' => 'PASSPORT500', 'travellers' => [['name' => 'Tanvir Hasan', 'phone' => '01711000001'], ['name' => 'Nusrat Jahan', 'passport_number' => 'B12345678']]])
            ->assertCreated()->assertJsonPath('data.discount', 500)->assertJsonPath('data.total', 152490);
        $use = CouponRedemption::query()->sole();
        $this->assertSame(['B12345678', BookingTraveller::passportHash('B12345678'), 'passport'], [$use->passport_number, $use->passport_number_hash, $use->kind]);

        // One use: nobody gets it again.
        $this->book(['coupon_code' => 'PASSPORT500', 'travellers' => [['name' => 'Nusrat Jahan', 'phone' => '01811000002', 'passport_number' => 'B12345678'], []]])
            ->assertStatus(409)->assertJsonPath('reason', 'used_up');
    }

    #[Test]
    public function a_booking_without_a_coupon_is_exactly_as_before(): void
    {
        $this->coupon(['code' => 'TRAVEL10']);
        $booking = $this->bookingFrom($this->book()->assertJsonPath('data.total', 153000)->assertJsonPath('data.discount', 0)->assertJsonPath('data.coupon', null));
        // An empty code is no code.
        $this->book(['coupon_code' => '', 'travellers' => [['name' => 'Rahim Uddin', 'phone' => '01811000002'], []]])->assertCreated()->assertJsonPath('data.total', 153000);

        $this->assertSame(['0.00', '0.00', '153000.00'], [$booking->discount_amount, $booking->coupon_discount_amount, $booking->total_amount]);
        $this->assertSame(0, CouponRedemption::query()->count());

        $admin = $this->staff('admin');
        $this->actingAsApi($admin)->postJson("/api/v1/admin/bookings/{$booking->id}/invoice")->assertOk();
        $this->assertSame([null, '0.00'], [Invoice::query()->sole()->coupon_code, Invoice::query()->sole()->coupon_discount_amount]);
        $html = $this->actingAsApi($admin)->get("/api/v1/admin/bookings/{$booking->id}/invoice/print")->assertOk()->getContent();
        $this->assertStringNotContainsString('Coupon', $html);
        $this->assertStringNotContainsString('<span>Discount', $html);
    }
}
