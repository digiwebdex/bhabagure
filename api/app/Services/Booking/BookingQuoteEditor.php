<?php

namespace App\Services\Booking;

use App\Models\Addon;
use App\Models\Booking;
use App\Models\BookingLine;
use App\Models\CouponRedemption;
use App\Models\Invoice;
use App\Models\Staff;
use App\Services\AuditLogger;
use App\Services\Coupons\CouponCheck;
use App\Services\Coupons\CouponRefused;
use App\Services\Coupons\CouponService;
use App\Services\Ledger\LedgerService;
use App\Support\Money;
use App\Support\Pricing\PriceGrid;
use App\Support\Pricing\PricingConfig;
use App\Support\Pricing\PricingService;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The admin's draft-invoice controls (travellers, VAT rate, discount, coupon) before the invoice is issued. Prices are
 * recomputed with the shared pricing service from the booking's own snapshot — list price and add-on prices as they
 * were when booked — so a CMS price change never slips into an existing booking. The admin screen shows the same
 * numbers live with @bhabaghure/pricing; if they differ the save is refused.
 *
 * A booking's discount is its coupon's (docs/coupons.md), worked out from the terms its use copied, plus the staff's own
 * `discount` on top.
 */
final class BookingQuoteEditor
{
    public const VAT_RATES = [0, 2, 5, 7.5, 15];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly LedgerService $ledger,
        private readonly CouponService $coupons,
    ) {}

    /**
     * @param  int|float  $discount  the staff's own discount, on top of any coupon
     * @return array<string, mixed> the new quote
     *
     * @throws PriceChanged|QuoteLocked|CouponRefused
     */
    public function update(Booking $booking, int $pax, string $room, int|float $discount, int|float $vatRate, int|float $expectedTotal, Staff $staff): array
    {
        return DB::transaction(function () use ($booking, $pax, $room, $discount, $vatRate, $expectedTotal, $staff) {
            $booking = $this->lockEditable($booking);
            $quote = $this->quote($booking, $pax, $room, $discount, $vatRate, $booking->couponRedemption);
            if ((int) round($expectedTotal) !== $quote['total']) {
                throw new PriceChanged($quote);
            }

            $before = $booking->only(['pax_count', 'room_type', 'discount_amount', 'coupon_discount_amount', 'vat_rate', 'total_amount']);
            $this->replaceLines($booking, $quote);
            $this->save($booking, $quote, ['pax_count' => $pax, 'room_type' => $room, 'unit_price' => $quote['perPerson'], 'subtotal_amount' => $quote['subtotal'],
                'single_supplement_amount' => $quote['singleSupplement'], 'addons_amount' => array_sum(array_column($quote['addons'], 'amount'))]);
            $this->audit->record('booking.quote_updated', $staff, $booking, ['before' => $before, 'after' => $booking->only(array_keys($before))]);

            return $quote;
        });
    }

    /**
     * A customer's coupon, applied by staff (a code given on the phone, or forgotten on the website): checked exactly as
     * the website checks it, reserved, and the booking's total worked out again from its lines as they stand.
     *
     * @throws QuoteLocked|CouponRefused
     */
    public function applyCoupon(Booking $booking, string $code, Staff $staff): CouponRedemption
    {
        return DB::transaction(function () use ($booking, $code, $staff) {
            $booking = $this->lockEditable($booking);
            if ($booking->couponRedemption !== null) {
                throw new CouponRefused('already_applied');
            }
            $eligible = BookingCreator::eligibleAmount($booking->lines->map(fn (BookingLine $line) => ['amount' => $line->amount])->all());
            $offer = $this->coupons->evaluate($code, new CouponCheck(
                $booking->tour_package_id, $eligible, $booking->customer_id, null,
                $booking->travellers()->whereNotNull('passport_number_hash')->pluck('passport_number_hash')->unique()->values()->all(),
            ), lock: true);

            $before = (float) $booking->total_amount;
            $redemption = $this->coupons->reserve($offer['coupon'], $booking, [
                'eligible' => $eligible, 'discount' => $offer['discount'], 'originalTotal' => $before, 'finalTotal' => $before,
            ], CouponRedemption::SOURCE_OFFICE, $staff);
            $this->save($booking, $this->retotal($booking, $this->extraDiscount($booking), $redemption), []);

            return $redemption->refresh();
        });
    }

    /** Takes the coupon off the booking: the use is given back and the total worked out without it. @throws QuoteLocked */
    public function removeCoupon(Booking $booking, Staff $staff): void
    {
        DB::transaction(function () use ($booking, $staff) {
            $booking = $this->lockEditable($booking);
            $extra = $this->extraDiscount($booking);
            if ($this->coupons->release($booking, 'removed_by_staff', $staff) === null) {
                throw new LogicException("Booking {$booking->reference} has no coupon.");
            }
            $booking->unsetRelation('couponRedemption');
            $this->save($booking, $this->retotal($booking, $extra, null), []);
        });
    }

    /**
     * The quote for new travellers, room, VAT or staff discount, with the booking's coupon worked out again on the new
     * lines from the terms its use copied. A booking that falls below the coupon's minimum is refused, never quietly
     * stripped of it: staff remove the coupon first.
     *
     * @return array<string, mixed> with couponDiscount
     *
     * @throws CouponRefused
     */
    public function quote(Booking $booking, int $pax, string $room, int|float $discount, int|float $vatRate, ?CouponRedemption $coupon = null): array
    {
        $couponDiscount = 0;
        if ($coupon !== null) {
            $outcome = $coupon->discountOn(BookingCreator::eligibleAmount($this->priced($booking, $pax, $room, 0, $vatRate)['lines']));
            if (! $outcome['eligible']) {
                throw new CouponRefused('below_minimum', ['amount' => (float) $coupon->min_booking_amount]);
            }
            $couponDiscount = $outcome['discount'];
        }
        $quote = $this->priced($booking, $pax, $room, $couponDiscount + $discount, $vatRate);

        // The discount is capped at the lines; the coupon's share never exceeds what came off.
        return $quote + ['couponDiscount' => min($couponDiscount, $quote['discount'])];
    }

    /** @return array<string, mixed> */
    private function priced(Booking $booking, int $pax, string $room, int|float $discount, int|float $vatRate): array
    {
        if ($booking->is_custom) {
            return BookingCreator::customQuote($this->customItems($booking), $pax, $discount, $vatRate) + ['singleSupplement' => 0, 'addons' => []];
        }

        // A grid booking keeps its category and that category's prices as booked.
        return PricingService::quoteBooking((float) $booking->list_price, $pax, $room, $this->addonInputs($booking), PricingConfig::current(), $discount, $vatRate,
            grid: $booking->price_grid, hotelCategory: $booking->hotel_category);
    }

    /**
     * The booking's own lines as they stand, with a coupon applied or taken off: only the discount, VAT and total change.
     *
     * @return array<string, mixed>
     */
    private function retotal(Booking $booking, int|float $extra, ?CouponRedemption $coupon): array
    {
        $lines = $booking->lines->map(fn (BookingLine $line) => ['quantity' => (int) $line->quantity, 'unitPrice' => (float) $line->unit_price, 'amount' => (float) $line->amount])->all();
        $couponDiscount = $coupon === null ? 0 : $coupon->discountOn(BookingCreator::eligibleAmount($lines))['discount'];
        $totals = PricingService::invoiceTotals($lines, $couponDiscount + $extra, (float) $booking->vat_rate);

        return ['lines' => $lines, 'discount' => $totals['discount'], 'couponDiscount' => min($couponDiscount, $totals['discount']),
            'chargePercent' => $totals['chargePercent'], 'serviceCharge' => $totals['charge'], 'total' => $totals['total']];
    }

    /**
     * Writes the quote's amounts (and any other columns given), then the paid status, then the coupon's use, whose amounts
     * follow the booking while its quote can change.
     *
     * @param  array<string, mixed>  $quote
     * @param  array<string, mixed>  $columns
     */
    private function save(Booking $booking, array $quote, array $columns): void
    {
        if ((float) $booking->paid_amount > $quote['total']) {
            throw new LogicException('The new total is less than what has already been paid.');
        }
        $booking->fill($columns + [
            'discount_amount' => $quote['discount'], 'coupon_discount_amount' => $quote['couponDiscount'],
            'vat_rate' => $quote['chargePercent'], 'vat_amount' => $quote['serviceCharge'], 'total_amount' => $quote['total'],
        ])->save();

        // A partial payment's status changes with the total.
        $this->ledger->syncPaid($booking);

        if ($quote['couponDiscount'] > 0 || $booking->couponRedemption()->exists()) {
            $lines = $quote['lines'];
            $eligible = BookingCreator::eligibleAmount($lines);
            $withoutCoupon = PricingService::invoiceTotals(
                array_map(fn (array $line) => ['quantity' => $line['quantity'], 'unitPrice' => $line['unitPrice'] ?? $line['unit_price']], $lines),
                $quote['discount'] - $quote['couponDiscount'], $quote['chargePercent'],
            )['total'];
            $this->coupons->syncAmounts($booking, $eligible, $quote['couponDiscount'], $withoutCoupon);
        }
    }

    /** @param array<string, mixed> $quote */
    private function replaceLines(Booking $booking, array $quote): void
    {
        $addonLines = $booking->lines->where('kind', 'addon')->keyBy('code');
        $booking->lines()->delete();
        // A custom service keeps its own items, names and prices; only the travellers they are for change.
        foreach ($booking->is_custom ? $quote['lines'] : [] as $index => $line) {
            $booking->lines()->create($line + ['sort_order' => $index]);
        }
        foreach ($booking->is_custom ? [] : $quote['lines'] as $index => $line) {
            $snapshot = $line['code'] ? $addonLines[$line['code']] : null;
            $booking->lines()->create([
                'kind' => $line['kind'], 'code' => $line['code'],
                'title_en' => $snapshot?->title_en ?? ($line['kind'] === 'package' ? PriceGrid::lineTitle($booking->package_title_en, $booking->hotel_category, 'en') : 'Single room supplement'),
                'title_bn' => $snapshot?->title_bn ?? ($line['kind'] === 'package' ? ($booking->package_title_bn === null ? null : PriceGrid::lineTitle($booking->package_title_bn, $booking->hotel_category, 'bn')) : 'সিঙ্গেল রুম সাপ্লিমেন্ট'),
                'quantity' => $line['quantity'], 'unit_price' => $line['unitPrice'], 'amount' => $line['amount'], 'sort_order' => $index,
            ]);
        }
        $booking->unsetRelation('lines');
    }

    /** The booking, locked, while its quote may still change: open, and no issued invoice. @throws QuoteLocked */
    private function lockEditable(Booking $booking): Booking
    {
        $booking = Booking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();
        if ($booking->status->isFinal()) {
            throw new LogicException("Booking {$booking->reference} is {$booking->status->value}.");
        }
        if (Invoice::query()->where('booking_id', $booking->id)->where('status', Invoice::ISSUED)->exists()) {
            throw new QuoteLocked($booking);
        }

        return $booking;
    }

    /** The staff's own discount: the booking's whole discount less its coupon's share. */
    private function extraDiscount(Booking $booking): float
    {
        return max(0, (float) $booking->discount_amount - (float) $booking->coupon_discount_amount);
    }

    /**
     * A custom service's items as pricing inputs: each item's name and price per person, as booked.
     *
     * @return list<array{title: string, unitPrice: int|float}>
     */
    public function customItems(Booking $booking): array
    {
        return $booking->lines->where('kind', 'custom')->sortBy('sort_order')
            ->map(fn (BookingLine $line) => ['title' => $line->title_en, 'unitPrice' => Money::toNumber($line->unit_price)])
            ->values()->all();
    }

    /**
     * The booking's add-ons as pricing inputs: prices from the booking's snapshot. The admin screen receives exactly
     * these, so its live totals use the same inputs as the save.
     *
     * @return list<array{code: string, price: int|float, unit: string}>
     */
    public function addonInputs(Booking $booking): array
    {
        $lines = $booking->lines->where('kind', 'addon');
        $units = Addon::query()->whereIn('code', $lines->pluck('code'))->pluck('unit', 'code');

        return $lines->map(fn (BookingLine $line) => [
            'code' => $line->code,
            'price' => Money::toNumber($line->unit_price),
            // The add-on's unit as configured; a booking line alone can't tell per-person from per-booking when pax is 1.
            'unit' => $units[$line->code] ?? ($line->quantity === 1 && $booking->pax_count > 1 ? 'per_booking' : 'per_person'),
        ])->values()->all();
    }
}
