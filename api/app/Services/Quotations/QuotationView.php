<?php

namespace App\Services\Quotations;

use App\Models\Quotation;
use App\Models\QuotationLine;
use App\Services\Invoices\InvoiceView;
use App\Support\AmountInWords;
use App\Support\Money;
use App\Support\Numerals;
use App\Support\Payments\PaymentOptions;

/**
 * What the quotation print view shows — the invoice page ($kind = 'quotation') with the same letterhead. Everything
 * comes from the quotation's frozen snapshot and lines. It shows the validity instead of payments, and never travellers:
 * a quotation carries no passport data at all.
 */
final class QuotationView
{
    /** 3: English only (2026-09-19). */
    public const TEMPLATE_VERSION = '3';

    public function __construct(private readonly InvoiceView $invoiceView) {}

    /** @return array<string, mixed> */
    public function data(Quotation $quotation, bool $header, string $locale = 'bn'): array
    {
        // Printed in English only, like the invoice (the client's decision, 2026-09-19); the parameter stays for callers.
        $locale = 'en';
        $quotation->loadMissing(['lines', 'customer', 'assignedStaff']);
        $n = fn ($value) => Numerals::number($value, $locale);
        // "BDT", not the ৳ sign: an English-only document (2026-09-19).
        $bdt = fn ($value) => Numerals::bdt($value, $locale, 'auto', 'code');
        $date = fn ($value) => $value ? Numerals::date($value->toDateString(), $locale) : '—';

        $status = match ($quotation->displayStatus()) {
            Quotation::DRAFT => ['key' => 'draft', 'label' => 'DRAFT'],
            'expired' => ['key' => 'expired', 'label' => 'EXPIRED'],
            Quotation::ACCEPTED => ['key' => 'accepted', 'label' => 'ACCEPTED'],
            Quotation::DECLINED => ['key' => 'declined', 'label' => 'DECLINED'],
            Quotation::WITHDRAWN => ['key' => 'withdrawn', 'label' => 'WITHDRAWN'],
            Quotation::CONVERTED => ['key' => 'booked', 'label' => 'BOOKED'],
            default => ['key' => 'valid', 'label' => 'VALID'],
        };

        $travel = '—';
        if ($quotation->travel_date !== null) {
            $end = $quotation->duration_days > 1 ? $quotation->travel_date->copy()->addDays($quotation->duration_days - 1) : null;
            $travel = $end ? $date($quotation->travel_date).' – '.$date($end) : $date($quotation->travel_date);
        }

        $packageDetail = array_filter([
            $quotation->duration_nights !== null && $quotation->duration_days !== null
                ? $quotation->duration_nights.' nights '.$quotation->duration_days.' days'
                : null,
            $quotation->includes_airfare === null ? null : ($quotation->includes_airfare ? 'Air ticket included' : 'Without air ticket'),
            $quotation->pax_count.' travellers',
            match ($quotation->room_type) {
                'single' => 'Single room',
                'triple' => 'Triple sharing',
                default => 'Twin sharing',
            },
        ]);

        $customer = $quotation->customer;
        $subtotal = $quotation->lines->sum(fn (QuotationLine $line) => (float) $line->amount);
        $validUntil = $date($quotation->valid_until);

        return [
            'kind' => 'quotation',
            'locale' => $locale,
            'header' => $header,
            ...$this->invoiceView->letterhead($locale),
            'number' => $quotation->number,
            'barcode' => null,
            'status' => $status,
            'billed' => [
                'name' => $customer->name,
                'lines' => array_values(array_filter([$customer->phone ? preg_replace('/^\+?88/', '', $customer->phone) : null, $customer->email, $customer->address])),
            ],
            // The quotation date and how long it holds are printed in the band across the top, not repeated here.
            'documentDate' => $date(($quotation->sent_at ?? $quotation->created_at)->copy()->setTimezone('Asia/Dhaka')),
            'dueDate' => $validUntil,
            'meta' => [
                ['Travel', $travel],
                ['Prepared by', $quotation->assignedStaff?->name ?? '—'],
            ],
            'package' => [
                'title' => $quotation->package_title_en ?: $quotation->package_title_bn,
                'code' => $quotation->package_code,
                'detail' => implode(' · ', $packageDetail),
            ],
            'items' => $quotation->lines->map(fn (QuotationLine $line) => [
                'title' => $line->title_en ?: $line->title_bn,
                'note' => null,
                'quantity' => $n($line->quantity),
                'rate' => (float) $line->unit_price > 0 ? $bdt($line->unit_price) : 'Included',
                'amount' => (float) $line->amount > 0 ? $bdt($line->amount) : '—',
            ])->all(),
            'travellers' => [],
            'totals' => array_values(array_filter([
                ['Subtotal', $bdt($subtotal)],
                (float) $quotation->discount_amount > 0 ? ['Discount', '− '.$bdt($quotation->discount_amount)] : null,
                ['Service charge & VAT ('.Numerals::percent((float) $quotation->vat_rate, $locale).')', $bdt($quotation->vat_amount)],
            ])),
            'total' => $bdt($quotation->total_amount),
            'amountInWords' => AmountInWords::taka(Money::toNumber($quotation->total_amount) ?? 0),
            'paid' => null,
            'due' => null,
            'hasDue' => false,
            'payments' => [],
            'paymentRows' => [],
            'note' => $quotation->notes,
            // Phase 8 §4.F: how the quoted total can be paid once it is booked.
            'howToPay' => PaymentOptions::lines(Money::toNumber($quotation->total_amount) ?? 0, $locale, currency: 'code'),
            'validUntil' => "This price is honoured until {$validUntil}. After that it may change; seats are confirmed when you book.",
            'terms' => [
                'This quotation does not book or hold any seat; seats are confirmed on booking and payment. Passports must be valid for at least 6 months from the travel date.',
                'Visa processing fees are not refunded if a visa is refused. This quotation is computer-generated and valid without a signature.',
            ],
            'voidReason' => null,
        ];
    }
}
