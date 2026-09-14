<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Services\Invoices\InvoicePdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * The customer's invoice link (40-character random share token). Issued invoices only. Passport numbers are partly
 * masked here, because a link can be forwarded; the copy staff print or email carries them in full.
 */
class PublicInvoiceController extends Controller
{
    public function show(Request $request, string $token, InvoicePdf $pdf): Response
    {
        [$invoice, $header, $locale] = $this->resolve($request, $token);

        return response($pdf->html($invoice, $header, $locale, maskPassports: true))
            ->header('Content-Type', 'text/html; charset=utf-8')
            ->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Referrer-Policy', 'no-referrer')
            ->header('Content-Security-Policy', "default-src 'none'; img-src data:; style-src 'unsafe-inline' https://fonts.googleapis.com; font-src https://fonts.gstatic.com");
    }

    public function pdf(Request $request, string $token, InvoicePdf $pdf): Response
    {
        [$invoice, $header, $locale] = $this->resolve($request, $token);

        return response($pdf->pdf($invoice, $header, $locale, maskPassports: true))
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', "inline; filename=\"{$invoice->invoice_number}.pdf\"")
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }

    /** @return array{0: Invoice, 1: bool, 2: string} */
    private function resolve(Request $request, string $token): array
    {
        abort_unless(strlen($token) === 40, 404);
        $invoice = Invoice::query()->where('share_token', $token)->whereIn('status', [Invoice::ISSUED, Invoice::VOID])->firstOrFail();
        $options = $request->validate(['lang' => ['nullable', Rule::in(['bn', 'en'])]]);

        return [$invoice, true, $options['lang'] ?? 'bn'];
    }
}
