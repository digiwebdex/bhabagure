<?php

namespace App\Services\Invoices;

use App\Models\Client;
use App\Models\Customer;
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
 * An invoice staff write themselves (docs/phase-9-accounts.md §5): a customer or company, as many lines as it needs,
 * each with its own discount and VAT, a discount on the whole invoice, a due date and the words printed at the foot.
 *
 * It is a draft until it is issued: only then does it take an invoice number and go into the books. After that its
 * figures are frozen — a mistake is voided and written again, which is what the ledger and the customer's copy expect.
 */
final class InvoiceBuilder
{
    public function __construct(
        private readonly DocumentNumbers $numbers,
        private readonly LedgerService $ledger,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data  title, note, footer, due_on, discount_label, discount_amount, lines[]
     */
    public function createDraft(Customer|Client $party, array $data, Staff $staff): Invoice
    {
        return DB::transaction(function () use ($party, $data, $staff) {
            $invoice = Invoice::query()->create(self::billedTo($party) + [
                'kind' => Invoice::KIND_DEAL,
                'status' => Invoice::DRAFT,
                'share_token' => Str::random(40),
                'sales_agent_name' => $staff->name,
                'updated_by_staff_id' => $staff->id,
                'title' => $data['title'],
                // Zeroed here and worked out from the lines a moment later; the columns hold no nulls.
                'subtotal_amount' => 0, 'discount_amount' => 0, 'vat_rate' => 0, 'vat_amount' => 0, 'delivery_charge' => 0, 'total_amount' => 0,
            ]);
            $this->writeLines($invoice, $data, $staff);
            $this->audit->record('invoice.drafted', $staff, $invoice, ['title' => $invoice->title, 'total' => (float) $invoice->total_amount]);

            return $invoice->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws LogicException when the invoice has already been issued
     */
    public function updateDraft(Invoice $invoice, Customer|Client $party, array $data, Staff $staff): Invoice
    {
        return DB::transaction(function () use ($invoice, $party, $data, $staff) {
            $locked = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== Invoice::DRAFT) {
                throw new LogicException('Only a draft invoice can be changed; void this one and write another.');
            }
            $locked->fill(self::billedTo($party) + ['updated_by_staff_id' => $staff->id])->save();
            $locked->items()->delete();
            $this->writeLines($locked, $data, $staff);
            $this->audit->record('invoice.draft_updated', $staff, $locked, ['total' => (float) $locked->total_amount]);

            return $locked->refresh();
        });
    }

    /** Gives the draft its number and posts it to the journal; from here the figures are frozen. */
    public function issue(Invoice $invoice, Staff $staff): Invoice
    {
        return DB::transaction(function () use ($invoice, $staff) {
            $locked = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== Invoice::DRAFT) {
                return $locked;
            }
            if ($locked->items()->count() === 0 || LedgerService::paisa($locked->total_amount) <= 0) {
                throw new LogicException('An invoice needs at least one line and a total above zero.');
            }

            WriteScope::run(WriteScope::INVOICE_STATUS, fn () => $locked->forceFill([
                'invoice_number' => $locked->invoice_number ?? $this->numbers->invoiceNumber(),
                'issued_on' => $locked->issued_on ?? now('Asia/Dhaka')->toDateString(),
                'issued_by_staff_id' => $staff->id,
                'status' => Invoice::ISSUED,
            ])->save());

            $this->ledger->postDealIssued($locked, $staff);
            $this->audit->record('invoice.issued', $staff, $locked, ['number' => $locked->invoice_number, 'total' => (float) $locked->total_amount]);

            return $locked->refresh();
        });
    }

    /**
     * The lines and every figure that follows from them. Each line: quantity × price, less its own discount, then its
     * own VAT. The invoice's discount comes off the sum of the lines, and its VAT rate is what a new line starts with.
     *
     * @param  array<string, mixed>  $data
     */
    private function writeLines(Invoice $invoice, array $data, Staff $staff): void
    {
        $subtotal = 0;
        $vat = 0;
        foreach (array_values($data['lines']) as $index => $line) {
            $gross = LedgerService::paisa($line['quantity'] * $line['unit_price']);
            $discount = min(LedgerService::paisa($line['discount_amount'] ?? 0), $gross);
            $net = $gross - $discount;
            $rate = (float) ($line['vat_rate'] ?? 0);
            $lineVat = (int) round($net * $rate / 100);

            $invoice->items()->create([
                'kind' => 'deal',
                'title_en' => $line['title'],
                'title_bn' => $line['title'],
                'detail' => $line['detail'] ?? null,
                'note' => $line['note'] ?? null,
                'quantity' => $line['quantity'],
                'unit_price' => $line['unit_price'],
                'discount_amount' => LedgerService::amount($discount),
                'vat_rate' => $rate,
                'vat_amount' => LedgerService::amount($lineVat),
                'line_total' => LedgerService::amount($net),
                'sort_order' => $index,
            ]);
            $subtotal += $net;
            $vat += $lineVat;
        }

        $documentDiscount = min(LedgerService::paisa($data['discount_amount'] ?? 0), $subtotal);
        // Sending tickets, passports or visas across is charged on top of the lines, after any discount.
        $delivery = LedgerService::paisa($data['delivery_charge'] ?? 0);
        $invoice->fill([
            'title' => $data['title'],
            'po_number' => $data['po_number'] ?? null,
            'note' => $data['note'] ?? null,
            'footer' => $data['footer'] ?? null,
            'due_on' => $data['due_on'] ?? null,
            'discount_label' => $data['discount_label'] ?? null,
            'discount_amount' => LedgerService::amount($documentDiscount),
            'subtotal_amount' => LedgerService::amount($subtotal),
            'vat_rate' => $data['vat_rate'] ?? 0,
            'vat_amount' => LedgerService::amount($vat),
            'delivery_charge' => LedgerService::amount($delivery),
            'total_amount' => LedgerService::amount($subtotal - $documentDiscount + $vat + $delivery),
            'updated_by_staff_id' => $staff->id,
        ])->save();
    }

    /**
     * Who the invoice is billed to — a customer or a B2B client, copied onto the invoice so the customer's copy keeps
     * reading the same even if the record is edited later.
     *
     * @return array<string, mixed>
     */
    private static function billedTo(Customer|Client $party): array
    {
        return $party instanceof Customer
            ? [
                'customer_id' => $party->id, 'client_id' => $party->client_id, 'billed_name' => $party->name,
                'billed_phone' => $party->phone, 'billed_email' => $party->email, 'billed_address' => $party->address,
            ]
            : [
                'customer_id' => null, 'client_id' => $party->id, 'billed_name' => $party->name,
                'billed_phone' => $party->contact_phone, 'billed_email' => $party->contact_email, 'billed_address' => $party->city,
            ];
    }

    /** What the invoice screens show for one line. */
    public static function line(InvoiceItem $item): array
    {
        return [
            'id' => $item->id,
            'title' => $item->title_en,
            'detail' => $item->detail,
            'note' => $item->note,
            'quantity' => (float) $item->quantity,
            'unit_price' => (float) $item->unit_price,
            'discount_amount' => (float) $item->discount_amount,
            'vat_rate' => (float) $item->vat_rate,
            'vat_amount' => (float) $item->vat_amount,
            'line_total' => (float) $item->line_total,
        ];
    }
}
