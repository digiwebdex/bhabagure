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

    /**
     * @param  list<array{code: string, price: int|float, unit: string}>  $addons  only the selected add-ons
     * @return array{pax: int, slab: array, perPerson: int, subtotal: int, singleSupplement: int, addons: list<array{code: string, amount: int}>, discount: int, chargePercent: int|float, serviceCharge: int, total: int, lines: list<array{kind: string, code: ?string, quantity: int, unitPrice: int, amount: int}>}
     */
    public static function quoteBooking(int|float $listPrice, int $pax, string $room, array $addons, PricingConfig $config, int|float $discount = 0, int|float|null $chargePercent = null): array
    {
        self::assertTravellers($pax);
        if ($pax > $config->maxTravellers) {
            throw new InvalidArgumentException("At most {$config->maxTravellers} travellers per booking");
        }

        $slab = self::slabFor($pax, $config->slabs);
        $perPerson = self::perPersonRate($listPrice, $pax, $config->slabs);
        $lines = [['kind' => 'package', 'code' => null, 'quantity' => $pax, 'unitPrice' => $perPerson, 'amount' => $perPerson * $pax]];

        if ($room === 'single') {
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
