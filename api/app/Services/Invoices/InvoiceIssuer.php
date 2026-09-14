<?php

namespace App\Services\Invoices;

use App\Models\Booking;
use App\Models\BookingLine;
use App\Models\BookingTraveller;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Staff;
use App\Services\AuditLogger;
use App\Services\Documents\DocumentNumbers;
use App\Services\Ledger\LedgerService;
use App\Support\WriteScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * Issues a booking's invoice as a frozen snapshot (docs/phase-3-booking.md §6): everything printed is copied from the
 * booking, its priced lines and travellers at the moment of issue. Printing and PDFs read only these columns, so a
 * later package edit never changes an invoice the customer already has. Corrections are void-and-reissue.
 */
final class InvoiceIssuer
{
    public function __construct(
        private readonly DocumentNumbers $numbers,
        private readonly LedgerService $ledger,
        private readonly AuditLogger $audit,
    ) {}

    /** The booking's current issued invoice, issuing one if there is none. Idempotent. */
    public function issueForBooking(Booking $booking, ?Staff $staff = null): Invoice
    {
        return DB::transaction(function () use ($booking, $staff) {
            $booking = Booking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();
            $current = Invoice::query()->where('booking_id', $booking->id)->where('status', Invoice::ISSUED)->latest('id')->first();
            if ($current) {
                return $current;
            }
            if ($booking->status->isFinal() && $booking->status->value === 'cancelled') {
                throw new LogicException("Booking {$booking->reference} is cancelled; it can't be invoiced.");
            }

            $booking->load(['customer', 'package', 'lines', 'travellers', 'assignedStaff']);
            $invoice = Invoice::query()->create($this->snapshot($booking) + ['status' => Invoice::DRAFT, 'share_token' => Str::random(40)]);
            foreach ($booking->lines as $index => $line) {
                $invoice->items()->create([
                    'kind' => $line->kind, 'title_en' => $this->lineTitle($booking, $line, 'en'), 'title_bn' => $this->lineTitle($booking, $line, 'bn'),
                    'quantity' => $line->quantity, 'unit_price' => $line->unit_price, 'line_total' => $line->amount, 'sort_order' => $index,
                ]);
            }

            WriteScope::run(WriteScope::INVOICE_STATUS, fn () => $invoice->forceFill([
                'invoice_number' => $this->numbers->invoiceNumber(),
                'issued_on' => now('Asia/Dhaka')->toDateString(),
                'issued_by_staff_id' => $staff?->id,
                'status' => Invoice::ISSUED,
            ])->save());

            $this->ledger->postInvoiceIssued($invoice, $staff);
            $this->ledger->syncPaid($booking);
            $this->audit->record('invoice.issued', $staff, $invoice, ['booking' => $booking->reference, 'number' => $invoice->invoice_number]);

            return $invoice->refresh();
        });
    }

    /**
     * What the invoice will look like if issued now — built from the same snapshot, never saved. Paid amounts are the
     * booking's (ledger-derived); there is no number yet.
     */
    public function preview(Booking $booking): Invoice
    {
        $booking->load(['customer', 'package', 'lines', 'travellers', 'assignedStaff']);
        $invoice = (new Invoice)->forceFill($this->snapshot($booking) + [
            'status' => Invoice::DRAFT,
            'paid_amount' => $booking->paid_amount,
            'balance_due' => $booking->due_amount,
            'payment_status' => $booking->payment_status,
        ]);

        return $invoice->setRelation('items', $booking->lines->values()->map(fn (BookingLine $line, int $index) => (new InvoiceItem)->forceFill([
            'kind' => $line->kind, 'title_en' => $this->lineTitle($booking, $line, 'en'), 'title_bn' => $this->lineTitle($booking, $line, 'bn'),
            'quantity' => $line->quantity, 'unit_price' => $line->unit_price, 'line_total' => $line->amount, 'sort_order' => $index,
        ])));
    }

    public function void(Invoice $invoice, string $reason, Staff $staff): Invoice
    {
        return DB::transaction(function () use ($invoice, $reason, $staff) {
            $invoice = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if ($invoice->status !== Invoice::ISSUED) {
                throw new LogicException("Only an issued invoice can be voided; {$invoice->invoice_number} is {$invoice->status}.");
            }

            WriteScope::run(WriteScope::INVOICE_STATUS, fn () => $invoice->forceFill([
                'status' => Invoice::VOID, 'voided_at' => now(), 'void_reason' => mb_substr($reason, 0, 500),
            ])->save());
            $this->ledger->postInvoiceVoided($invoice, $staff);
            $this->audit->record('invoice.voided', $staff, $invoice, ['reason' => $reason]);

            return $invoice;
        });
    }

    /** @return array<string, mixed> */
    private function snapshot(Booking $booking): array
    {
        $package = $booking->package;

        return [
            'booking_id' => $booking->id,
            'customer_id' => $booking->customer_id,
            'client_id' => $booking->client_id,
            'billed_name' => $booking->travellers->firstWhere('is_lead', true)?->full_name ?? $booking->customer->name,
            'billed_phone' => $booking->customer->phone,
            'billed_email' => $booking->customer->email,
            'billed_address' => $booking->customer->address,
            'booking_reference' => $booking->reference,
            'package_code' => $package?->code,
            'package_title_en' => $booking->package_title_en,
            'package_title_bn' => $booking->package_title_bn,
            'package_duration_days' => $package?->duration_days,
            'package_duration_nights' => $package?->duration_nights,
            'includes_airfare' => $package?->includes_airfare,
            'travel_start' => $booking->travel_start,
            'travel_end' => $booking->travel_end,
            'sales_agent_name' => $booking->assignedStaff?->name,
            'travellers' => $booking->travellers->map(fn (BookingTraveller $traveller) => [
                'name' => $traveller->full_name,
                'passportNumber' => $traveller->passport_number,
                'passportExpiry' => $traveller->passport_expiry?->toDateString(),
            ])->values()->all(),
            'pax_count' => $booking->pax_count,
            'unit_price' => $booking->unit_price,
            'subtotal_amount' => $booking->lines->sum(fn (BookingLine $line) => (float) $line->amount),
            'discount_amount' => $booking->discount_amount,
            'vat_rate' => $booking->vat_rate,
            'vat_amount' => $booking->vat_amount,
            'total_amount' => $booking->total_amount,
        ];
    }

    private function lineTitle(Booking $booking, BookingLine $line, string $locale): ?string
    {
        return match ($line->kind) {
            'package' => $locale === 'en' ? $booking->package_title_en : $booking->package_title_bn,
            'single_supplement' => $locale === 'en' ? 'Single room supplement' : 'সিঙ্গেল রুম সাপ্লিমেন্ট',
            default => $locale === 'en' ? $line->title_en : $line->title_bn,
        };
    }
}
