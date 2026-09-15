<?php

namespace Tests\Unit;

use App\Support\Pricing\PricingConfig;
use App\Support\Pricing\PricingService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The PHP pricing must give exactly the answers the website's TypeScript pricing gives, case for case, from the
 * one shared fixtures file. If this fails, a customer could be charged a different amount from the one shown.
 */
class PricingServiceTest extends TestCase
{
    private static array $fixtures;

    public static function setUpBeforeClass(): void
    {
        self::$fixtures = json_decode((string) file_get_contents(__DIR__.'/../../../packages/pricing/fixtures.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    #[Test]
    public function per_person_rates_match_the_shared_fixtures(): void
    {
        foreach (self::$fixtures['perPersonRate'] as $case) {
            $this->assertSame($case['expected'], PricingService::perPersonRate($case['list'], $case['pax'], self::$fixtures['config']['slabs']), json_encode($case));
        }
    }

    #[Test]
    public function booking_quotes_match_the_shared_fixtures_including_their_lines(): void
    {
        $config = PricingConfig::fromArray(self::$fixtures['config']);

        foreach (self::$fixtures['quoteBooking'] as $case) {
            $input = $case['input'];
            $quote = PricingService::quoteBooking($input['listPrice'], $input['pax'], $input['room'], $input['addons'], $config, $input['discount'] ?? 0, $input['chargePercent'] ?? null);
            unset($quote['pax'], $quote['slab']);

            $this->assertEquals($case['expected'], $quote, json_encode($input));
        }
    }

    #[Test]
    public function hotel_category_grid_rates_and_quotes_match_the_shared_fixtures(): void
    {
        $grid = self::$fixtures['grid'];
        $config = PricingConfig::fromArray(self::$fixtures['config']);
        $this->assertSame(['3', '4', '5'], PricingService::gridCategories($grid));
        $this->assertSame([], PricingService::gridCategories(['4' => ['2' => 90000]]));

        foreach (self::$fixtures['gridRate'] as $case) {
            $this->assertSame($case['expected'], PricingService::gridRate($grid, $case['category'], $case['pax']), json_encode($case));
        }
        foreach (self::$fixtures['quoteBookingGrid'] as $case) {
            $input = $case['input'];
            $quote = PricingService::quoteBooking($input['listPrice'], $input['pax'], $input['room'], $input['addons'], $config, $input['discount'] ?? 0, null, $grid, $input['hotelCategory']);
            $this->assertSame(0, $quote['slab']['discountPercent']);
            unset($quote['pax'], $quote['slab']);
            $this->assertEquals($case['expected'], $quote, $case['$comment']);
        }

        $this->expectException(\InvalidArgumentException::class);
        PricingService::quoteBooking(75000, 2, 'twin', [], $config, grid: $grid);
    }

    #[Test]
    public function invoice_totals_match_the_shared_fixtures(): void
    {
        foreach (self::$fixtures['invoiceTotals'] as $case) {
            $input = $case['input'];
            $this->assertEquals($case['expected'], PricingService::invoiceTotals($input['lines'], $input['discount'], $input['chargePercent']), $case['$comment']);
        }
    }

    #[Test]
    public function online_payment_charges_match_the_shared_fixtures(): void
    {
        foreach (self::$fixtures['onlinePayment'] as $case) {
            $this->assertEquals($case['expected'], PricingService::onlinePayment($case['amount'], $case['chargePercent']), json_encode($case));
        }
        $this->assertSame(0, PricingConfig::fromArray(self::$fixtures['config'])->onlinePaymentChargePercent);
    }

    #[Test]
    public function payment_status_matches_the_shared_fixtures(): void
    {
        foreach (self::$fixtures['paymentStatus'] as $case) {
            $this->assertSame($case['expected'], PricingService::paymentStatus($case['total'], $case['paid']), json_encode($case));
        }
    }
}
