<?php

namespace App\Http\Resources;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\BookingLine;
use App\Models\Invoice;
use App\Models\PaymentAttempt;
use App\Services\Payments\PaymentService;
use App\Support\Money;
use App\Support\Payments\PaymentOptions;

/**
 * A booking as its customer sees it on the website: amounts, status and traveller names — never passport data,
 * internal notes or staff details. camelCase like the rest of the public API.
 */
final class PublicBooking
{
    /** @return array<string, mixed> */
    public static function make(Booking $booking): array
    {
        $booking->loadMissing(['lines', 'travellers']);
        $en = app()->getLocale() === 'en';
        $invoice = Invoice::query()->where('booking_id', $booking->id)->where('status', Invoice::ISSUED)->latest('id')->first();
        $attempt = PaymentAttempt::query()->where('booking_id', $booking->id)->latest('id')->first();
        $due = (float) $booking->due_amount;
        $canPay = in_array($booking->status, [BookingStatus::Inquiry, BookingStatus::Confirmed], true) && $due > 0;
        $checkout = PaymentOptions::checkoutAvailable();

        return [
            'reference' => $booking->reference,
            'status' => $booking->status->value,
            'paymentStatus' => $booking->payment_status,
            'packageTitle' => $en ? $booking->package_title_en : ($booking->package_title_bn ?: $booking->package_title_en),
            'travelStart' => $booking->travel_start?->toDateString(),
            'travelEnd' => $booking->travel_end?->toDateString(),
            'pax' => $booking->pax_count,
            'room' => $booking->room_type,
            'lines' => $booking->lines->map(fn (BookingLine $line) => [
                'kind' => $line->kind,
                'code' => $line->code,
                'title' => $en ? $line->title_en : ($line->title_bn ?: $line->title_en),
                'quantity' => $line->quantity,
                'unitPrice' => Money::toNumber($line->unit_price),
                'amount' => Money::toNumber($line->amount),
            ])->values()->all(),
            'discount' => Money::toNumber($booking->discount_amount),
            'chargePercent' => Money::toNumber($booking->vat_rate),
            'serviceCharge' => Money::toNumber($booking->vat_amount),
            'total' => Money::toNumber($booking->total_amount),
            'paid' => Money::toNumber($booking->paid_amount),
            'due' => Money::toNumber($booking->due_amount),
            'travellers' => $booking->travellers->map(fn ($traveller) => ['name' => $traveller->full_name])->values()->all(),
            'invoice' => $invoice ? [
                'number' => $invoice->invoice_number,
                'issuedOn' => $invoice->issued_on?->toDateString(),
                'url' => url("/api/v1/public/invoices/{$invoice->share_token}"),
                'pdfUrl' => url("/api/v1/public/invoices/{$invoice->share_token}/pdf"),
            ] : null,
            'payment' => [
                'canPay' => $canPay,
                // Phase 8 §4.F: the built-in SSLCommerz checkout, and paying by hand with each method's exact amount.
                'checkout' => $checkout,
                'manual' => $canPay ? PaymentOptions::forAmount(Money::toNumber($booking->due_amount), $checkout) : null,
                // What paying the balance online costs now: shown line by line before the customer commits.
                'online' => PaymentService::quote($booking),
                'lastAttempt' => $attempt ? [
                    'status' => $attempt->status->value,
                    'amount' => Money::toNumber($attempt->amount),
                    'at' => $attempt->created_at?->toIso8601String(),
                ] : null,
            ],
            'createdAt' => $booking->created_at?->toIso8601String(),
        ];
    }
}
