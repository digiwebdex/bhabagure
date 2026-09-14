<?php

namespace App\Services\Quotations;

use App\Models\Quotation;
use App\Models\QuotationLine;
use App\Services\Invoices\InvoiceView;
use App\Support\Numerals;

/**
 * What the quotation print view shows — the invoice page ($kind = 'quotation') with the same letterhead. Everything
 * comes from the quotation's frozen snapshot and lines. It shows the validity instead of payments, and never travellers:
 * a quotation carries no passport data at all.
 */
final class QuotationView
{
    public const TEMPLATE_VERSION = '1';

    public function __construct(private readonly InvoiceView $invoiceView) {}

    /** @return array<string, mixed> */
    public function data(Quotation $quotation, bool $header, string $locale = 'bn'): array
    {
        $quotation->loadMissing(['lines', 'customer', 'assignedStaff']);
        $n = fn ($value) => Numerals::number($value, $locale);
        $bdt = fn ($value) => Numerals::bdt($value, $locale);
        $date = fn ($value) => $value ? Numerals::date($value->toDateString(), $locale) : '—';

        $status = match ($quotation->displayStatus()) {
            Quotation::DRAFT => ['key' => 'draft', 'label' => 'খসড়া · DRAFT'],
            'expired' => ['key' => 'expired', 'label' => 'মেয়াদোত্তীর্ণ · EXPIRED'],
            Quotation::ACCEPTED => ['key' => 'accepted', 'label' => 'গৃহীত · ACCEPTED'],
            Quotation::DECLINED => ['key' => 'declined', 'label' => 'প্রত্যাখ্যাত · DECLINED'],
            Quotation::WITHDRAWN => ['key' => 'withdrawn', 'label' => 'প্রত্যাহার · WITHDRAWN'],
            Quotation::CONVERTED => ['key' => 'booked', 'label' => 'বুকিং হয়েছে · BOOKED'],
            default => ['key' => 'valid', 'label' => 'বৈধ · VALID'],
        };

        $travel = '—';
        if ($quotation->travel_date !== null) {
            $end = $quotation->duration_days > 1 ? $quotation->travel_date->copy()->addDays($quotation->duration_days - 1) : null;
            $travel = $end ? $date($quotation->travel_date).' – '.$date($end) : $date($quotation->travel_date);
        }

        $packageDetail = array_filter([
            $quotation->duration_nights !== null && $quotation->duration_days !== null
                ? ($locale === 'bn'
                    ? $n($quotation->duration_nights).' রাত '.$n($quotation->duration_days).' দিন'
                    : $quotation->duration_nights.' nights '.$quotation->duration_days.' days')
                : null,
            $quotation->includes_airfare === null ? null
                : ($quotation->includes_airfare ? ($locale === 'bn' ? 'এয়ার টিকেট অন্তর্ভুক্ত' : 'Air ticket included') : ($locale === 'bn' ? 'এয়ার টিকেট ছাড়া' : 'Without air ticket')),
            $locale === 'bn' ? $n($quotation->pax_count).' জন যাত্রী' : $quotation->pax_count.' travellers',
            match ($quotation->room_type) {
                'single' => $locale === 'bn' ? 'সিঙ্গেল রুম' : 'Single room',
                'triple' => $locale === 'bn' ? 'ট্রিপল শেয়ারিং' : 'Triple sharing',
                default => $locale === 'bn' ? 'টুইন শেয়ারিং' : 'Twin sharing',
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
            'meta' => [
                ['কোটেশন তারিখ · Date', $date(($quotation->sent_at ?? $quotation->created_at)->copy()->setTimezone('Asia/Dhaka'))],
                ['মেয়াদ · Valid until', $validUntil],
                ['যাত্রার তারিখ · Travel', $travel],
                ['প্রস্তুতকারী · Prepared by', $quotation->assignedStaff?->name ?? '—'],
            ],
            'package' => [
                'title' => $locale === 'bn' ? ($quotation->package_title_bn ?: $quotation->package_title_en) : $quotation->package_title_en,
                'code' => $quotation->package_code,
                'detail' => implode(' · ', $packageDetail),
            ],
            'items' => $quotation->lines->map(fn (QuotationLine $line) => [
                'title' => $locale === 'bn' ? ($line->title_bn ?: $line->title_en) : $line->title_en,
                'note' => null,
                'quantity' => $n($line->quantity),
                'rate' => (float) $line->unit_price > 0 ? $bdt($line->unit_price) : ($locale === 'bn' ? 'অন্তর্ভুক্ত' : 'Included'),
                'amount' => (float) $line->amount > 0 ? $bdt($line->amount) : '—',
            ])->all(),
            'travellers' => [],
            'totals' => array_values(array_filter([
                ['সাব-টোটাল · Subtotal', $bdt($subtotal)],
                (float) $quotation->discount_amount > 0 ? ['ডিসকাউন্ট · Discount', '− '.$bdt($quotation->discount_amount)] : null,
                ['সার্ভিস চার্জ ও ভ্যাট · VAT ('.Numerals::percent((float) $quotation->vat_rate, $locale).')', $bdt($quotation->vat_amount)],
            ])),
            'total' => $bdt($quotation->total_amount),
            'paid' => null,
            'due' => null,
            'hasDue' => false,
            'payments' => [],
            'validUntil' => $locale === 'bn'
                ? "{$validUntil} পর্যন্ত এই মূল্য প্রযোজ্য। এরপর মূল্য পরিবর্তন হতে পারে; সিট প্রাপ্যতা বুকিংয়ের সময় নিশ্চিত করা হবে।"
                : "This price is honoured until {$validUntil}. After that it may change; seats are confirmed when you book.",
            'terms' => [
                'এই কোটেশন কোনো বুকিং বা সিট নিশ্চিত করে না; বুকিং ও পেমেন্টের পর সিট নিশ্চিত হয়। পাসপোর্টের মেয়াদ যাত্রার তারিখ থেকে কমপক্ষে ৬ মাস থাকতে হবে।',
                'ভিসা প্রত্যাখ্যাত হলে প্রসেসিং ফি অফেরতযোগ্য। এই কোটেশন কম্পিউটার-জেনারেটেড; স্বাক্ষর ছাড়াও বৈধ।',
            ],
            'voidReason' => null,
        ];
    }
}
