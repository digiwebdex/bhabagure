<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\BookingStatus;
use App\Events\PaymentRecorded;
use App\Http\Controllers\Controller;
use App\Http\Resources\AdminBooking;
use App\Models\Booking;
use App\Models\BookingTraveller;
use App\Models\Invoice;
use App\Models\SeatHold;
use App\Models\Staff;
use App\Models\Transaction;
use App\Services\Admin\Ownership;
use App\Services\Admin\OwnershipRefused;
use App\Services\AuditLogger;
use App\Services\Booking\BookingQuoteEditor;
use App\Services\Booking\BookingStateMachine;
use App\Services\Booking\BookingTransitionRefused;
use App\Services\Booking\PriceChanged;
use App\Services\Booking\QuoteLocked;
use App\Services\Invoices\InvoiceIssuer;
use App\Services\Invoices\InvoicePdf;
use App\Services\Ledger\EvidenceStore;
use App\Services\Ledger\LedgerService;
use App\Services\Ledger\PaymentExceedsBalance;
use App\Services\Quotations\QuotationService;
use App\Support\Phone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use LogicException;

/**
 * Bookings, their invoice and the payments against it (docs/phase-3-booking.md §3, §6). Money is only ever written
 * through LedgerService, status only through BookingStateMachine; nothing here accepts a paid amount or a status.
 */
class BookingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(BookingStatus::class)],
            'payment_status' => ['nullable', Rule::in(['unpaid', 'partial', 'paid'])],
            'owner' => ['nullable', Rule::in(['mine', 'pool'])],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        $staff = $request->user('staff');

        $page = $this->visible($staff)
            ->filtered($filters, $staff)
            ->with(['customer', 'assignedStaff'])
            ->withExists(['invoices as has_invoice' => fn (Builder $q) => $q->where('status', Invoice::ISSUED)])
            ->withExists(['transactions as has_payments'])
            ->latest('id')
            ->paginate(30);

        // The status chips' numbers: the same visibility and filters with each status in turn, so the Inquiry chip and
        // the sidebar badge (NavBadges 'bookings') are one number.
        $statusCounts = $this->visible($staff)->filtered(['status' => null] + $filters, $staff)
            ->toBase()->reorder()->selectRaw('status, COUNT(*) AS n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n);

        return response()->json([
            'data' => collect($page->items())->map(AdminBooking::summary(...)),
            'meta' => [
                'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(),
                'status_counts' => collect(BookingStatus::cases())->mapWithKeys(fn (BookingStatus $s) => [$s->value => $statusCounts[$s->value] ?? 0]),
            ],
        ]);
    }

    /**
     * Deletes a booking made by mistake — only while nothing about money exists: no issued or voided invoice, no payment,
     * no payment attempt. Anything else is cancelled through the state machine instead, so the books keep their record.
     */
    public function destroy(Request $request, int $id, AuditLogger $audit, QuotationService $quotations): JsonResponse
    {
        $booking = $this->find($request, $id, 'bookings.delete');
        $blocked = Invoice::query()->where('booking_id', $booking->id)->exists() ? 'has_invoice'
            : ($booking->transactions()->exists() ? 'has_payments' : ($booking->paymentAttempts()->exists() ? 'has_payment_attempts' : null));
        if ($blocked !== null) {
            return $this->refused("booking.delete_{$blocked}", $blocked);
        }

        DB::transaction(function () use ($booking, $request, $audit, $quotations) {
            SeatHold::query()->where('booking_id', $booking->id)->whereNull('released_at')->update(['released_at' => now()]);
            $quotations->bookingDeleted($booking, $request->user('staff'));
            $booking->delete();
            $audit->record('booking.deleted', $request->user('staff'), $booking, ['reference' => $booking->reference]);
        });

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return $this->detail($request, $this->find($request, $id));
    }

    /** Draft-invoice controls. The admin screen sends the total it computed with @bhabaghure/pricing. */
    public function updateQuote(Request $request, int $id, BookingQuoteEditor $editor): JsonResponse
    {
        $booking = $this->find($request, $id, 'bookings.update');
        $data = $request->validate([
            'pax' => ['required', 'integer', 'min:1', 'max:99'],
            'room' => ['required', Rule::in(['twin', 'triple', 'single'])],
            'discount' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'vat_rate' => ['required', 'numeric', Rule::in(BookingQuoteEditor::VAT_RATES)],
            'expected_total' => ['required', 'numeric', 'min:0'],
        ]);

        try {
            $editor->update($booking, $data['pax'], $data['room'], $data['discount'], $data['vat_rate'], $data['expected_total'], $request->user('staff'));
        } catch (PriceChanged $e) {
            return response()->json(['message' => __('booking.price_changed'), 'code' => 'price_changed', 'quote' => $e->quote], 409);
        } catch (QuoteLocked) {
            return $this->refused('booking.quote_locked', 'quote_locked');
        } catch (LogicException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'not_allowed'], 409);
        }

        return $this->detail($request, $booking->fresh());
    }

    /**
     * A traveller's details, completed by staff: a website booking needs only the lead's name and WhatsApp number, so the
     * others arrive as "Traveller 2" and so on (docs/phase-8-visa-quotes-pricing-downloads.md §2). The audit log names the
     * fields changed, never their values. An issued invoice keeps the details it was issued with.
     */
    public function updateTraveller(Request $request, int $travellerId, AuditLogger $audit): JsonResponse
    {
        $traveller = BookingTraveller::query()->findOrFail($travellerId);
        $booking = $this->find($request, $traveller->booking_id, 'bookings.update');
        foreach (['phone', 'passport_number'] as $field) {
            $value = trim((string) $request->input($field));
            $request->merge([$field => $value === '' ? null : ($field === 'phone' ? (Phone::normalizeBdMobile($value) ?? $value) : strtoupper((string) preg_replace('/\s+/', '', $value)))]);
        }
        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:160'],
            'date_of_birth' => ['nullable', 'date_format:Y-m-d', 'before:today'],
            'passport_number' => ['nullable', 'regex:/^(?:[A-Z]{2}\d{7}|[A-Z]\d{8})$/'],
            'passport_expiry' => ['nullable', 'date_format:Y-m-d', 'after:'.($booking->travel_end ?? $booking->travel_start ?? now('Asia/Dhaka'))->toDateString()],
            'phone' => [Rule::requiredIf($traveller->is_lead), 'nullable', 'regex:/^8801[3-9]\d{8}$/'],
            'email' => ['nullable', 'email', 'max:190'],
        ], ['passport_expiry.after' => __('booking.passport_expiry_after_travel')]);

        $traveller->fill(['full_name' => trim($data['full_name'])] + $data);
        $changed = array_keys($traveller->getDirty());
        $changed = array_values(array_diff($changed, ['passport_number_hash']));
        if ($changed !== []) {
            $traveller->save();
            $audit->record('traveller.updated', $request->user('staff'), $booking, ['traveller_id' => $traveller->id, 'fields' => $changed]);
        }

        return $this->detail($request, $booking->fresh());
    }

    public function issueInvoice(Request $request, int $id, InvoiceIssuer $issuer): JsonResponse
    {
        $booking = $this->find($request, $id, 'invoices.manage');
        try {
            $issuer->issueForBooking($booking, $request->user('staff'));
        } catch (LogicException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'not_allowed'], 409);
        }

        return $this->detail($request, $booking->fresh());
    }

    public function voidInvoice(Request $request, int $invoiceId, InvoiceIssuer $issuer): JsonResponse
    {
        $invoice = Invoice::query()->findOrFail($invoiceId);
        $booking = $this->find($request, (int) $invoice->booking_id, 'invoices.manage');
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        try {
            $issuer->void($invoice, $data['reason'], $request->user('staff'));
        } catch (LogicException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'not_allowed'], 409);
        }

        return $this->detail($request, $booking->fresh());
    }

    /**
     * "Record payment" — replaces the prototype's typed Paid field. Writes the cash book and the journal, with the
     * receipt: a payment typed in by staff is the entry that most needs proof (decided 2026-09-14).
     */
    public function recordPayment(Request $request, int $id, LedgerService $ledger, EvidenceStore $evidence): JsonResponse
    {
        $booking = $this->find($request, $id, 'transactions.create_manual');
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999', 'decimal:0,2'],
            'method' => ['required', Rule::in(LedgerService::STAFF_METHODS)],
            'reference' => ['nullable', 'string', 'max:100'],
            // A calendar date in Dhaka: "today" must be accepted between midnight and 06:00 Dhaka, when UTC is still yesterday.
            'occurred_at' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.now('Asia/Dhaka')->toDateString()],
            'note' => ['nullable', 'string', 'max:300'],
            'evidence' => EvidenceStore::rules(),
        ]);
        if ($booking->status->isFinal()) {
            return $this->refused('booking.closed', 'booking_closed');
        }

        try {
            $payment = $evidence->with($request->file('evidence'), fn (?string $path) => DB::transaction(fn () => $ledger->recordPayment(
                $booking, $data['amount'], $data['method'], ($data['note'] ?? null) ?: 'Payment recorded by staff',
                // A wallet or bank reference is unique per method, like a gateway transaction.
                externalRef: ($data['reference'] ?? null) ?: null, staff: $request->user('staff'), referenceLabel: $data['reference'] ?? null,
                occurredAt: self::paidOn($data['occurred_at'] ?? null), evidencePath: $path,
            )));
        } catch (PaymentExceedsBalance) {
            return $this->refused('booking.payment_exceeds_balance', 'exceeds_balance', 422);
        } catch (LogicException) {
            return $this->refused('booking.no_invoice', 'no_invoice');
        } catch (UniqueConstraintViolationException) {
            return $this->refused('booking.duplicate_reference', 'duplicate_reference', 422);
        }
        PaymentRecorded::dispatch($booking->fresh(), $payment, false);

        return $this->detail($request, $booking->fresh());
    }

    public function reversePayment(Request $request, int $transactionId, LedgerService $ledger): JsonResponse
    {
        $payment = Transaction::query()->findOrFail($transactionId);
        $booking = $this->find($request, (int) $payment->booking_id, 'transactions.create_manual');
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:300']]);

        try {
            DB::transaction(fn () => $ledger->reversePayment($payment, $data['reason'], $request->user('staff')));
        } catch (LogicException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'not_allowed'], 409);
        }

        return $this->detail($request, $booking->fresh());
    }

    public function transition(Request $request, int $id, string $action, BookingStateMachine $machine): JsonResponse
    {
        $booking = $this->find($request, $id, 'bookings.update');
        $staff = $request->user('staff');

        try {
            match ($action) {
                'confirm' => $machine->confirm($booking, $staff),
                'complete' => $machine->complete($booking, $staff),
                'cancel' => $machine->cancel($booking, $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']])['reason'], $staff),
            };
        } catch (BookingTransitionRefused $e) {
            return response()->json(['message' => __("booking.transition_{$e->reason}"), 'code' => $e->reason], 409);
        }

        return $this->detail($request, $booking->fresh());
    }

    /** The invoice as it prints: issued invoices from their snapshot, otherwise a preview of what issuing would print. */
    public function invoiceHtml(Request $request, int $id, InvoiceIssuer $issuer, InvoicePdf $pdf): Response
    {
        [$invoice, $header, $locale] = $this->printable($request, $id, $issuer);

        return response($pdf->html($invoice, $header, $locale))
            ->header('Content-Type', 'text/html; charset=utf-8')
            ->header('Cache-Control', 'no-store')
            ->header('Content-Security-Policy', "default-src 'none'; img-src data:; style-src 'unsafe-inline' https://fonts.googleapis.com; font-src https://fonts.gstatic.com");
    }

    public function invoicePdf(Request $request, int $id, InvoiceIssuer $issuer, InvoicePdf $pdf): Response
    {
        [$invoice, $header, $locale] = $this->printable($request, $id, $issuer);
        $name = ($invoice->invoice_number ?? 'draft-'.$request->route('id')).($header ? '' : '-pad').'.pdf';

        return response($invoice->exists ? $pdf->pdf($invoice, $header, $locale) : $pdf->render($pdf->html($invoice, $header, $locale, forPdf: true)))
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', "inline; filename=\"{$name}\"")
            ->header('Cache-Control', 'no-store');
    }

    /** @return array{0: Invoice, 1: bool, 2: string} */
    private function printable(Request $request, int $id, InvoiceIssuer $issuer): array
    {
        $booking = $this->find($request, $id);
        $options = $request->validate([
            'header' => ['nullable', 'boolean'],
            'lang' => ['nullable', Rule::in(['bn', 'en'])],
            'invoice_id' => ['nullable', 'integer'],
        ]);
        $invoice = isset($options['invoice_id'])
            ? Invoice::query()->where('booking_id', $booking->id)->findOrFail($options['invoice_id'])
            : (Invoice::query()->where('booking_id', $booking->id)->where('status', Invoice::ISSUED)->latest('id')->first() ?? $issuer->preview($booking));

        return [$invoice, (bool) ($options['header'] ?? true), $options['lang'] ?? 'bn'];
    }

    private function detail(Request $request, Booking $booking): JsonResponse
    {
        return response()->json(['data' => AdminBooking::detail($booking, $request->user('staff'))]);
    }

    /** Takes an unowned inquiry booking from the shared pool; its commission then belongs to the claimant (audited). */
    public function claim(Request $request, int $id, Ownership $ownership): JsonResponse
    {
        $booking = $this->find($request, $id, 'bookings.update', claiming: true);
        try {
            $ownership->claim($booking, $request->user('staff'));
        } catch (OwnershipRefused $e) {
            return $this->refused("ownership.{$e->reason}", $e->reason);
        }

        return $this->detail($request, $booking->fresh());
    }

    /** An admin moves a booking to another staff member, or back to the pool. The reason goes to the audit trail. */
    public function assign(Request $request, int $id, Ownership $ownership): JsonResponse
    {
        $booking = $this->find($request, $id, 'records.assign');
        $data = $request->validate([
            'staff_id' => ['present', 'nullable', 'integer', Rule::exists('staff', 'id')->whereNull('deleted_at')],
            'reason' => ['required', 'string', 'min:3', 'max:300'],
        ]);
        try {
            $ownership->assign($booking, $data['staff_id'] ? Staff::query()->find($data['staff_id']) : null, $request->user('staff'), $data['reason']);
        } catch (OwnershipRefused $e) {
            return $this->refused("ownership.{$e->reason}", $e->reason, 422);
        }

        return $this->detail($request, $booking->fresh());
    }

    /** Bookings this staff member may see — the one rule every list, count and search uses (Booking::scopeVisibleTo). */
    private function visible(Staff $staff): Builder
    {
        return Booking::query()->visibleTo($staff);
    }

    /**
     * A visible booking, and for any action ($permission given) one this staff member may work on: a sales agent sees the
     * shared pool but claims a booking before changing it, so two agents never work the same customer.
     */
    private function find(Request $request, int $id, ?string $permission = null, bool $claiming = false): Booking
    {
        $staff = $request->user('staff');
        abort_if($permission !== null && ! $staff->can($permission), 403, __('auth.forbidden'));
        $booking = $this->visible($staff)->findOrFail($id);

        if ($permission !== null && ! $claiming && ! Booking::seesAll($staff) && $booking->assigned_staff_id !== $staff->id) {
            abort(response()->json(['message' => __('ownership.claim_first'), 'code' => 'claim_first'], 409));
        }

        return $booking;
    }

    /** The day the money arrived, in Dhaka: now for today, midday for an earlier day (never drifting across a date line). */
    private static function paidOn(?string $date): ?Carbon
    {
        if ($date === null) {
            return null;
        }

        return $date === now('Asia/Dhaka')->toDateString() ? now() : Carbon::parse("{$date} 12:00", 'Asia/Dhaka')->utc();
    }

    private function refused(string $message, string $code, int $status = 409): JsonResponse
    {
        return response()->json(['message' => __($message), 'code' => $code], $status);
    }
}
