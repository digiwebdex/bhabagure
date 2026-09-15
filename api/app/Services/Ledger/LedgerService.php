<?php

namespace App\Services\Ledger;

use App\Enums\TransactionDirection;
use App\Events\CashEntryReversed;
use App\Models\Account;
use App\Models\Booking;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\OpeningBalance;
use App\Models\Staff;
use App\Models\Transaction;
use App\Support\Ledger\CashCategories;
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
        'bkash' => Account::BKASH,
        'nagad' => Account::NAGAD,
        'rocket' => Account::ROCKET,
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

    /** A deal invoice (no package): Dr Accounts receivable · Cr Deal and service sales · Cr VAT payable when it has VAT. */
    public function postDealIssued(Invoice $invoice, ?Staff $staff = null): ?JournalEntry
    {
        $total = self::paisa($invoice->total_amount);
        $vat = self::paisa($invoice->vat_amount);
        if ($total === 0) {
            return null;
        }

        return $this->post($invoice, "Deal invoice {$invoice->invoice_number} issued · {$invoice->title}", null, $staff, [
            [Account::RECEIVABLE, $total, 0],
            [Account::DEAL_SALES, 0, $total - $vat],
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
        ?string $evidencePath = null,
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
            'client_id' => $booking->client_id, 'occurred_at' => self::instant($occurredAt), 'recorded_by_staff_id' => $staff?->id,
            'reference_label' => $referenceLabel,
        ];
        $payment = Transaction::query()->create($common + [
            'direction' => TransactionDirection::In, 'amount' => self::amount($amountPaisa), 'category' => self::CATEGORY_PAYMENT,
            'external_ref' => $externalRef, 'description' => $description, 'evidence_path' => $evidencePath,
        ]);
        if ($chargePaisa > 0) {
            Transaction::query()->create($common + [
                'direction' => TransactionDirection::In, 'amount' => self::amount($chargePaisa), 'category' => self::CATEGORY_ONLINE_CHARGE,
                'external_ref' => $externalRef === null ? null : "{$externalRef}:charge",
                'description' => ($method === 'bkash' ? 'bKash charge' : 'Online payment charge')." · {$description}",
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

    /**
     * A payment on a deal invoice (a standalone invoice with no booking), by a staff member: the cash book row and
     * Dr money account · Cr Accounts receivable. Never more than is still due.
     */
    public function recordDealPayment(
        Invoice $invoice,
        string|int|float $amount,
        string $method,
        string $description,
        Staff $staff,
        ?string $referenceLabel = null,
        ?string $evidencePath = null,
        ?\DateTimeInterface $occurredAt = null,
    ): Transaction {
        $this->assertInTransaction();
        $amountPaisa = self::paisa($amount);
        $account = in_array($method, self::STAFF_METHODS, true) ? self::METHOD_ACCOUNTS[$method] : throw new InvalidArgumentException("Unknown payment method {$method}");
        if ($amountPaisa <= 0) {
            throw new InvalidArgumentException('A payment amount must be positive.');
        }
        $invoice = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
        if ($invoice->kind !== Invoice::KIND_DEAL || $invoice->status !== Invoice::ISSUED) {
            throw new LogicException("Invoice {$invoice->invoice_number} is not an issued deal invoice.");
        }
        if ($amountPaisa > self::paisa($invoice->total_amount) - $this->invoicePaidPaisa($invoice)) {
            throw new PaymentExceedsBalance($invoice);
        }

        $payment = Transaction::query()->create([
            'direction' => TransactionDirection::In, 'amount' => self::amount($amountPaisa), 'category' => self::CATEGORY_PAYMENT,
            'method' => $method, 'invoice_id' => $invoice->id, 'customer_id' => $invoice->customer_id, 'client_id' => $invoice->client_id,
            'description' => $description, 'reference_label' => $referenceLabel, 'evidence_path' => $evidencePath,
            'occurred_at' => self::instant($occurredAt), 'recorded_by_staff_id' => $staff->id,
        ]);
        $this->post($payment, "Payment for {$invoice->invoice_number} · {$description}", null, $staff, [
            [$account, $amountPaisa, 0],
            [Account::RECEIVABLE, 0, $amountPaisa],
        ], on: $occurredAt);
        $this->syncInvoicePaid($invoice);

        return $payment;
    }

    /**
     * A manual cash in or out: the cash book row, and the journal entry between its money account (from the method) and
     * the category's account — Dr money · Cr category for money in, the other way round for money out.
     */
    public function recordManualEntry(
        TransactionDirection $direction,
        string|int|float $amount,
        string $method,
        string $category,
        ?string $businessLine,
        string $description,
        Staff $staff,
        ?string $referenceLabel = null,
        ?string $evidencePath = null,
        ?\DateTimeInterface $occurredAt = null,
    ): Transaction {
        $this->assertInTransaction();
        [$allowed, $other] = CashCategories::MANUAL[$category] ?? throw new InvalidArgumentException("Unknown cash category {$category}");
        if ($allowed !== 'both' && $allowed !== $direction->value) {
            throw new InvalidArgumentException("{$category} is money {$allowed}, not {$direction->value}.");
        }
        $money = in_array($method, self::STAFF_METHODS, true) ? self::METHOD_ACCOUNTS[$method] : throw new InvalidArgumentException("Unknown payment method {$method}");
        if ($businessLine !== null && ! in_array($businessLine, CashCategories::BUSINESS_LINES, true)) {
            throw new InvalidArgumentException("Unknown business line {$businessLine}");
        }
        $paisa = self::paisa($amount);
        if ($paisa <= 0) {
            throw new InvalidArgumentException('An amount must be positive.');
        }

        $entry = Transaction::query()->create([
            'direction' => $direction, 'amount' => self::amount($paisa), 'category' => $category, 'business_line' => $businessLine,
            'method' => $method, 'description' => $description, 'reference_label' => $referenceLabel, 'evidence_path' => $evidencePath,
            'occurred_at' => self::instant($occurredAt), 'recorded_by_staff_id' => $staff->id,
        ]);
        $this->post($entry, "Manual {$direction->value} · {$category} · {$description}", null, $staff, $direction === TransactionDirection::In
            ? [[$money, $paisa, 0], [$other, 0, $paisa]]
            : [[$other, $paisa, 0], [$money, 0, $paisa]], on: $occurredAt);

        return $entry;
    }

    /**
     * A money account's opening balance, once, against Opening balances equity, dated the day the books start. Zero is
     * recorded without a journal entry. A wrong figure is corrected with a balance adjustment, never re-entered.
     */
    public function postOpeningBalance(Account $account, string|int|float $amount, string $asOf, ?string $note, Staff $staff): OpeningBalance
    {
        $this->assertInTransaction();
        if (! in_array($account->code, Account::MONEY, true)) {
            throw new InvalidArgumentException("Account {$account->code} is not a money account.");
        }
        $paisa = self::paisa($amount);
        if ($paisa < 0) {
            throw new InvalidArgumentException('An opening balance can\'t be negative.');
        }
        Account::query()->whereKey($account->id)->lockForUpdate()->first();
        if (OpeningBalance::query()->where('account_id', $account->id)->exists()) {
            throw new LogicException("{$account->name_en} already has its opening balance.");
        }

        $entry = $paisa === 0 ? null : $this->post($account, "Opening balance · {$account->name_en}", null, $staff, [
            [$account->code, $paisa, 0],
            [Account::OPENING_BALANCES, 0, $paisa],
        ], on: Carbon::parse($asOf, 'Asia/Dhaka'));

        return OpeningBalance::query()->create([
            'account_id' => $account->id, 'amount' => self::amount($paisa), 'as_of' => $asOf, 'note' => $note,
            'journal_entry_id' => $entry?->id, 'created_by_staff_id' => $staff->id,
        ]);
    }

    /**
     * A mistake is corrected by an opposite entry in the cash book and the journal — never by editing. Reversible: an
     * original staff-recorded customer payment (booking or deal) and a manual entry. Not an online payment (refunded
     * through the gateway), its charge or fee lines, or a reversal.
     */
    public function reversePayment(Transaction $payment, string $reason, Staff $staff): Transaction
    {
        $this->assertInTransaction();
        if (! self::reversible($payment)) {
            throw new LogicException($payment->method === 'sslcommerz'
                ? 'An online payment is refunded through the gateway, not reversed in the ledger.'
                : 'Only an original customer payment or manual entry can be reversed.');
        }
        $booking = $payment->booking_id ? Booking::query()->whereKey($payment->booking_id)->lockForUpdate()->firstOrFail() : null;
        $invoice = $booking === null && $payment->invoice_id ? Invoice::query()->whereKey($payment->invoice_id)->lockForUpdate()->firstOrFail() : null;
        Transaction::query()->whereKey($payment->id)->lockForUpdate()->first();
        if (Transaction::query()->where('reverses_transaction_id', $payment->id)->exists()) {
            throw new LogicException("Entry #{$payment->id} is already reversed.");
        }

        $reversal = Transaction::query()->create([
            'direction' => $payment->direction->opposite(), 'amount' => $payment->amount, 'category' => $payment->category,
            'business_line' => $payment->business_line, 'method' => $payment->method, 'booking_id' => $payment->booking_id,
            'invoice_id' => $payment->invoice_id, 'customer_id' => $payment->customer_id, 'client_id' => $payment->client_id,
            'occurred_at' => now(), 'recorded_by_staff_id' => $staff->id, 'reverses_transaction_id' => $payment->id,
            'description' => "Reversal of #{$payment->id}: {$reason}",
        ]);

        // A bKash charge recorded with the payment (Phase 8 §4.F) is in the same journal entry; its cash-book row goes too.
        if ($payment->external_ref !== null && $payment->category === self::CATEGORY_PAYMENT) {
            Transaction::query()->where('booking_id', $payment->booking_id)->where('method', $payment->method)
                ->where('category', self::CATEGORY_ONLINE_CHARGE)->where('external_ref', "{$payment->external_ref}:charge")
                ->whereNull('reverses_transaction_id')->whereDoesntHave('reversal')->get()
                ->each(fn (Transaction $charge) => Transaction::query()->create([
                    'direction' => $charge->direction->opposite(), 'amount' => $charge->amount, 'category' => $charge->category,
                    'business_line' => $charge->business_line, 'method' => $charge->method, 'booking_id' => $charge->booking_id,
                    'invoice_id' => $charge->invoice_id, 'customer_id' => $charge->customer_id, 'client_id' => $charge->client_id,
                    'occurred_at' => now(), 'recorded_by_staff_id' => $staff->id, 'reverses_transaction_id' => $charge->id,
                    'description' => "Reversal of #{$charge->id}: {$reason}",
                ]));
        }

        $entry = JournalEntry::query()->where('source_type', $payment->getMorphClass())->where('source_id', $payment->id)->firstOrFail();
        $this->reverse($entry, "Reversal of #{$payment->id}: {$reason}", $staff, $reversal);
        if ($booking) {
            $this->syncPaid($booking);
        } elseif ($invoice) {
            $this->syncInvoicePaid($invoice);
        }
        CashEntryReversed::dispatch($payment, $reversal, $staff, $reason);

        return $reversal;
    }

    /** Whether reversePayment accepts the row (the cash book shows ✕ "Reverse…" only for these). */
    public static function reversible(Transaction $row): bool
    {
        return $row->reverses_transaction_id === null && $row->method !== 'sslcommerz'
            && ($row->category === self::CATEGORY_PAYMENT || CashCategories::isManual($row->category));
    }

    /** A deal invoice's paid amount and payment status, from its cash-book rows. */
    public function syncInvoicePaid(Invoice $invoice): void
    {
        $paid = self::amount($this->invoicePaidPaisa($invoice));
        WriteScope::run(WriteScope::BOOKING_MONEY, fn () => $invoice->forceFill([
            'paid_amount' => $paid,
            'payment_status' => PricingService::paymentStatus($invoice->total_amount, $paid),
        ])->save());
    }

    public function invoicePaidPaisa(Invoice $invoice): int
    {
        $sum = Transaction::query()->where('invoice_id', $invoice->id)->where('category', self::CATEGORY_PAYMENT)
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'in' THEN amount ELSE -amount END), 0) AS paid")
            ->value('paid');

        return self::paisa($sum ?? 0);
    }

    /**
     * The company balance: each money account's journal balance (debits − credits), in paisa. Opening balances,
     * payments, manual entries and reversals are all in the journal, so nothing else is added.
     *
     * The old shared Mobile wallets account is included only while it still holds a balance nothing could attribute.
     *
     * @return array<string, int> account code => paisa, in Account::MONEY order
     */
    public function moneyBalances(): array
    {
        $rows = DB::table('journal_lines')->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->whereIn('accounts.code', [...Account::MONEY, Account::MOBILE_WALLETS])->groupBy('accounts.code')
            ->selectRaw('accounts.code AS code, SUM(journal_lines.debit) - SUM(journal_lines.credit) AS balance')
            ->pluck('balance', 'code');

        $balances = collect(Account::MONEY)->mapWithKeys(fn (string $code) => [$code => self::paisa($rows[$code] ?? 0)])->all();
        $legacy = self::paisa($rows[Account::MOBILE_WALLETS] ?? 0);

        return $legacy === 0 ? $balances : $balances + [Account::MOBILE_WALLETS => $legacy];
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

    /**
     * When money moved, as a UTC instant. Eloquent writes a date in its own timezone without converting, so a Dhaka
     * time passed as-is would be stored six hours off.
     */
    private static function instant(?\DateTimeInterface $at): Carbon
    {
        return $at === null ? now()->utc() : Carbon::instance($at)->utc();
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
