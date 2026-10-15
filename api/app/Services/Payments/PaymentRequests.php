<?php

namespace App\Services\Payments;

use App\Enums\BookingStatus;
use App\Events\PaymentRecorded;
use App\Models\Booking;
use App\Models\PaymentRequest;
use App\Models\Staff;
use App\Services\AuditLogger;
use App\Services\Booking\BookingStateMachine;
use App\Services\Invoices\InvoiceIssuer;
use App\Services\Ledger\EvidenceStore;
use App\Services\Ledger\LedgerService;
use App\Services\Ledger\PaymentExceedsBalance;
use App\Support\Payments\PaymentOptions;
use App\Support\Pricing\PricingService;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Payment requests (docs/payment-requests.md): the customer says how much they paid, into which of the office's
 * accounts, when, with a screenshot; staff approve or reject it. Approving records the payment exactly as "Record
 * payment" on a booking does — the screenshot is its receipt — issues the invoice if the booking had none, and confirms
 * an inquiry (the owner's choice, 2026-10-15).
 */
final class PaymentRequests
{
    /** Requests waiting at once on one booking: enough for real payments in parts, too few to flood the queue. */
    public const MAX_PENDING = 5;

    public function __construct(
        private readonly EvidenceStore $evidence,
        private readonly LedgerService $ledger,
        private readonly InvoiceIssuer $invoices,
        private readonly BookingStateMachine $bookings,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{method: string, account_number: string|null, amount: float, paid_on: string, reference: string|null}  $data
     *
     * @throws PaymentRequestRefused
     */
    public function submit(Booking $booking, array $data, UploadedFile $screenshot, string $via, ?string $ip): PaymentRequest
    {
        // Completed and cancelled bookings take no payments, as on the admin's "Record payment".
        if ($booking->status->isFinal()) {
            throw new PaymentRequestRefused('booking_closed');
        }
        if ((float) $booking->due_amount <= 0) {
            throw new PaymentRequestRefused('nothing_due');
        }
        if ($data['amount'] > (float) $booking->due_amount) {
            throw new PaymentRequestRefused('over_due', 422);
        }
        $options = PaymentOptions::settings();
        $bank = null;
        if ($data['method'] === 'bank_transfer') {
            $bank = collect($options['banks'])->firstWhere('accountNumber', $data['account_number']) ?? throw new PaymentRequestRefused('unknown_account', 422);
        } elseif ($options['bkash'] === null) {
            throw new PaymentRequestRefused('unknown_account', 422);
        }
        if (PaymentRequest::query()->where('booking_id', $booking->id)->where('status', PaymentRequest::PENDING)->count() >= self::MAX_PENDING) {
            throw new PaymentRequestRefused('too_many_pending', 429);
        }
        if ($data['reference'] !== null && PaymentRequest::query()->where('method', $data['method'])->whereIn('status', [PaymentRequest::PENDING, PaymentRequest::APPROVED])
            ->whereRaw('lower(reference) = ?', [mb_strtolower($data['reference'])])->exists()) {
            throw new PaymentRequestRefused('duplicate_reference', 422);
        }

        return $this->evidence->with($screenshot, function (?string $path) use ($booking, $data, $bank, $options, $via, $ip) {
            $request = PaymentRequest::query()->create([
                'booking_id' => $booking->id,
                'customer_id' => $booking->customer_id,
                'method' => $data['method'],
                'bank_name' => $bank['bankName'] ?? 'bKash',
                'account_number' => $bank['accountNumber'] ?? $options['bkash']['number'],
                'amount' => $data['amount'],
                'paid_on' => $data['paid_on'],
                'reference' => $data['reference'],
                'evidence_path' => $path,
                'via' => $via,
                'ip' => $ip,
            ]);
            $this->audit->record('payment_request.submitted', null, $booking, ['request' => $request->id, 'amount' => $data['amount'], 'method' => $data['method'], 'via' => $via]);

            return $request;
        });
    }

    /**
     * Records the payment — the amount staff found in the account, the customer's figure by default — with the
     * screenshot as its receipt, then confirms the booking if it was an inquiry.
     *
     * @throws PaymentRequestRefused
     */
    public function approve(PaymentRequest $request, float $amount, bool $bkashCharge, Staff $by): PaymentRequest
    {
        [$approved, $payment, $confirmed] = DB::transaction(function () use ($request, $amount, $bkashCharge, $by) {
            $locked = PaymentRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== PaymentRequest::PENDING) {
                throw new PaymentRequestRefused('already_decided');
            }
            $booking = Booking::query()->whereKey($locked->booking_id)->lockForUpdate()->firstOrFail();
            if ($booking->status->isFinal()) {
                throw new PaymentRequestRefused('booking_closed');
            }
            $charge = 0;
            if ($bkashCharge && $locked->method === 'bkash') {
                $percent = PaymentOptions::settings()['bkash']['chargePercent'] ?? 0;
                if ($percent <= 0 || blank($locked->reference)) {
                    throw new PaymentRequestRefused('bkash_charge', 422);
                }
                $charge = PricingService::onlinePayment($amount, $percent)['charge'];
            }

            $this->invoices->issueForBooking($booking, $by);
            try {
                $payment = $this->ledger->recordPayment(
                    $booking, $amount, $locked->method, "Payment request #{$locked->id} · {$locked->bank_name}",
                    externalRef: $locked->reference ?: null, staff: $by, referenceLabel: $locked->reference,
                    onlineCharge: $charge, occurredAt: self::paidOn($locked->paid_on->toDateString()), evidencePath: $locked->evidence_path,
                );
            } catch (PaymentExceedsBalance) {
                throw new PaymentRequestRefused('over_due', 422);
            } catch (UniqueConstraintViolationException) {
                throw new PaymentRequestRefused('duplicate_reference', 422);
            }
            $locked->forceFill([
                'status' => PaymentRequest::APPROVED, 'approved_amount' => $amount, 'transaction_id' => $payment->id,
                'decided_by_staff_id' => $by->id, 'decided_at' => now(),
            ])->save();

            $booking->refresh();
            $confirmed = $booking->status === BookingStatus::Inquiry;
            if ($confirmed) {
                $this->bookings->confirm($booking, $by);
            }
            $this->audit->record('payment_request.approved', $by, $booking, ['request' => $locked->id, 'amount' => $amount, 'customer_amount' => (float) $locked->amount, 'confirmed' => $confirmed]);

            return [$locked, $payment, $confirmed];
        });
        // One message for one payment: when it confirms the booking, the confirmation message covers it.
        PaymentRecorded::dispatch($approved->booking()->firstOrFail(), $payment, $confirmed);

        return $approved;
    }

    /** @throws PaymentRequestRefused */
    public function reject(PaymentRequest $request, string $reason, Staff $by): PaymentRequest
    {
        return DB::transaction(function () use ($request, $reason, $by) {
            $locked = PaymentRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== PaymentRequest::PENDING) {
                throw new PaymentRequestRefused('already_decided');
            }
            $locked->forceFill(['status' => PaymentRequest::REJECTED, 'decided_by_staff_id' => $by->id, 'decided_at' => now(), 'decision_note' => $reason])->save();
            $this->audit->record('payment_request.rejected', $by, $locked->booking, ['request' => $locked->id, 'reason' => $reason]);

            return $locked;
        });
    }

    /**
     * A booking's requests as its customer sees them (booking page and portal), newest first.
     *
     * @return list<array<string, mixed>>
     */
    public static function forCustomer(Booking $booking): array
    {
        return PaymentRequest::query()->where('booking_id', $booking->id)->latest('id')->limit(20)->get()->map(fn (PaymentRequest $request) => [
            'id' => $request->id,
            'method' => $request->method,
            'bankName' => $request->bank_name,
            'amount' => (float) $request->amount,
            'approvedAmount' => $request->approved_amount === null ? null : (float) $request->approved_amount,
            'paidOn' => $request->paid_on->toDateString(),
            'reference' => $request->reference,
            'status' => $request->status,
            // Why it wasn't accepted, as staff wrote it.
            'note' => $request->status === PaymentRequest::REJECTED ? $request->decision_note : null,
            'sentAt' => $request->created_at?->toIso8601String(),
        ])->all();
    }

    /** @return array<string, mixed> */
    public static function forStaff(PaymentRequest $request): array
    {
        return [
            'id' => $request->id,
            'booking' => $request->relationLoaded('booking') && $request->booking ? [
                'id' => $request->booking->id, 'reference' => $request->booking->reference, 'status' => $request->booking->status->value,
                'due' => (float) $request->booking->due_amount, 'customer' => $request->booking->relationLoaded('customer') ? $request->booking->customer?->name : null,
            ] : ['id' => $request->booking_id],
            'method' => $request->method,
            'bank_name' => $request->bank_name,
            'account_number' => $request->account_number,
            'amount' => (float) $request->amount,
            'approved_amount' => $request->approved_amount === null ? null : (float) $request->approved_amount,
            'paid_on' => $request->paid_on->toDateString(),
            'reference' => $request->reference,
            'via' => $request->via,
            'status' => $request->status,
            'transaction_id' => $request->transaction_id,
            'decided_by' => $request->relationLoaded('decidedBy') ? $request->decidedBy?->name : null,
            'decided_at' => $request->decided_at?->toIso8601String(),
            'decision_note' => $request->decision_note,
            'created_at' => $request->created_at?->toIso8601String(),
        ];
    }

    /** The day the customer paid, at noon in Dhaka (or now, for today), as staff-recorded payments are dated. */
    private static function paidOn(string $date): Carbon
    {
        return $date === now('Asia/Dhaka')->toDateString() ? now() : Carbon::parse("{$date} 12:00", 'Asia/Dhaka')->utc();
    }
}
