<?php

namespace Tests\Concerns;

use App\Models\Booking;
use App\Models\Coupon;
use Illuminate\Testing\TestResponse;

/** Coupons and website bookings with them (docs/coupons.md). Needs ContentSeeder: the Mustang package for two is ৳1,50,000 + 2%. */
trait CreatesCoupons
{
    protected const MUSTANG = 'nepal-mustang-adventure-tour-8-days-7-nights';

    /** A coupon straight into the table; CouponManager's own rules are tested through the admin API. */
    protected function coupon(array $attributes = []): Coupon
    {
        return Coupon::query()->create([
            'code' => 'TRAVEL10',
            'name' => 'Website promotion',
            'kind' => Coupon::PUBLIC,
            'discount_type' => Coupon::PERCENT,
            'discount_value' => 10,
            'applies_to' => Coupon::APPLIES_ALL,
            'is_active' => true,
            ...$attributes,
        ]);
    }

    /**
     * A website booking as the form makes it: ask with a total of 0 to learn the API's (409 price_changed), then book at
     * that total. Any other first answer — coupon_invalid, say — comes straight back.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function book(array $overrides = []): TestResponse
    {
        $payload = $this->bookingPayload($overrides);
        $first = $this->postJson('/api/v1/public/bookings', $payload + ['expected_total' => 0]);
        if ($first->json('code') !== 'price_changed') {
            return $first;
        }

        return $this->postJson('/api/v1/public/bookings', $payload + ['expected_total' => $first->json('quote.total')]);
    }

    /**
     * Mustang for two, the lead Tanvir Hasan on 01711000001; no expected_total.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function bookingPayload(array $overrides = []): array
    {
        return [
            'package_slug' => self::MUSTANG,
            'travel_date' => now('Asia/Dhaka')->addDays(46)->toDateString(),
            'pax' => 2,
            'room' => 'twin',
            'addons' => [],
            'travellers' => [['name' => 'Tanvir Hasan', 'phone' => '01711000001'], []],
            'terms_accepted' => true,
            'locale' => 'en',
            ...$overrides,
        ];
    }

    protected function bookingFrom(TestResponse $created): Booking
    {
        return Booking::query()->where('reference', $created->assertCreated()->json('data.reference'))->firstOrFail();
    }

    /**
     * The booking form's Apply button for Mustang, two travellers, the lead's number 01711000001.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function checkCoupon(string $code, array $overrides = []): TestResponse
    {
        return $this->postJson('/api/v1/public/coupons/check', [
            'code' => $code,
            'package_slug' => self::MUSTANG,
            'pax' => 2,
            'room' => 'twin',
            'addons' => [],
            'phone' => '01711-000001',
            'passport_numbers' => [],
            'locale' => 'en',
            ...$overrides,
        ]);
    }
}
