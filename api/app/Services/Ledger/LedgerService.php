<?php

namespace App\Services\Ledger;

use App\Enums\TransactionDirection;
use App\Models\Account;
use App\Models\Booking;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Staff;
use App\Models\Transaction;
use App\Support\Pricing\PricingService;
use App\Support\WriteScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * The only writer of money (docs/phase-3-booking.md §3, decision 1 of 2026-09-13):
 *
 *  - issuing an invoice posts Dr Accounts receivable · Cr Sales · Cr VAT payable to the journal;
 *  - a payment writes the cash book (transactions) and posts Dr cash/bank/wallet/SSLCommerz · Cr Accounts receivable;
 *  - a booking's paid amount and payment status are recomputed from the cash book, never typed or incremented.
 *
 * Every table written here is append-only; corrections are reversing entries. Callers run inside a DB transaction.
 */
final class LedgerService
{
    public const CATEGORY_PAYMENT = 'customer_payment';

    /** The online payment charge line a customer paid on top of the booking. */
    public const CATEGORY_ONLINE_CHARGE = 'online_payment_charge';

    /** What a payment gateway kept from a settlement. */
    public const CATEGORY_GATEWAY_FEE = 'gateway_fee';

    /** Payment method → the asset account the money lands in. */
    public const METHOD_ACCOUNTS = [
        'cash' => Account::CASH,
        'bank_transfer' => Account::BANK,
        'cheque' => Account::BANK,
        'card_terminal' => Account::BANK,
        'bkash' => Account::MOBILE_WALLETS,
        'nagad' => Account::MOBILE_WALLETS,
        'rocket' => Account::MOBILE_WALLETS,
        'sslcommerz' => Account::SSLCOMMERZ_CLEARING,
    ];

    public const STAFF_METHODS = ['cash', 'bank_transfer', 'cheque', 'card_terminal', 'bkash', 'nagad', 'rocket'];

    public function postInvoiceIssued(Invoice $invoice, ?Staff $staff = null): ?JournalEntry
    {
        $total = self::paisa($invoice->total_amount);
        $vat = self::paisa($invoice->vat_amount);
        if ($total === 0) {
            return null;
        }

        return $this->post($invoice, "Invoice {$invoice->invoice_number} issued", $invoice->booking_id, $staff, [
            [Account::RECEIVABLE, $total, 0],
            [Account::PACKAGE_SALES, 0, $total - $vat],
            [Account::VAT_PAYABLE, 0, $vat],
        ]);
    }

    public function postInvoiceVoided(Invoice $invoice, ?Staff $staff = null): ?JournalEntry
    {
        $issued = JournalEntry::query()->where('source_type', $invoice->getMorphClass())->where('source_id', $invoice->id)
            ->whereNull('reverses_journal_entry_id')->first();

        return $issued ? $this->reverse($issued, "Invoice {$invoice->invoice_number} voided", $staff) : null;
    }

    /**
     * Credits a payment to the booking. The booking must have an issued invoice (the receivable it settles).
     *
     * Online payments can carry two more amounts, neither of them booking money:
     *  - $onlineCharge: the "online payment charge" line the customer saw and paid on top of the booking — income;
     *  - $gatewayFee: what the gateway keeps from the settlement — a business cost.
     * The money account is debited with what actually arrives: amount + online charge − gateway fee.
     */
    public function recordPayment(
        Booking $booking,
        string|int|float $amount,
        string $method,
        string $description,
        ?string $externalRef = null,
        ?Staff $staff = null,
        string|int|float $onlineCharge = 0,
        string|int|float $gatewayFee = 0,
        ?string $referenceLabel = null,
        bool $allowOverpayment = false,
        ?\DateTimeInterface $occurredAt = null,
    ): Transaction {
        $this->assertInTransaction();
        $amountPaisa = self::paisa($amount);
        $chargePaisa = self::paisa($onlineCharge);
        $gatewayFeePaisa = self::paisa($gatewayFee);
        $account = self::METHOD_ACCOUNTS[$method] ?? throw new InvalidArgumentException("Unknown payment method {$method}");
        if ($amountPaisa <= 0 || $chargePaisa < 0 || $gatewayFeePaisa < 0 || $gatewayFeePaisa > $amountPaisa + $chargePaisa) {
            throw new InvalidArgumentException('A payment amount must be positive, and fees can\'t exceed it.');
        }

        $booking = Booking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();
        $invoice = Invoice::query()->where('booking_id', $booking->id)->where('status', Invoice::ISSUED)->latest('id')->first()
            ?? throw new LogicException("Booking {$booking->reference} has no issued invoice to pay against.");
        if (! $allowOverpayment && $amountPaisa > self::paisa($booking->total_amount) - $this->paidPaisa($booking)) {
            throw new PaymentExceedsBalance($booking);
        }

        $common = [
            'method' => $method, 'booking_id' => $booking->id, 'invoice_id' => $invoice->id, 'customer_id' => $booking->customer_id,
            'client_id' => $booking->client_id, 'occurred_at' => $occurredAt ?? now(), 'recorded_by_staff_id' => $staff?->id,
            'reference_label' => $referenceLabel,
        ];
        $payment = Transaction::query()->create($common + [
            'direction' => TransactionDirection::In, 'amount' => self::amount($amountPaisa), 'category' => self::CATEGORY_PAYMENT,
            'external_ref' => $externalRef, 'description' => $description,
        ]);
        if ($chargePaisa > 0) {
            Transaction::query()->create($common + [
                'direction' => TransactionDirection::In, 'amount' => self::amount($chargePaisa), 'category' => self::CATEGORY_ONLINE_CHARGE,
                'external_ref' => $externalRef === null ? null : "{$externalRef}:charge", 'description' => "Online payment charge · {$description}",
            ]);
        }
        if ($gatewayFeePaisa > 0) {
            Transaction::query()->create($common + [
                'direction' => TransactionDirection::Out, 'amount' => self::amount($gatewayFeePaisa), 'category' => self::CATEGORY_GATEWAY_FEE,
                'external_ref' => $externalRef === null ? null : "{$externalRef}:gateway-fee", 'description' => "Gateway fee · {$description}",
            ]);
        }

        // Journalled on the day the money arrived, like its cash-book row — a backdated payment belongs to that day's books.
        $this->post($payment, "Payment for {$booking->reference} · {$description}", $booking->id, $staff, [
            [$account, $amountPaisa + $chargePaisa - $gatewayFeePaisa, 0],
            [Account::GATEWAY_FEES, $gatewayFeePaisa, 0],
            [Account::RECEIVABLE, 0, $amountPaisa],
            [Account::ONLINE_PAYMENT_CHARGES, 0, $chargePaisa],
        ], on: $occurredAt);

        $this->syncPaid($booking);

        return $payment;
    }

    /** A mistaken payment is corrected by an opposite entry in the cash book and the journal — never by editing. */
    public function reversePayment(Transaction $payment, string $reason, Staff $staff): Transaction
    {
        $this->assertInTransaction();
        if ($payment->category !== self::CATEGORY_PAYMENT || $payment->reverses_transaction_id !== null) {
            throw new LogicException('Only an original customer payment can be reversed.');
        }
        if ($payment->method === 'sslcommerz') {
            throw new LogicException('An online payment is refunded through the gateway, not reversed in the ledger.');
        }
        $booking = Booking::query()->whereKey($payment->booking_id)->lockForUpdate()->firstOrFail();
        if (Transaction::query()->where('reverses_transaction_id', $payment->id)->exists()) {
            throw new LogicException("Payment #{$payment->id} is already reversed.");
        }

        $reversal = Transaction::query()->create([
            'direction' => $payment->direction->opposite(), 'amount' => $payment->amount, 'category' => $payment->category,
            'method' => $payment->method, 'booking_id' => $payment->booking_id, 'invoice_id' => $payment->invoice_id,
            'customer_id' => $payment->customer_id, 'client_id' => $payment->client_id, 'occurred_at' => now(),
            'recorded_by_staff_id' => $staff->id, 'reverses_transaction_id' => $payment->id,
            'description' => "Reversal of payment #{$payment->id}: {$reason}",
        ]);

        $entry = JournalEntry::query()->where('source_type', $payment->getMorphClass())->where('source_id', $payment->id)->firstOrFail();
        $this->reverse($entry, "Reversal of payment #{$payment->id}: {$reason}", $staff, $reversal);
        $this->syncPaid($booking);

        return $reversal;
    }

    /** Recomputes paid amount and payment status of the booking and its current invoice from the cash book. */
    public function syncPaid(Booking $booking): void
    {
        $paid = self::amount($this->paidPaisa($booking));
        $status = PricingService::paymentStatus($booking->total_amount, $paid);

        WriteScope::run(WriteScope::BOOKING_MONEY, function () use ($booking, $paid, $status) {
            $booking->forceFill(['paid_amount' => $paid, 'payment_status' => $status])->save();

            $invoice = Invoice::query()->where('booking_id', $booking->id)->where('status', Invoice::ISSUED)->latest('id')->first();
            $invoice?->forceFill([
                'paid_amount' => $paid,
                'payment_status' => PricingService::paymentStatus($invoice->total_amount, $paid),
            ])->save();
        });
    }

    public function paidPaisa(Booking $booking): int
    {
        $sum = Transaction::query()->where('booking_id', $booking->id)->where('category', self::CATEGORY_PAYMENT)
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'in' THEN amount ELSE -amount END), 0) AS paid")
            ->value('paid');

        return self::paisa($sum ?? 0);
    }

    /** @param list<array{0: string, 1: int, 2: int}> $lines [account code, debit paisa, credit paisa] */
    private function post(Model $source, string $description, ?int $bookingId, ?Staff $staff, array $lines, ?int $reverses = null, ?\DateTimeInterface $on = null): JournalEntry
    {
        $this->assertInTransaction();
        $lines = array_values(array_filter($lines, fn (array $line) => $line[1] > 0 || $line[2] > 0));
        if (array_sum(array_column($lines, 1)) !== array_sum(array_column($lines, 2)) || $lines === []) {
            throw new LogicException("Unbalanced journal entry: {$description}");
        }

        $accounts = Account::query()->whereIn('code', array_column($lines, 0))->pluck('id', 'code');
        $entry = JournalEntry::query()->create([
            'entry_date' => Carbon::instance($on ?? now())->setTimezone('Asia/Dhaka')->toDateString(), 'description' => mb_substr($description, 0, 500),
            'source_type' => $source->getMorphClass(), 'source_id' => $source->getKey(), 'booking_id' => $bookingId,
            'reverses_journal_entry_id' => $reverses, 'created_by_staff_id' => $staff?->id,
        ]);
        foreach ($lines as [$code, $debit, $credit]) {
            $entry->lines()->create([
                'account_id' => $accounts[$code] ?? throw new LogicException("Account {$code} is missing from the chart of accounts."),
                'debit' => self::amount($debit), 'credit' => self::amount($credit),
            ]);
        }

        return $entry;
    }

    private function reverse(JournalEntry $entry, string $description, ?Staff $staff, ?Model $source = null): JournalEntry
    {
        $lines = $entry->lines()->with('account')->get()
            ->map(fn ($line) => [$line->account->code, self::paisa($line->credit), self::paisa($line->debit)])
            ->all();

        return $this->post($source ?? $entry->source, $description, $entry->booking_id, $staff, $lines, $entry->id);
    }

    private function assertInTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Ledger writes run inside a database transaction.');
        }
    }

    public static function paisa(string|int|float|null $amount): int
    {
        return (int) round(((float) ($amount ?? 0)) * 100);
    }

    public static function amount(int $paisa): string
    {
        return number_format($paisa / 100, 2, '.', '');
    }
}
