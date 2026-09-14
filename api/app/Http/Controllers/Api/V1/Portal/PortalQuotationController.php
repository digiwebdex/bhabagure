<?php

namespace App\Http\Controllers\Api\V1\Portal;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Quotation;
use App\Models\QuotationLine;
use App\Services\Quotations\QuotationRefused;
use App\Services\Quotations\QuotationService;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Quotations addressed to the signed-in customer (docs/phase-6-customer-portal.md §3.2). Drafts and withdrawn ones are
 * staff business and never listed. Opening a sent one records Viewed; accepting a valid one tells its owner, who still
 * converts it into a booking.
 */
class PortalQuotationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $quotations = self::quotationsOf(PortalTripController::customer($request))
            ->orderByDesc('sent_at')->orderByDesc('id')->get();

        return response()->json(['data' => $quotations->map(fn (Quotation $q) => self::summary($q))->all()]);
    }

    public function show(Request $request, string $number, QuotationService $service): JsonResponse
    {
        $customer = PortalTripController::customer($request);
        $quotation = self::find($customer, $number);
        $service->markViewed($quotation, $customer);
        $en = app()->getLocale() === 'en';

        return response()->json(['data' => self::summary($quotation->refresh()->load('lines')) + [
            'lines' => $quotation->lines->sortBy('sort_order')->map(fn (QuotationLine $line) => [
                'kind' => $line->kind,
                'title' => $en ? $line->title_en : ($line->title_bn ?: $line->title_en),
                'quantity' => $line->quantity,
                'unitPrice' => Money::toNumber($line->unit_price),
                'amount' => Money::toNumber($line->amount),
            ])->values()->all(),
            'discount' => Money::toNumber($quotation->discount_amount),
            'serviceCharge' => Money::toNumber($quotation->vat_amount),
            'room' => $quotation->room_type,
            'durationDays' => $quotation->duration_days,
            'durationNights' => $quotation->duration_nights,
            'includesAirfare' => $quotation->includes_airfare,
        ]]);
    }

    public function accept(Request $request, string $number, QuotationService $service): JsonResponse
    {
        $customer = PortalTripController::customer($request);
        try {
            $quotation = $service->acceptFromPortal(self::find($customer, $number), $customer);
        } catch (QuotationRefused $e) {
            return response()->json(['message' => __('portal.quote_not_acceptable'), 'code' => $e->reason], Response::HTTP_CONFLICT);
        }

        return response()->json(['data' => self::summary($quotation)]);
    }

    /** @return \Illuminate\Database\Eloquent\Builder<Quotation> */
    private static function quotationsOf(Customer $customer)
    {
        return Quotation::query()->where('customer_id', $customer->id)->whereNotNull('sent_at')
            ->whereNotIn('status', [Quotation::DRAFT, Quotation::WITHDRAWN]);
    }

    private static function find(Customer $customer, string $number): Quotation
    {
        $quotation = self::quotationsOf($customer)->where('number', $number)->first();
        abort_if($quotation === null, Response::HTTP_NOT_FOUND, __('portal.not_found'));

        return $quotation;
    }

    /** @return array<string, mixed> */
    private static function summary(Quotation $quotation): array
    {
        $en = app()->getLocale() === 'en';
        $status = $quotation->displayStatus();

        return [
            'number' => $quotation->number,
            'title' => $en ? $quotation->package_title_en : ($quotation->package_title_bn ?: $quotation->package_title_en),
            'status' => $status,
            'travelDate' => $quotation->travel_date?->toDateString(),
            'pax' => $quotation->pax_count,
            'total' => Money::toNumber($quotation->total_amount),
            'validUntil' => $quotation->valid_until->toDateString(),
            'sentAt' => $quotation->sent_at?->toIso8601String(),
            'viewedAt' => $quotation->viewed_at?->toIso8601String(),
            'acceptedAt' => $quotation->accepted_at?->toIso8601String(),
            'canAccept' => $status === Quotation::SENT,
            'url' => url("/api/v1/public/quotations/{$quotation->share_token}".($en ? '?lang=en' : '')),
            'pdfUrl' => url("/api/v1/public/quotations/{$quotation->share_token}/pdf".($en ? '?lang=en' : '')),
        ];
    }
}
