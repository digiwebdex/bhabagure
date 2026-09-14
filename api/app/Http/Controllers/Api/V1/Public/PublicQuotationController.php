<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Models\Quotation;
use App\Services\Quotations\QuotationPdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * The customer's quotation link (40-character random share token), the one WhatsApp fetches the PDF from. Only a
 * quotation that was sent — a draft has no link yet; a deleted one has none any more. The status pill tells a customer
 * holding an old link that it expired, was withdrawn or was booked. Quotations carry no passport data.
 */
class PublicQuotationController extends Controller
{
    public function show(Request $request, string $token, QuotationPdf $pdf): Response
    {
        [$quotation, $locale] = $this->resolve($request, $token);

        return response($pdf->html($quotation, true, $locale))
            ->header('Content-Type', 'text/html; charset=utf-8')
            ->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Referrer-Policy', 'no-referrer')
            ->header('Content-Security-Policy', "default-src 'none'; img-src data:; style-src 'unsafe-inline' https://fonts.googleapis.com; font-src https://fonts.gstatic.com");
    }

    public function pdf(Request $request, string $token, QuotationPdf $pdf): Response
    {
        [$quotation, $locale] = $this->resolve($request, $token);

        return response($pdf->pdf($quotation, true, $locale))
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', "inline; filename=\"{$quotation->number}.pdf\"")
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }

    /** @return array{0: Quotation, 1: string} */
    private function resolve(Request $request, string $token): array
    {
        abort_unless(strlen($token) === 40, 404);
        $quotation = Quotation::query()->where('share_token', $token)->whereNotNull('sent_at')->firstOrFail();
        $options = $request->validate(['lang' => ['nullable', Rule::in(['bn', 'en'])]]);

        return [$quotation, $options['lang'] ?? $quotation->locale];
    }
}
