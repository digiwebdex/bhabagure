<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\TourPackage;
use Database\Seeders\ContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCoupons;
use Tests\TestCase;

/**
 * docs/coupons.md §2.2–2.3: the booking form's Apply button. The API works the discount out; the answer says what the
 * customer saves, or why the code can't be used — in their language. Mustang for two is ৳1,50,000 + 2% = ৳1,53,000.
 */
class CouponCheckTest extends TestCase
{
    use CreatesCoupons, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ContentSeeder::class);
    }

    #[Test]
    public function a_percentage_coupon_comes_off_before_the_service_charge_in_any_case_the_code_is_typed(): void
    {
        $this->coupon(['code' => 'TRAVEL10', 'discount_value' => 10]);

        // 10% of 1,50,000 = 15,000; 2% of 1,35,000 = 2,700 → 1,37,700.
        $this->checkCoupon(' travel10 ')->assertOk()->assertExactJson(['data' => [
            'valid' => true, 'code' => 'TRAVEL10', 'kind' => 'public', 'discountType' => 'percent', 'discountValue' => 10,
            'maxDiscount' => null, 'minAmount' => null, 'discount' => 15000, 'subtotal' => 150000, 'originalTotal' => 153000, 'total' => 137700,
            'message' => 'Coupon applied — you save ৳ 15,000.',
        ]]);
        $this->checkCoupon('TRAVEL10', ['locale' => 'bn'])->assertJsonPath('data.message', 'কুপন যোগ হয়েছে — আপনার সাশ্রয় ৳ ১৫,০০০।');
    }

    #[Test]
    public function a_fixed_coupon_and_a_percentage_capped_at_its_maximum(): void
    {
        $this->coupon(['code' => 'FLAT5000', 'discount_type' => 'fixed', 'discount_value' => 5000]);
        $this->coupon(['code' => 'BIG20', 'discount_value' => 20, 'max_discount_amount' => 2000]);

        // 1,50,000 − 5,000 = 1,45,000; + 2,900.
        $this->checkCoupon('FLAT5000')->assertJsonPath('data.discount', 5000)->assertJsonPath('data.total', 147900);
        // 20% would be 30,000; the cap is 2,000. 1,48,000 + 2,960.
        $this->checkCoupon('BIG20')->assertJsonPath('data.discount', 2000)->assertJsonPath('data.maxDiscount', 2000)->assertJsonPath('data.total', 150960);
    }

    #[Test]
    public function unknown_switched_off_archived_not_yet_started_and_expired_codes_are_refused_with_the_reason(): void
    {
        $this->coupon(['code' => 'OFF10', 'is_active' => false]);
        $this->coupon(['code' => 'OLD10'])->delete();
        $this->coupon(['code' => 'SOON10', 'starts_at' => Carbon::parse('2030-01-15 10:00', 'Asia/Dhaka')->utc()]);
        $this->coupon(['code' => 'PAST10', 'ends_at' => Carbon::parse('2026-09-01 12:00', 'Asia/Dhaka')->utc()]);

        $refused = fn (string $code, string $reason, string $message) => $this->checkCoupon($code)->assertOk()
            ->assertJsonPath('data.valid', false)->assertJsonPath('data.reason', $reason)->assertJsonPath('data.message', $message)
            // The price without a coupon, so the form can show it.
            ->assertJsonPath('data.total', 153000);

        $refused('NOPE', 'not_found', 'This coupon code isn\'t valid. Check it and try again.');
        $refused('OFF10', 'inactive', 'This coupon isn\'t active.');
        $refused('OLD10', 'not_found', 'This coupon code isn\'t valid. Check it and try again.');
        $refused('SOON10', 'not_started', 'This coupon can be used from 15 January 2030, 10:00.');
        $refused('PAST10', 'expired', 'This coupon expired on 1 September 2026, 12:00.');
        $this->checkCoupon('PAST10', ['locale' => 'bn'])->assertJsonPath('data.message', 'এই কুপনের মেয়াদ ১ সেপ্টেম্বর ২০২৬, ১২:০০ তারিখে শেষ হয়েছে।');
    }

    #[Test]
    public function a_booking_below_the_minimum_or_of_another_package_is_refused(): void
    {
        $this->coupon(['code' => 'BIGTRIP', 'min_booking_amount' => 200000]);
        $other = TourPackage::query()->published()->where('slug', '!=', self::MUSTANG)->firstOrFail();
        $this->coupon(['code' => 'ONLYONE', 'applies_to' => Coupon::APPLIES_PACKAGES])->packages()->sync([$other->id]);

        $this->checkCoupon('BIGTRIP')->assertJsonPath('data.reason', 'min_amount')->assertJsonPath('data.message', 'This coupon needs a booking of at least ৳ 2,00,000.');
        // Four travellers reach it: 70,500 × 4 = 2,82,000.
        $this->checkCoupon('BIGTRIP', ['pax' => 4])->assertJsonPath('data.valid', true)->assertJsonPath('data.discount', 28200);

        $this->checkCoupon('ONLYONE')->assertJsonPath('data.reason', 'package');
        Coupon::query()->where('code', 'ONLYONE')->firstOrFail()->packages()->attach(TourPackage::query()->where('slug', self::MUSTANG)->value('id'));
        $this->checkCoupon('ONLYONE')->assertJsonPath('data.valid', true);
    }

    #[Test]
    public function a_passport_coupon_works_only_when_its_passport_is_on_the_booking_and_never_reveals_it(): void
    {
        $this->coupon(['code' => 'PASSPORT500', 'kind' => Coupon::PASSPORT, 'passport_number' => 'B12345678', 'discount_type' => 'fixed', 'discount_value' => 500]);

        foreach ([[], ['A01234567'], ['B12345679']] as $passports) {
            $answer = $this->checkCoupon('PASSPORT500', ['passport_numbers' => $passports])->assertJsonPath('data.reason', 'passport');
            $this->assertStringNotContainsString('B123', $answer->getContent());
        }
        // Any traveller on the booking may be the holder (decided 2026-09-24), typed any way.
        $this->checkCoupon('PASSPORT500', ['passport_numbers' => ['A01234567', 'b1234 5678']])
            ->assertJsonPath('data.valid', true)->assertJsonPath('data.kind', 'passport')->assertJsonPath('data.discount', 500);
    }

    #[Test]
    public function the_total_and_per_customer_limits_count_the_uses_bookings_hold(): void
    {
        $this->coupon(['code' => 'ONCE', 'usage_limit' => 1]);
        $this->coupon(['code' => 'EACH', 'per_customer_limit' => 1]);

        $this->book(['coupon_code' => 'ONCE'])->assertCreated();
        $this->checkCoupon('ONCE', ['phone' => '01811000002'])->assertJsonPath('data.reason', 'used_up')->assertJsonPath('data.message', 'This coupon has been used up.');

        $this->book(['coupon_code' => 'EACH'])->assertCreated();
        // The same customer (found by their WhatsApp number, however it is written) can't use it twice; someone else can.
        $this->checkCoupon('EACH', ['phone' => '+880 1711-000001'])->assertJsonPath('data.reason', 'customer_limit');
        $this->checkCoupon('EACH', ['phone' => '01811000002'])->assertJsonPath('data.valid', true);
    }

    #[Test]
    public function the_check_needs_a_booking_to_price_and_is_rate_limited(): void
    {
        $this->coupon();
        // POST only (deploy/smoke.sh reads this 405 to know the route is there without sending anything to it).
        $this->getJson('/api/v1/public/coupons/check')->assertStatus(405);
        $this->checkCoupon('TRAVEL10', ['package_slug' => null, 'pax' => 0])->assertUnprocessable()->assertJsonValidationErrors(['package_slug', 'pax']);
        $this->checkCoupon('TRAVEL10', ['package_slug' => 'no-such-package'])->assertNotFound();

        for ($i = 0; $i < 18; $i++) {
            $this->checkCoupon('GUESS'.$i)->assertOk();
        }
        // 20 a minute from one address, then no more guessing.
        $this->checkCoupon('GUESS-LAST')->assertStatus(429);
    }
}
