<?php

namespace App\Services\Invoices;

use App\Http\Resources\AdminServiceRecord;
use App\Models\Client;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\ServiceRecord;
use App\Models\Staff;
use App\Services\AuditLogger;
use App\Services\Documents\DocumentNumbers;
use App\Services\Ledger\LedgerService;
use App\Support\WriteScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * An invoice staff write themselves (docs/phase-9-accounts.md §5): a customer or company, as many lines as it needs,
 * each with its own discount and VAT, a discount on the whole invoice, a due date and the words printed at the foot.
 *
 * It is a draft until it is issued: only then does it take an invoice number and go into the books. After that a change
 * is a correction: the books reverse the old figures and take the new ones (correct()); a cancelled one is voided.
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

    /**
     * An issued invoice put right (client, 2026-09-29; docs/phase-9-accounts.md §5): same number and date, new figures.
     * The journal entry it was posted with is reversed and the new figures posted, both today, so a closed month is never
     * rewritten; what was paid stays against it, so the new total can't be below that. A booking's invoice follows its
     * booking and is not changed here.
     *
     * For the moment of the change the invoice is a draft again, inside this one transaction: the frozen-snapshot rules
     * (the model's and the optional database triggers) hold everywhere else.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws LogicException when it isn't an issued invoice written here
     * @throws ValidationException when the new total is below what has been paid
     */
    public function correct(Invoice $invoice, Customer|Client $party, array $data, Staff $staff): Invoice
    {
        return DB::transaction(function () use ($invoice, $party, $data, $staff) {
            $locked = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== Invoice::ISSUED || $locked->kind !== Invoice::KIND_DEAL || $locked->booking_id !== null) {
                throw new LogicException('Only an issued invoice written in the builder is corrected here.');
            }
            $before = (float) $locked->total_amount;

            WriteScope::run(WriteScope::INVOICE_STATUS, fn () => $locked->forceFill(['status' => Invoice::DRAFT])->save());
            $locked->fill(self::billedTo($party) + ['updated_by_staff_id' => $staff->id])->save();
            $locked->items()->delete();
            $this->writeLines($locked, $data, $staff);
            WriteScope::run(WriteScope::INVOICE_STATUS, fn () => $locked->forceFill(['status' => Invoice::ISSUED])->save());

            $total = LedgerService::paisa($locked->total_amount);
            $paid = $this->ledger->invoicePaidPaisa($locked);
            if ($total <= 0) {
                throw ValidationException::withMessages(['lines' => __('invoices.needs_a_line')]);
            }
            if ($total < $paid) {
                throw ValidationException::withMessages(['discount_amount' => __('invoices.below_paid', ['paid' => number_format(LedgerService::amount($paid), 2)])]);
            }

            $this->ledger->postDealCorrected($locked, $staff);
            $this->ledger->syncInvoicePaid($locked);
            $this->audit->record('invoice.corrected', $staff, $locked, ['number' => $locked->invoice_number, 'total_before' => $before, 'total' => (float) $locked->total_amount]);

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
        // What the lines sold outlives them: a correction rewrites the lines, and each record finds its own by its key.
        $records = ServiceRecord::query()->where('invoice_id', $invoice->id)->get()->keyBy('line_key');
        foreach (array_values($data['lines']) as $index => $line) {
            $lineKey = empty($line['service']) ? null : $this->writeService($invoice, $line, $records->pull($line['service']['line_key'] ?? ''), $staff);
            $gross = LedgerService::paisa($line['quantity'] * $line['unit_price']);
            $discount = min(LedgerService::paisa($line['discount_amount'] ?? 0), $gross);
            $net = $gross - $discount;
            $rate = (float) ($line['vat_rate'] ?? 0);
            $lineVat = (int) round($net * $rate / 100);

            $invoice->items()->create([
                'kind' => 'deal',
                'line_key' => $lineKey,
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

        // A line taken off the invoice, or no longer a ticket or a booking: its record goes, and the log keeps what it was.
        foreach ($records as $removed) {
            if ($invoice->invoice_number !== null) {
                $this->audit->record('service_record.removed', $staff, $invoice, AdminServiceRecord::fields($removed));
            }
            $removed->delete();
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
     * What a line sold (docs/invoice-services.md). A correction keeps the record and what has happened to it since: a
     * status of Schedule changed and its note are set in the service lists, never overwritten from the invoice form.
     *
     * @param  array<string, mixed>  $line
     * @return string the key that ties the record to its line
     */
    private function writeService(Invoice $invoice, array $line, ?ServiceRecord $record, Staff $staff): string
    {
        $service = $line['service'];
        $type = $service['type'];
        $record ??= new ServiceRecord(['invoice_id' => $invoice->id, 'line_key' => (string) Str::ulid()]);
        $text = fn (string $field) => isset($service[$field]) && trim((string) $service[$field]) !== '' ? trim((string) $service[$field]) : null;

        $status = in_array($service['status'] ?? null, [ServiceRecord::BOOKED, ServiceRecord::CONFIRMED], true)
            ? $service['status']
            : ($record->status ?? ServiceRecord::BOOKED);
        $allowed = ServiceRecord::statusesFor($type);
        if (! in_array($status, $allowed, true)) {
            $status = $allowed[0] ?? null;
        }
        $air = $type === ServiceRecord::AIR;
        $oneWay = $air && ($service['trip_type'] ?? null) === ServiceRecord::ONE_WAY;

        $record->fill([
            'type' => $type,
            'passenger_name' => $text('passenger_name'),
            'reference' => $type === ServiceRecord::TOUR ? null : ($air ? ($text('reference') === null ? null : mb_strtoupper($text('reference'))) : $text('reference')),
            'trip_type' => $air ? $service['trip_type'] : null,
            'route_from' => $air ? $text('route_from') : null,
            'route_to' => $air ? $text('route_to') : null,
            'starts_on' => $service['starts_on'] ?? null,
            'ends_on' => $oneWay ? null : ($service['ends_on'] ?? null),
            'hotel_name' => $type === ServiceRecord::HOTEL ? $text('hotel_name') : null,
            'room_type' => $type === ServiceRecord::HOTEL ? $text('room_type') : null,
            'package_name' => $type === ServiceRecord::TOUR ? ($text('package_name') ?? $line['title']) : null,
            'tour_package_id' => $type === ServiceRecord::TOUR ? ($service['tour_package_id'] ?? null) : null,
            'status' => $status,
            'schedule_note' => $status === ServiceRecord::SCHEDULE_CHANGED ? $record->schedule_note : null,
            'schedule_changed_at' => $status === ServiceRecord::SCHEDULE_CHANGED ? $record->schedule_changed_at : null,
            'updated_by_staff_id' => $staff->id,
        ])->save();

        return $record->line_key;
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
            'service' => $item->line_key !== null && $item->serviceRecord !== null ? AdminServiceRecord::fields($item->serviceRecord) : null,
        ];
    }
}
