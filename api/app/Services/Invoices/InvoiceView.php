<?php

namespace App\Services\Invoices;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\SiteSetting;
use App\Models\Transaction;
use App\Services\Ledger\LedgerService;
use App\Support\Barcode\Code128;
use App\Support\Numerals;

/**
 * Everything the invoice print view shows, taken from the invoice's frozen snapshot columns and lines — never from the
 * package or booking as they are today. Only the payment block (paid, balance, status pill, payments received) is
 * current, because it comes from the append-only ledger. The letterhead is the company's current details.
 */
final class InvoiceView
{
    public const TEMPLATE_VERSION = '1';

    /** @return array<string, mixed> */
    public function data(Invoice $invoice, bool $header, string $locale = 'bn', bool $maskPassports = false): array
    {
        $invoice->loadMissing('items');
        $n = fn ($value) => Numerals::number($value, $locale);
        $bdt = fn ($value) => Numerals::bdt($value, $locale);
        $date = fn ($value) => $value ? Numerals::date($value->toDateString(), $locale) : '—';

        $status = match (true) {
            $invoice->status === Invoice::VOID => ['key' => 'void', 'label' => 'বাতিল · VOID'],
            $invoice->payment_status === 'paid' => ['key' => 'paid', 'label' => 'পরিশোধিত · PAID'],
            $invoice->payment_status === 'partial' => ['key' => 'partial', 'label' => 'আংশিক · PARTIAL'],
            default => ['key' => 'unpaid', 'label' => 'বকেয়া · UNPAID'],
        };

        $travel = $invoice->travel_start
            ? ($invoice->travel_end && ! $invoice->travel_end->equalTo($invoice->travel_start)
                ? $date($invoice->travel_start).' – '.$date($invoice->travel_end)
                : $date($invoice->travel_start))
            : '—';

        $packageDetail = array_filter([
            $invoice->package_duration_nights !== null && $invoice->package_duration_days !== null
                ? ($locale === 'bn'
                    ? $n($invoice->package_duration_nights).' রাত '.$n($invoice->package_duration_days).' দিন'
                    : $invoice->package_duration_nights.' nights '.$invoice->package_duration_days.' days')
                : null,
            $invoice->includes_airfare === null ? null
                : ($invoice->includes_airfare ? ($locale === 'bn' ? 'এয়ার টিকেট অন্তর্ভুক্ত' : 'Air ticket included') : ($locale === 'bn' ? 'এয়ার টিকেট ছাড়া' : 'Without air ticket')),
            $invoice->pax_count ? ($locale === 'bn' ? $n($invoice->pax_count).' জন যাত্রী' : $invoice->pax_count.' travellers') : null,
        ]);

        $rows = Transaction::query()->where('booking_id', $invoice->booking_id)
            ->whereIn('category', [LedgerService::CATEGORY_PAYMENT, LedgerService::CATEGORY_ONLINE_CHARGE])->orderBy('occurred_at')->orderBy('id')->get();
        // An online payment's charge line is listed with the payment it belongs to (same gateway reference).
        $charges = $rows->where('category', LedgerService::CATEGORY_ONLINE_CHARGE)->keyBy(fn (Transaction $t) => (string) preg_replace('/:charge$/', '', (string) $t->external_ref));
        $payments = $rows->where('category', LedgerService::CATEGORY_PAYMENT)
            ->map(fn (Transaction $t) => ($t->direction->value === 'out' ? '− ' : '').$bdt($t->amount).' · '.$this->methodLabel($t->method)
                .($t->external_ref ? ' · '.$t->external_ref : '').' · '.$date($t->occurred_at)
                .(isset($charges[(string) $t->external_ref]) && $t->external_ref !== null
                    ? ' · + '.$bdt($charges[$t->external_ref]->amount).' '.($locale === 'bn' ? 'অনলাইন পেমেন্ট চার্জ' : 'online payment charge')
                    : ''))
            ->values()->all();

        $subtotal = (float) $invoice->subtotal_amount;
        $settings = SiteSetting::get('invoice', []);

        return [
            'locale' => $locale,
            'header' => $header,
            'headerHeightMm' => (float) ($settings['headerHeightMm'] ?? config('bhabaghure.invoices.header_height_mm')),
            'company' => $this->company($locale),
            'logo' => $this->logoDataUri(),
            'number' => $invoice->invoice_number ?? 'DRAFT',
            'barcode' => $invoice->invoice_number ? Code128::svg($invoice->invoice_number) : null,
            'status' => $status,
            'billed' => [
                'name' => $invoice->billed_name,
                'lines' => array_values(array_filter([$invoice->billed_phone ? $this->phone($invoice->billed_phone) : null, $invoice->billed_email, $invoice->billed_address])),
            ],
            'meta' => [
                ['ইনভয়েস তারিখ · Date', $date($invoice->issued_on)],
                ['বুকিং রেফ · Booking', $invoice->booking_reference ?? '—'],
                ['যাত্রার তারিখ · Travel', $travel],
                ['সেলস এজেন্ট · Agent', $invoice->sales_agent_name ?? '—'],
            ],
            'package' => [
                'title' => $locale === 'bn' ? ($invoice->package_title_bn ?: $invoice->package_title_en) : ($invoice->package_title_en ?: $invoice->package_title_bn),
                'code' => $invoice->package_code,
                'detail' => implode(' · ', $packageDetail),
            ],
            'items' => $invoice->items->map(fn (InvoiceItem $item) => [
                'title' => $locale === 'bn' ? ($item->title_bn ?: $item->title_en) : $item->title_en,
                'note' => $item->note,
                'quantity' => $n((float) $item->quantity),
                'rate' => (float) $item->unit_price > 0 ? $bdt($item->unit_price) : ($locale === 'bn' ? 'অন্তর্ভুক্ত' : 'Included'),
                'amount' => (float) $item->line_total > 0 ? $bdt($item->line_total) : '—',
            ])->all(),
            'travellers' => array_map(fn (array $t) => [
                'name' => $t['name'],
                'passport' => $t['passportNumber'] ? ($maskPassports ? $this->mask($t['passportNumber']) : $t['passportNumber']) : null,
                'expiry' => $t['passportExpiry'] ? Numerals::localizeDigits(date('d/m/Y', strtotime($t['passportExpiry'])), $locale) : null,
            ], $invoice->travellers ?? []),
            'totals' => array_values(array_filter([
                ['সাব-টোটাল · Subtotal', $bdt($subtotal)],
                (float) $invoice->discount_amount > 0 ? ['ডিসকাউন্ট · Discount'.($invoice->discount_label ? " ({$invoice->discount_label})" : ''), '− '.$bdt($invoice->discount_amount)] : null,
                ['সার্ভিস চার্জ ও ভ্যাট · VAT ('.Numerals::percent((float) $invoice->vat_rate, $locale).')', $bdt($invoice->vat_amount)],
            ])),
            'total' => $bdt($invoice->total_amount),
            'paid' => $bdt($invoice->paid_amount),
            'due' => $bdt($invoice->balance_due),
            'hasDue' => (float) $invoice->balance_due > 0,
            'payments' => $payments,
            'terms' => [
                'যাত্রার ২১ দিন আগে বাতিল করলে নন-রিফান্ডেবল অংশ বাদে অর্থ ফেরত দেওয়া হয়। পাসপোর্টের মেয়াদ যাত্রার তারিখ থেকে কমপক্ষে ৬ মাস থাকতে হবে।',
                'ভিসা প্রত্যাখ্যাত হলে প্রসেসিং ফি অফেরতযোগ্য। এই ইনভয়েস কম্পিউটার-জেনারেটেড; স্বাক্ষর ছাড়াও বৈধ।',
            ],
            'voidReason' => $invoice->status === Invoice::VOID ? $invoice->void_reason : null,
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
