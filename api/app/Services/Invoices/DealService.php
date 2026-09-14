<?php

namespace App\Services\Invoices;

use App\Models\Client;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Staff;
use App\Models\Transaction;
use App\Services\AuditLogger;
use App\Services\Documents\DocumentNumbers;
use App\Services\Ledger\LedgerService;
use App\Services\Ledger\PaymentExceedsBalance;
use App\Support\WriteScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * "Deals · advance & due" (docs/phase-5-admin-core.md, question 3): a standalone invoice for a customer or a company —
 * a corporate tour, a group booked outside the website — with no package. It is issued at once with the next invoice
 * number, posted to the journal like any invoice, and paid through the ledger; paid and due are derived from the cash
 * book, never typed.
 */
final class DealService
{
    public function __construct(
        private readonly DocumentNumbers $numbers,
        private readonly LedgerService $ledger,
        private readonly InvoiceIssuer $issuer,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{amount: int|float|string, method: string, reference: ?string}|null  $advance
     *
     * @throws PaymentExceedsBalance
     */
    public function create(Customer|Client $party, string $title, int|float|string $total, ?string $note, ?array $advance, Staff $staff): Invoice
    {
        return DB::transaction(function () use ($party, $title, $total, $note, $advance, $staff) {
            $amount = LedgerService::amount(LedgerService::paisa($total));
            $billed = $party instanceof Customer
                ? ['customer_id' => $party->id, 'client_id' => $party->client_id, 'billed_name' => $party->name, 'billed_phone' => $party->phone, 'billed_email' => $party->email, 'billed_address' => $party->address]
                : ['customer_id' => null, 'client_id' => $party->id, 'billed_name' => $party->name, 'billed_phone' => $party->contact_phone, 'billed_email' => $party->contact_email, 'billed_address' => $party->city];

            $invoice = Invoice::query()->create($billed + [
                'kind' => Invoice::KIND_DEAL, 'title' => $title, 'note' => $note, 'subtotal_amount' => $amount, 'total_amount' => $amount,
                'sales_agent_name' => $staff->name, 'status' => Invoice::DRAFT, 'share_token' => Str::random(40),
            ]);
            $invoice->items()->create([
                'kind' => 'deal', 'title_en' => $title, 'title_bn' => $title,
                'quantity' => 1, 'unit_price' => $amount, 'line_total' => $amount, 'sort_order' => 0,
            ]);
            WriteScope::run(WriteScope::INVOICE_STATUS, fn () => $invoice->forceFill([
                'invoice_number' => $this->numbers->invoiceNumber(),
                'issued_on' => now('Asia/Dhaka')->toDateString(),
                'issued_by_staff_id' => $staff->id,
                'status' => Invoice::ISSUED,
            ])->save());

            $this->ledger->postDealIssued($invoice, $staff);
            $this->audit->record('deal.created', $staff, $invoice, ['number' => $invoice->invoice_number, 'title' => $title, 'total' => (float) $amount]);

            if ($advance !== null && LedgerService::paisa($advance['amount']) > 0) {
                $this->ledger->recordDealPayment($invoice, $advance['amount'], $advance['method'], 'Advance', $staff, $advance['reference'] ?? null);
            }

            return $invoice->refresh();
        });
    }

    /** @throws PaymentExceedsBalance */
    public function pay(Invoice $invoice, int|float|string $amount, string $method, ?string $reference, ?\DateTimeInterface $occurredAt, ?string $evidencePath, Staff $staff): Transaction
    {
        return DB::transaction(function () use ($invoice, $amount, $method, $reference, $occurredAt, $evidencePath, $staff) {
            $payment = $this->ledger->recordDealPayment($invoice, $amount, $method, 'Payment', $staff, $reference, $evidencePath, $occurredAt);
            $this->audit->record('deal.payment_recorded', $staff, $invoice, ['amount' => $payment->amount, 'method' => $method]);

            return $payment;
        });
    }

    /** Cancelled before any money stayed with it: payments must be reversed first, so the books never hold cash against nothing. */
    public function void(Invoice $invoice, string $reason, Staff $staff): Invoice
    {
        return DB::transaction(function () use ($invoice, $reason, $staff) {
            $locked = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if ($locked->kind !== Invoice::KIND_DEAL) {
                throw new LogicException('Only a deal invoice is voided here.');
            }
            if ($this->ledger->invoicePaidPaisa($locked) !== 0) {
                throw new LogicException('Reverse the payments on this deal before voiding it.');
            }

            return $this->issuer->void($locked, $reason, $staff);
        });
    }
}
