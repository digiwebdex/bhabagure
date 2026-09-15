<?php

namespace App\Services\Quotations;

use App\Models\Quotation;
use App\Services\Invoices\InvoicePdf;
use App\Services\Invoices\InvoiceView;
use App\Support\Payments\PaymentOptions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * Quotation PDFs through the invoice renderer (one headless Chrome render at a time on the shared VPS). Kept on the
 * private disk, keyed by everything that changes what's printed: the frozen quotation (its updated_at), the status
 * shown — which turns "expired" by date alone — header on/off, language and both template versions.
 */
class QuotationPdf
{
    public function __construct(private readonly QuotationView $view, private readonly InvoicePdf $renderer) {}

    public function html(Quotation $quotation, bool $header, string $locale = 'bn', bool $forPdf = false): string
    {
        return view('invoices.invoice', ['forPdf' => $forPdf] + $this->view->data($quotation, $header, $locale))->render();
    }

    public function pdf(Quotation $quotation, bool $header, string $locale = 'bn'): string
    {
        $key = sha1(implode('|', [
            InvoiceView::TEMPLATE_VERSION, QuotationView::TEMPLATE_VERSION, $quotation->id, $quotation->number, $quotation->displayStatus(),
            $quotation->valid_until->toDateString(), $quotation->updated_at?->getTimestamp(), (int) $header, $locale, PaymentOptions::fingerprint(),
        ]));
        $path = "quotations/{$quotation->id}/{$key}.pdf";
        $disk = Storage::disk('local');
        if ($disk->exists($path)) {
            return (string) $disk->get($path);
        }

        $bytes = Cache::lock('bhabaghure:invoice-pdf-render', 90)->block(75, fn () => $this->renderer->render($this->html($quotation, $header, $locale, forPdf: true)));
        $disk->put($path, $bytes);

        return $bytes;
    }
}
