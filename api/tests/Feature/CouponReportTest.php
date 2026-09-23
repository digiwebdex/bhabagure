<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Staff;
use Database\Seeders\ContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCoupons;
use Tests\TestCase;

/**
 * docs/coupons.md §2.7: Admin → Marketing → Coupon report. Used = confirmed bookings, pending = open ones, released =
 * given back; discount given and revenue count used ones.
 */
class CouponReportTest extends TestCase
{
    use CreatesCoupons, RefreshDatabase;

    private Staff $admin;

    /** @var array<string, Booking> */
    private array $bookings;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(ContentSeeder::class);
        $this->admin = $this->staff('admin');

        // One Eid campaign on two channels, a passport coupon, and an expired coupon nobody used.
        $this->coupon(['code' => 'EID-FB', 'name' => 'Eid 2026', 'channel' => 'facebook']);
        $this->coupon(['code' => 'EID-SMS', 'name' => 'Eid 2026', 'channel' => 'sms', 'discount_type' => 'fixed', 'discount_value' => 2000]);
        $this->coupon(['code' => 'PASSPORT500', 'name' => 'Loyal traveller', 'kind' => 'passport', 'passport_number' => 'B12345678', 'holder_name' => 'Nusrat Jahan',
            'discount_type' => 'fixed', 'discount_value' => 500]);
        $this->coupon(['code' => 'WINTER', 'name' => 'Winter', 'ends_at' => now()->subDay()]);

        $lead = fn (string $name, string $phone, array $second = []) => ['travellers' => [['name' => $name, 'phone' => $phone], $second]];
        $this->bookings = [
            // Confirmed: 1,37,700 after 15,000 off.
            'fb' => $this->bookingFrom($this->book(['coupon_code' => 'EID-FB'] + $lead('Tanvir Hasan', '01711000001'))),
            // Open: 1,48,000 + 2,960 = 1,50,960.
            'sms' => $this->bookingFrom($this->book(['coupon_code' => 'EID-SMS'] + $lead('Rahim Uddin', '01811000002'))),
            // Confirmed: 1,49,500 + 2,990 = 1,52,490; the holder travels second.
            'passport' => $this->bookingFrom($this->book(['coupon_code' => 'PASSPORT500'] + $lead('Karim Ahmed', '01911000003', ['name' => 'Nusrat Jahan', 'passport_number' => 'B12345678']))),
            // Cancelled before it was confirmed: its use went back.
            'cancelled' => $this->bookingFrom($this->book(['coupon_code' => 'EID-FB'] + $lead('Salma Begum', '01611000004'))),
        ];
        foreach (['fb', 'passport'] as $key) {
            $id = $this->bookings[$key]->id;
            $this->actingAsApi($this->admin)->postJson("/api/v1/admin/bookings/{$id}/invoice")->assertOk();
            $this->actingAsApi($this->admin)->postJson("/api/v1/admin/bookings/{$id}/payments", ['amount' => 20000, 'method' => 'cash', 'evidence' => $this->receipt()])->assertOk();
            $this->actingAsApi($this->admin)->postJson("/api/v1/admin/bookings/{$id}/confirm")->assertOk();
        }
        $this->actingAsApi($this->admin)->postJson("/api/v1/admin/bookings/{$this->bookings['cancelled']->id}/cancel", ['reason' => 'Changed plans'])->assertOk();
    }

    #[Test]
    public function the_report_counts_uses_discount_and_revenue_by_coupon_campaign_and_passport(): void
    {
        $report = $this->actingAsApi($this->admin)->getJson('/api/v1/admin/coupon-report')->assertOk()
            ->assertJsonPath('data.totals', [
                'coupons' => 4, 'active' => 3, 'expired' => 1, 'used' => 2, 'pending' => 1, 'released' => 1,
                'discount_given' => 15500, 'revenue' => 290190,
            ])
            ->assertJsonPath('meta.total', 4);

        $coupons = collect($report->json('data.coupons'))->keyBy('code');
        $this->assertSame(['used' => 1, 'pending' => 0, 'released' => 1, 'discount_given' => 15000, 'revenue' => 137700],
            array_intersect_key($coupons['EID-FB'], array_flip(['used', 'pending', 'released', 'discount_given', 'revenue'])));
        $this->assertSame([0, 1], [$coupons['EID-SMS']['used'], $coupons['EID-SMS']['pending']]);
        $this->assertSame([1, 500, 152490], [$coupons['PASSPORT500']['used'], $coupons['PASSPORT500']['discount_given'], $coupons['PASSPORT500']['revenue']]);
        $this->assertSame(['expired', 0], [$coupons['WINTER']['status'], $coupons['WINTER']['used']]);

        $campaigns = collect($report->json('data.campaigns'))->keyBy('name');
        $this->assertSame(['name' => 'Eid 2026', 'channels' => ['facebook', 'sms'], 'coupons' => 2, 'used' => 1, 'pending' => 1, 'discount_given' => 15000, 'revenue' => 137700],
            $campaigns['Eid 2026']);

        $this->assertSame([[
            'code' => 'PASSPORT500', 'holder_name' => 'Nusrat Jahan', 'passport_masked' => 'B1•••••78', 'reference' => $this->bookings['passport']->reference, 'status' => 'used',
        ]], collect($report->json('data.passport_uses'))->map(fn (array $use) => [
            'code' => $use['code'], 'holder_name' => $use['holder_name'], 'passport_masked' => $use['passport_masked'], 'reference' => $use['booking']['reference'], 'status' => $use['status'],
        ])->all());
        $this->assertStringNotContainsString('B12345678', $report->getContent());

        // Every use, newest first, with its booking and customer.
        $this->assertSame([$this->bookings['cancelled']->reference, $this->bookings['passport']->reference, $this->bookings['sms']->reference, $this->bookings['fb']->reference],
            array_column(array_column($report->json('data.uses'), 'booking'), 'reference'));
        $this->assertSame(['released', 'cancelled'], [$report->json('data.uses.0.status'), $report->json('data.uses.0.booking.status')]);
        $this->assertSame('Salma Begum', $report->json('data.uses.0.customer.name'));
    }

    #[Test]
    public function the_report_filters_by_status_booking_customer_passport_coupon_and_dates(): void
    {
        $get = fn (array $filters) => $this->actingAsApi($this->admin)->getJson('/api/v1/admin/coupon-report?'.http_build_query($filters))->assertOk();
        $references = fn (array $filters) => array_column(array_column($get($filters)->json('data.uses'), 'booking'), 'reference');
        $b = fn (string $key) => $this->bookings[$key]->reference;

        $this->assertSame([$b('passport'), $b('fb')], $references(['status' => 'used']));
        $get(['status' => 'used'])->assertJsonPath('data.totals.used', 2)->assertJsonPath('data.totals.pending', 0);
        $this->assertSame([$b('sms')], $references(['search' => $b('sms')]));
        $this->assertSame([$b('sms')], $references(['search' => 'Rahim']));
        $this->assertSame([$b('sms')], $references(['search' => '01811000002']));
        $this->assertSame([$b('passport')], $references(['passport' => 'b1234 5678']));
        $this->assertSame([], $references(['passport' => 'B00000000']));

        $fb = collect($get([])->json('data.coupons'))->firstWhere('code', 'EID-FB')['id'];
        $this->assertSame([$b('cancelled'), $b('fb')], $references(['coupon_id' => $fb]));
        $get(['coupon_id' => $fb])->assertJsonCount(1, 'data.coupons')->assertJsonPath('data.coupons.0.code', 'EID-FB');

        $today = now('Asia/Dhaka')->toDateString();
        $this->assertCount(4, $references(['from' => $today, 'to' => $today]));
        $tomorrow = now('Asia/Dhaka')->addDay()->toDateString();
        $get(['from' => $tomorrow])->assertJsonCount(0, 'data.uses')->assertJsonPath('data.totals.used', 0)->assertJsonPath('data.totals.discount_given', 0);
        $this->actingAsApi($this->admin)->getJson("/api/v1/admin/coupon-report?from={$tomorrow}&to={$today}")->assertUnprocessable()->assertJsonValidationErrors('to');
    }
}
