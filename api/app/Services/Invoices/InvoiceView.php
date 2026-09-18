<?php

namespace App\Services\Invoices;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\SiteSetting;
use App\Models\Transaction;
use App\Services\Ledger\LedgerService;
use App\Support\AmountInWords;
use App\Support\Barcode\Code128;
use App\Support\Money;
use App\Support\Numerals;
use App\Support\Payments\PaymentOptions;

/**
 * Everything the invoice print view shows, taken from the invoice's frozen snapshot columns and lines — never from the
 * package or booking as they are today. Only the payment block (paid, balance, status pill, payments received) is
 * current, because it comes from the append-only ledger. The letterhead is the company's current details.
 */
final class InvoiceView
{
    /** 5: English only (2026-09-19) — a stored PDF from before is made again. */
    public const TEMPLATE_VERSION = '5';

    /**
     * @param  string  $size  a4 · a5 · slip · delivery (docs/phase-9-accounts.md §5) — the same figures, printed on
     *                        different paper. The delivery receipt leaves the prices off.
     * @return array<string, mixed>
     */
    public function data(Invoice $invoice, bool $header, string $locale = 'bn', bool $maskPassports = false, string $size = 'a4'): array
    {
        // A printed invoice is in English only — words, digits and dates — whatever the customer's language (the
        // client's decision, 2026-09-19). The parameter stays for the callers; it no longer changes the page.
        $locale = 'en';
        $invoice->loadMissing('items');
        $n = fn ($value) => Numerals::number($value, $locale);
        // "BDT", not the ৳ sign: an English-only document (2026-09-19).
        $bdt = fn ($value) => Numerals::bdt($value, $locale, 'auto', 'code');
        $date = fn ($value) => $value ? Numerals::date($value->toDateString(), $locale) : '—';

        $status = match (true) {
            $invoice->status === Invoice::VOID => ['key' => 'void', 'label' => 'VOID'],
            $invoice->payment_status === 'paid' => ['key' => 'paid', 'label' => 'PAID'],
            $invoice->payment_status === 'partial' => ['key' => 'partial', 'label' => 'PARTIAL'],
            default => ['key' => 'unpaid', 'label' => 'UNPAID'],
        };

        $travel = $invoice->travel_start
            ? ($invoice->travel_end && ! $invoice->travel_end->equalTo($invoice->travel_start)
                ? $date($invoice->travel_start).' – '.$date($invoice->travel_end)
                : $date($invoice->travel_start))
            : '—';

        $packageDetail = array_filter([
            $invoice->package_duration_nights !== null && $invoice->package_duration_days !== null
                ? $invoice->package_duration_nights.' nights '.$invoice->package_duration_days.' days'
                : null,
            $invoice->includes_airfare === null ? null : ($invoice->includes_airfare ? 'Air ticket included' : 'Without air ticket'),
            $invoice->pax_count ? $invoice->pax_count.' travellers' : null,
        ]);

        $deal = $invoice->kind === Invoice::KIND_DEAL;
        // A booking's payments are the booking's (they carry over a void and reissue); a deal's are the invoice's own.
        $rows = Transaction::query()->when($invoice->booking_id !== null, fn ($q) => $q->where('booking_id', $invoice->booking_id), fn ($q) => $q->where('invoice_id', $invoice->id ?? 0))
            ->whereIn('category', [LedgerService::CATEGORY_PAYMENT, LedgerService::CATEGORY_ONLINE_CHARGE])->orderBy('occurred_at')->orderBy('id')->get();
        // An online payment's charge line is listed with the payment it belongs to (same gateway reference).
        $charges = $rows->where('category', LedgerService::CATEGORY_ONLINE_CHARGE)->keyBy(fn (Transaction $t) => (string) preg_replace('/:charge$/', '', (string) $t->external_ref));
        $payments = $rows->where('category', LedgerService::CATEGORY_PAYMENT)
            ->map(fn (Transaction $t) => ($t->direction->value === 'out' ? '− ' : '').$bdt($t->amount).' · '.$this->methodLabel($t->method)
                // A gateway or wallet reference; a deal payment's reference is only its label.
                .(($t->external_ref ?? $t->reference_label) ? ' · '.($t->external_ref ?? $t->reference_label) : '').' · '.$date($t->occurred_at)
                .(isset($charges[(string) $t->external_ref]) && $t->external_ref !== null
                    ? ' · + '.$bdt($charges[$t->external_ref]->amount).' '.($t->method === 'bkash' ? 'bKash charge' : 'online payment charge')
                    : ''))
            ->values()->all();
        // The same payments as rows under the total — "Payment on 15 September 2026 (Cash)" and the amount — which is
        // how the client's own invoices read.
        $paymentRows = $rows->where('category', LedgerService::CATEGORY_PAYMENT)
            ->map(fn (Transaction $t) => [
                'Payment on '.$date($t->occurred_at)
                    // The reference is what a customer checks the payment against, so it is printed with it.
                    .' ('.$this->methodLabel($t->method).(($t->external_ref ?? $t->reference_label) ? ' · '.($t->external_ref ?? $t->reference_label) : '').')',
                ($t->direction->value === 'out' ? '− ' : '').$bdt($t->amount),
            ])->values()->all();

        $subtotal = (float) $invoice->subtotal_amount;

        return [
            'kind' => 'invoice',
            'validUntil' => null,
            'locale' => $locale,
            'header' => $header,
            'size' => $size,
            'delivery' => $size === 'delivery',
            'slipDate' => $invoice->issued_on ? Numerals::date($invoice->issued_on->toDateString(), 'en') : '—',
            ...$this->letterhead($locale),
            'number' => $invoice->invoice_number ?? 'DRAFT',
            'barcode' => $invoice->invoice_number ? Code128::svg($invoice->invoice_number) : null,
            'status' => $status,
            'billed' => [
                'name' => $invoice->billed_name,
                'lines' => array_values(array_filter([$invoice->billed_phone ? $this->phone($invoice->billed_phone) : null, $invoice->billed_email, $invoice->billed_address])),
            ],
            // The two dates are printed in the band across the top, so they are not repeated here.
            'documentDate' => $date($invoice->issued_on),
            'dueDate' => $invoice->due_on ? $date($invoice->due_on) : $date($invoice->issued_on),
            'meta' => $deal ? [
                ['Issued by', $invoice->sales_agent_name ?? '—'],
            ] : [
                ['Booking', $invoice->booking_reference ?? '—'],
                ['Travel', $travel],
                ['Agent', $invoice->sales_agent_name ?? '—'],
            ],
            'packageLabel' => $deal ? 'Service' : 'Package',
            // What staff wrote to the customer prints under Notes / Terms, where an invoice is read for it.
            'note' => $invoice->note,
            'package' => $deal ? ['title' => $invoice->title, 'code' => null, 'detail' => null] : [
                'title' => $invoice->package_title_en ?: $invoice->package_title_bn,
                'code' => $invoice->package_code,
                'detail' => implode(' · ', $packageDetail),
            ],
            // A line's own discount and VAT are said under it: otherwise the amount would not read as quantity × rate.
            'items' => $invoice->items->map(fn (InvoiceItem $item) => [
                'title' => $item->title_en ?: $item->title_bn,
                'note' => implode(' · ', array_filter([
                    $item->note,
                    (float) $item->discount_amount > 0 ? 'Discount − '.$bdt($item->discount_amount) : null,
                    (float) $item->vat_amount > 0 ? 'VAT '.Numerals::percent((float) $item->vat_rate, $locale).' '.$bdt($item->vat_amount) : null,
                ])) ?: null,
                'quantity' => $n((float) $item->quantity),
                'rate' => (float) $item->unit_price > 0 ? $bdt($item->unit_price) : 'Included',
                'amount' => (float) $item->line_total > 0 ? $bdt($item->line_total) : '—',
            ])->all(),
            'travellers' => array_map(fn (array $t) => [
                'name' => $t['name'],
                'passport' => $t['passportNumber'] ? ($maskPassports ? $this->mask($t['passportNumber']) : $t['passportNumber']) : null,
                'expiry' => $t['passportExpiry'] ? Numerals::localizeDigits(date('d/m/Y', strtotime($t['passportExpiry'])), $locale) : null,
            ], $invoice->travellers ?? []),
            'totals' => array_values(array_filter([
                ['Subtotal', $bdt($subtotal)],
                (float) $invoice->discount_amount > 0 ? ['Discount'.($invoice->discount_label ? " ({$invoice->discount_label})" : ''), '− '.$bdt($invoice->discount_amount)] : null,
                // One rate on the whole invoice is named; VAT that came from the lines is only totalled here.
                (float) $invoice->vat_rate > 0 || (float) $invoice->vat_amount > 0
                    ? ['Service charge & VAT'.((float) $invoice->vat_rate > 0 ? ' ('.Numerals::percent((float) $invoice->vat_rate, $locale).')' : ''), $bdt($invoice->vat_amount)]
                    : null,
            ])),
            'total' => $bdt($invoice->total_amount),
            // The total written out as well as in figures: an invoice is a demand for money, so a changed digit shows.
            'amountInWords' => AmountInWords::taka(Money::toNumber($invoice->total_amount) ?? 0),
            'paid' => $bdt($invoice->paid_amount),
            'due' => $bdt($invoice->balance_due),
            'hasDue' => (float) $invoice->balance_due > 0,
            'payments' => $payments,
            'paymentRows' => $paymentRows,
            // Phase 8 §4.F: how to pay what is still due, while the invoice is open.
            'howToPay' => $invoice->status === Invoice::ISSUED ? PaymentOptions::lines(Money::toNumber($invoice->balance_due) ?? 0, $locale, currency: 'code') : [],
            // Whatever staff wrote at the foot of this one invoice comes first, then the standing terms.
            'terms' => $deal ? array_values(array_filter([
                $invoice->footer,
                'The balance is payable as agreed. This invoice is computer-generated and valid without a signature.',
            ])) : [
                'Cancelled 21 days or more before travel: the amount paid is refunded, less the non-refundable part. Passports must be valid for at least 6 months from the travel date.',
                'Visa processing fees are not refunded if a visa is refused. This invoice is computer-generated and valid without a signature.',
            ],
            'voidReason' => $invoice->status === Invoice::VOID ? $invoice->void_reason : null,
        ];
    }

    /**
     * The letterhead every printed document shares (invoices, quotations): company details, logo and pad height.
     *
     * @return array{company: array<string, mixed>, logo: ?string, headerHeightMm: float}
     */
    public function letterhead(string $locale): array
    {
        $settings = SiteSetting::get('invoice', []);

        return [
            'company' => $this->company($locale),
            'logo' => $this->logoDataUri(),
            'headerHeightMm' => (float) ($settings['headerHeightMm'] ?? config('bhabaghure.invoices.header_height_mm')),
        ];
    }

    /** @return array<string, mixed> */
    private function company(string $locale): array
    {
        $company = SiteSetting::get('company', []);
        $contact = SiteSetting::get('contact', []);

        return [
            'name' => $company['name']['en'] ?? 'Bhabaghure Holidays Aviation',
            'address' => SiteSetting::get('address', ''),
            'email' => $contact['email'] ?? null,
            'phones' => implode(', ', array_filter([isset($contact['phone']) ? $this->phone($contact['phone']) : null, isset($contact['phoneAlt']) ? $this->phone($contact['phoneAlt']) : null])),
            // Printed beside the main lines so a customer can check the number automated WhatsApp messages come from.
            'notificationsWhatsapp' => ! empty($contact['notificationsWhatsapp']) ? $this->phone($contact['notificationsWhatsapp']) : null,
            'civilAviationNo' => SiteSetting::get('civilAviationNo'),
            'website' => isset($contact['website']) ? preg_replace('#^https?://#', '', rtrim($contact['website'], '/')) : null,
            'facebook' => isset($contact['facebook']) ? preg_replace('#^https?://(www\.)?#', '', rtrim($contact['facebook'], '/')) : null,
        ];
    }

    private function logoDataUri(): ?string
    {
        $path = config('bhabaghure.invoices.logo_path');

        return is_string($path) && is_file($path) ? 'data:image/png;base64,'.base64_encode((string) file_get_contents($path)) : null;
    }

    /** 8801743939300 / +8801743939300 → 01743939300 (identifiers keep Latin digits). */
    private function phone(string $phone): string
    {
        return preg_replace('/^\+?88/', '', $phone) ?? $phone;
    }

    private function mask(string $passport): string
    {
        return strlen($passport) <= 4 ? $passport : substr($passport, 0, 2).str_repeat('•', strlen($passport) - 4).substr($passport, -2);
    }

    private function methodLabel(string $method): string
    {
        return [
            'sslcommerz' => 'SSLCommerz', 'cash' => 'Cash', 'bank_transfer' => 'Bank transfer', 'cheque' => 'Cheque',
            'card_terminal' => 'Card', 'bkash' => 'bKash', 'nagad' => 'Nagad', 'rocket' => 'Rocket',
        ][$method] ?? $method;
    }
}
