<?php

namespace App\Support\Pricing;

use InvalidArgumentException;

/**
 * PHP twin of @bhabaghure/pricing. The website quotes with the TypeScript version; the API recomputes with this
 * one before it accepts a booking or issues an invoice. Both are held to packages/pricing/fixtures.json
 * (tests/Unit/PricingServiceTest), so a price can't differ between the screen and the charge.
 *
 * Amounts are whole taka; rounding is half-up per step (JavaScript Math.round and PHP round agree for positives).
 */
final class PricingService
{
    /**
     * @param  list<array{minPax: int, discountPercent: int|float}>  $slabs
     * @return array{minPax: int, discountPercent: int|float}
     */
    public static function slabFor(int $pax, array $slabs): array
    {
        self::assertTravellers($pax);
        usort($slabs, fn (array $a, array $b) => $a['minPax'] <=> $b['minPax']);
        $match = null;
        foreach ($slabs as $slab) {
            if ($slab['minPax'] <= $pax) {
                $match = $slab;
            }
        }

        return $match ?? throw new InvalidArgumentException("No slab covers {$pax} travellers");
    }

    /** @param list<array{minPax: int, discountPercent: int|float}> $slabs */
    public static function perPersonRate(int|float $listPrice, int $pax, array $slabs): int
    {
        self::assertAmount($listPrice);

        return self::round($listPrice * (100 - self::slabFor($pax, $slabs)['discountPercent']) / 100);
    }

    /** Group sizes a hotel-category price grid is entered for (docs/phase-8-visa-quotes-pricing-downloads.md §4.D). */
    public const GRID_TIERS = [1, 2, 4, 6, 10];

    public const HOTEL_CATEGORIES = ['3', '4', '5'];

    /**
     * The categories a grid offers, in order: those with a 1-traveller price.
     *
     * @param  array<string, array<string, int|float>>|null  $grid
     * @return list<string>
     */
    public static function gridCategories(?array $grid): array
    {
        return array_values(array_filter(self::HOTEL_CATEGORIES, fn (string $category) => is_numeric($grid[$category]['1'] ?? null)));
    }

    /**
     * Per-person price for `pax` in a category: the highest tier at or below it that has a price.
     *
     * @param  array<string, array<string, int|float>>  $grid
     * @return array{tier: int, perPerson: int}
     */
    public static function gridRate(array $grid, string $category, int $pax): array
    {
        self::assertTravellers($pax);
        $row = $grid[$category] ?? null;
        if (! is_array($row) || ! is_numeric($row['1'] ?? null)) {
            throw new InvalidArgumentException("The grid has no {$category}-star prices");
        }
        $match = null;
        foreach (self::GRID_TIERS as $tier) {
            $price = $row[(string) $tier] ?? null;
            if ($tier <= $pax && is_numeric($price)) {
                self::assertAmount($price + 0);
                $match = ['tier' => $tier, 'perPerson' => self::round($price + 0)];
            }
        }

        return $match;
    }

    /**
     * @param  list<array{code: string, price: int|float, unit: string}>  $addons  only the selected add-ons
     * @param  array<string, array<string, int|float>>|null  $grid  the package's hotel-category grid; when it offers a category it replaces the list price and the slabs
     * @return array{pax: int, slab: array, hotelCategory: ?string, perPerson: int, subtotal: int, singleSupplement: int, addons: list<array{code: string, amount: int}>, discount: int, chargePercent: int|float, serviceCharge: int, total: int, lines: list<array{kind: string, code: ?string, quantity: int, unitPrice: int, amount: int}>}
     */
    public static function quoteBooking(int|float $listPrice, int $pax, string $room, array $addons, PricingConfig $config, int|float $discount = 0, int|float|null $chargePercent = null, ?array $grid = null, ?string $hotelCategory = null): array
    {
        self::assertTravellers($pax);
        if ($pax > $config->maxTravellers) {
            throw new InvalidArgumentException("At most {$config->maxTravellers} travellers per booking");
        }

        $offered = self::gridCategories($grid);
        $category = null;
        if ($offered !== []) {
            if ($hotelCategory === null || ! in_array($hotelCategory, $offered, true)) {
                throw new InvalidArgumentException('Choose one of the hotel categories '.implode(', ', $offered));
            }
            $rate = self::gridRate($grid, $hotelCategory, $pax);
            $slab = ['minPax' => $rate['tier'], 'discountPercent' => 0];
            $perPerson = $rate['perPerson'];
            $category = $hotelCategory;
        } else {
            $slab = self::slabFor($pax, $config->slabs);
            $perPerson = self::perPersonRate($listPrice, $pax, $config->slabs);
        }
        $lines = [['kind' => 'package', 'code' => null, 'quantity' => $pax, 'unitPrice' => $perPerson, 'amount' => $perPerson * $pax]];

        // A grid's 1-traveller price already includes a single room (decided 2026-09-16); larger groups pay the supplement.
        if ($room === 'single' && ! ($category !== null && $pax === 1)) {
            $supplement = self::round($perPerson * $config->singleRoomSupplementPercent / 100);
            $lines[] = ['kind' => 'single_supplement', 'code' => null, 'quantity' => $pax, 'unitPrice' => $supplement, 'amount' => $supplement * $pax];
        }

        foreach ($addons as $addon) {
            self::assertAmount($addon['price']);
            $quantity = $addon['unit'] === 'per_booking' ? 1 : $pax;
            $price = (int) round($addon['price']);
            $lines[] = ['kind' => 'addon', 'code' => $addon['code'], 'quantity' => $quantity, 'unitPrice' => $price, 'amount' => $price * $quantity];
        }

        $totals = self::invoiceTotals($lines, $discount, $chargePercent ?? $config->serviceChargePercent);
        $supplementLine = array_values(array_filter($lines, fn (array $line) => $line['kind'] === 'single_supplement'))[0] ?? null;

        return [
            'pax' => $pax,
            'slab' => $slab,
            'hotelCategory' => $category,
            'perPerson' => $perPerson,
            'subtotal' => $lines[0]['amount'],
            'singleSupplement' => $supplementLine['amount'] ?? 0,
            'addons' => array_values(array_map(
                fn (array $line) => ['code' => $line['code'], 'amount' => $line['amount']],
                array_filter($lines, fn (array $line) => $line['kind'] === 'addon'),
            )),
            'discount' => $totals['discount'],
            'chargePercent' => $totals['chargePercent'],
            'serviceCharge' => $totals['charge'],
            'total' => $totals['total'],
            'lines' => $lines,
        ];
    }

    /**
     * Discount first, then VAT / service charge on what is left (decided 2026-09-13).
     *
     * @param  list<array{quantity: int, unitPrice: int|float}>  $lines
     * @return array{subtotal: int, discount: int, taxable: int, chargePercent: int|float, charge: int, total: int}
     */
    public static function invoiceTotals(array $lines, int|float $discount, int|float $chargePercent): array
    {
        self::assertAmount($discount);
        self::assertAmount($chargePercent);

        $subtotal = 0;
        foreach ($lines as $line) {
            if (! is_int($line['quantity']) || $line['quantity'] < 0) {
                throw new InvalidArgumentException('Quantities must be whole numbers');
            }
            self::assertAmount($line['unitPrice']);
            $subtotal += $line['quantity'] * (int) round($line['unitPrice']);
        }

        $applied = min(self::round($discount), $subtotal);
        $taxable = $subtotal - $applied;
        $charge = self::round($taxable * $chargePercent / 100);

        return ['subtotal' => $subtotal, 'discount' => $applied, 'taxable' => $taxable, 'chargePercent' => $chargePercent, 'charge' => $charge, 'total' => $taxable + $charge];
    }

    /**
     * The amount to pay online: the balance plus the configured online payment charge, shown as its own line before
     * the customer commits. The gateway is asked for exactly `total`.
     *
     * @return array{amount: int|float, chargePercent: int|float, charge: int, total: int|float}
     */
    public static function onlinePayment(int|float $amount, int|float $chargePercent): array
    {
        self::assertAmount($amount);
        self::assertAmount($chargePercent);
        $charge = self::round($amount * $chargePercent / 100);

        return ['amount' => $amount, 'chargePercent' => $chargePercent, 'charge' => $charge, 'total' => $amount + $charge];
    }

    /**
     * A coupon's discount on the booking amount — every line before any discount and before the service charge, which
     * then applies to what is left (docs/coupons.md §2.2). Percent: the amount × rate in whole taka, capped at the maximum;
     * fixed: the value. Never more than the amount, so a total can't go below zero. Below the minimum: not eligible, 0.
     *
     * @param  'percent'|'fixed'  $type
     * @return array{eligible: bool, discount: int}
     */
    public static function couponDiscount(string $type, int|float $value, int|float|null $maxDiscount, int|float|null $minAmount, int|float $amount): array
    {
        self::assertAmount($amount);
        self::assertAmount($value);
        if (! in_array($type, ['percent', 'fixed'], true) || ($type === 'percent' && $value > 100)) {
            throw new InvalidArgumentException("A coupon is a percentage up to 100 or a fixed amount, got {$type} {$value}");
        }
        if ($minAmount !== null && $amount < $minAmount) {
            return ['eligible' => false, 'discount' => 0];
        }

        $discount = $type === 'percent' ? self::round($amount * $value / 100) : self::round($value);
        if ($type === 'percent' && $maxDiscount !== null) {
            $discount = min($discount, self::round($maxDiscount));
        }

        return ['eligible' => true, 'discount' => (int) min($discount, self::round($amount))];
    }

    /** The PAID / PARTIAL / UNPAID pill — derived from amounts, never chosen. */
    public static function paymentStatus(int|float|string $total, int|float|string $paid): string
    {
        if ((float) $paid >= (float) $total) {
            return 'paid';
        }

        return (float) $paid <= 0 ? 'unpaid' : 'partial';
    }

    private static function round(int|float $value): int
    {
        return (int) round($value, 0, PHP_ROUND_HALF_UP);
    }

    private static function assertTravellers(int $pax): void
    {
        if ($pax < 1) {
            throw new InvalidArgumentException("Travellers must be a whole number from 1, got {$pax}");
        }
    }

    private static function assertAmount(int|float $amount): void
    {
        if (! is_finite((float) $amount) || $amount < 0) {
            throw new InvalidArgumentException("Amounts must be finite and non-negative, got {$amount}");
        }
    }
}
