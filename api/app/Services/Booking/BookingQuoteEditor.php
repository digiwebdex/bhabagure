<?php

namespace App\Services\Booking;

use App\Models\Addon;
use App\Models\Booking;
use App\Models\BookingLine;
use App\Models\Invoice;
use App\Models\Staff;
use App\Services\AuditLogger;
use App\Services\Ledger\LedgerService;
use App\Support\Money;
use App\Support\Pricing\PricingConfig;
use App\Support\Pricing\PricingService;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The admin's draft-invoice controls (travellers, VAT rate, discount) before the invoice is issued. Prices are
 * recomputed with the shared pricing service from the booking's own snapshot — list price and add-on prices as they
 * were when booked — so a CMS price change never slips into an existing booking. The admin screen shows the same
 * numbers live with @bhabaghure/pricing; if they differ the save is refused.
 */
final class BookingQuoteEditor
{
    public const VAT_RATES = [0, 2, 5, 7.5, 15];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly LedgerService $ledger,
    ) {}

    /** @return array<string, mixed> the new quote */
    public function update(Booking $booking, int $pax, string $room, int|float $discount, int|float $vatRate, int|float $expectedTotal, Staff $staff): array
    {
        return DB::transaction(function () use ($booking, $pax, $room, $discount, $vatRate, $expectedTotal, $staff) {
            $booking = Booking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();
            if ($booking->status->isFinal()) {
                throw new LogicException("Booking {$booking->reference} is {$booking->status->value}.");
            }
            if (Invoice::query()->where('booking_id', $booking->id)->where('status', Invoice::ISSUED)->exists()) {
                throw new QuoteLocked($booking);
            }

            $quote = $this->quote($booking, $pax, $room, $discount, $vatRate);
            if ((int) round($expectedTotal) !== $quote['total']) {
                throw new PriceChanged($quote);
            }
            if ((float) $booking->paid_amount > $quote['total']) {
                throw new LogicException('The new total is less than what has already been paid.');
            }

            $addonLines = $booking->lines->where('kind', 'addon')->keyBy('code');
            $before = $booking->only(['pax_count', 'room_type', 'discount_amount', 'vat_rate', 'total_amount']);

            $booking->lines()->delete();
            foreach ($quote['lines'] as $index => $line) {
                $snapshot = $line['code'] ? $addonLines[$line['code']] : null;
                $booking->lines()->create([
                    'kind' => $line['kind'], 'code' => $line['code'],
                    'title_en' => $snapshot?->title_en ?? ($line['kind'] === 'package' ? $booking->package_title_en : 'Single room supplement'),
                    'title_bn' => $snapshot?->title_bn ?? ($line['kind'] === 'package' ? $booking->package_title_bn : 'সিঙ্গেল রুম সাপ্লিমেন্ট'),
                    'quantity' => $line['quantity'], 'unit_price' => $line['unitPrice'], 'amount' => $line['amount'], 'sort_order' => $index,
                ]);
            }

            $booking->fill([
                'pax_count' => $pax, 'room_type' => $room, 'unit_price' => $quote['perPerson'], 'subtotal_amount' => $quote['subtotal'],
                'single_supplement_amount' => $quote['singleSupplement'], 'addons_amount' => array_sum(array_column($quote['addons'], 'amount')),
                'discount_amount' => $quote['discount'], 'vat_rate' => $quote['chargePercent'], 'vat_amount' => $quote['serviceCharge'],
                'total_amount' => $quote['total'],
            ])->save();

            // A partial payment's status changes with the total.
            $this->ledger->syncPaid($booking);
            $this->audit->record('booking.quote_updated', $staff, $booking, ['before' => $before, 'after' => $booking->only(array_keys($before))]);

            return $quote;
        });
    }

    /** @return array<string, mixed> */
    public function quote(Booking $booking, int $pax, string $room, int|float $discount, int|float $vatRate): array
    {
        return PricingService::quoteBooking((float) $booking->list_price, $pax, $room, $this->addonInputs($booking), PricingConfig::current(), $discount, $vatRate);
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
