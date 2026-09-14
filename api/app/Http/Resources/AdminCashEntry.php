<?php

namespace App\Http\Resources;

use App\Models\Invoice;
use App\Models\Staff;
use App\Models\Transaction;
use App\Services\Ledger\LedgerService;
use App\Support\Money;

/** Cash-book rows for the admin (docs/phase-5-admin-core.md §4.6). Amounts are numbers; evidence is a flag, never a path. */
final class AdminCashEntry
{
    public const RELATIONS = ['booking:id,reference,customer_id', 'invoice:id,invoice_number,kind,title', 'customer:id,name,phone,email', 'client:id,name,contact_phone,contact_email', 'recordedBy:id,name', 'reversal:id,reverses_transaction_id,occurred_at'];

    /** @return array<string, mixed> */
    public static function row(Transaction $entry, Staff $viewer): array
    {
        $party = $entry->customer
            ? ['type' => 'customer', 'id' => $entry->customer->id, 'name' => $entry->customer->name, 'phone' => $entry->customer->phone, 'email' => $entry->customer->email]
            : ($entry->client ? ['type' => 'client', 'id' => $entry->client->id, 'name' => $entry->client->name, 'phone' => $entry->client->contact_phone, 'email' => $entry->client->contact_email] : null);

        return [
            'id' => $entry->id,
            'occurred_at' => $entry->occurred_at->toIso8601String(),
            'direction' => $entry->direction->value,
            'amount' => Money::toNumber($entry->amount),
            'category' => $entry->category,
            'business_line' => $entry->business_line,
            'method' => $entry->method,
            'description' => $entry->description,
            'reference' => $entry->reference_label ?? $entry->external_ref,
            'booking' => $entry->booking ? ['id' => $entry->booking->id, 'reference' => $entry->booking->reference] : null,
            'invoice' => $entry->invoice ? ['id' => $entry->invoice->id, 'number' => $entry->invoice->invoice_number, 'kind' => $entry->invoice->kind, 'title' => $entry->invoice->title] : null,
            'party' => $party,
            'recorded_by' => $entry->recordedBy ? ['id' => $entry->recordedBy->id, 'name' => $entry->recordedBy->name] : null,
            'reverses_id' => $entry->reverses_transaction_id,
            'reversed_by' => $entry->reversal ? ['id' => $entry->reversal->id, 'occurred_at' => $entry->reversal->occurred_at->toIso8601String()] : null,
            'has_evidence' => $entry->evidence_path !== null,
            'actions' => [
                'reverse' => $viewer->can('transactions.create_manual') && $entry->reversal === null && LedgerService::reversible($entry),
            ],
            // Why ✕ is unavailable, for its tooltip.
            'reverse_blocked' => match (true) {
                $entry->reverses_transaction_id !== null => 'is_reversal',
                $entry->reversal !== null => 'reversed',
                $entry->method === 'sslcommerz' => 'online',
                ! LedgerService::reversible($entry) => 'fee_line',
                ! $viewer->can('transactions.create_manual') => 'permission',
                default => null,
            },
        ];
    }

    /** @return array<string, mixed> a deal invoice with what is paid and still due */
    public static function deal(Invoice $invoice): array
    {
        $invoice->loadMissing(['customer:id,name,phone,email', 'client:id,name,contact_phone,contact_email', 'transactions', 'issuedBy:id,name']);
        $payments = $invoice->transactions->where('category', LedgerService::CATEGORY_PAYMENT);

        return [
            'id' => $invoice->id,
            'number' => $invoice->invoice_number,
            'title' => $invoice->title,
            'note' => $invoice->note,
            'status' => $invoice->status,
            'payment_status' => $invoice->payment_status,
            'issued_on' => $invoice->issued_on?->toDateString(),
            'party' => $invoice->customer
                ? ['type' => 'customer', 'id' => $invoice->customer->id, 'name' => $invoice->customer->name, 'phone' => $invoice->customer->phone, 'email' => $invoice->customer->email]
                : ['type' => 'client', 'id' => $invoice->client?->id, 'name' => $invoice->billed_name, 'phone' => $invoice->billed_phone, 'email' => $invoice->billed_email],
            'total_amount' => Money::toNumber($invoice->total_amount),
            'paid_amount' => Money::toNumber($invoice->paid_amount),
            'balance_due' => Money::toNumber($invoice->balance_due),
            'issued_by' => $invoice->issuedBy?->name,
            'void_reason' => $invoice->void_reason,
            'payments' => $payments->map(fn (Transaction $t) => [
                'id' => $t->id,
                'occurred_at' => $t->occurred_at->toIso8601String(),
                'direction' => $t->direction->value,
                'amount' => Money::toNumber($t->amount),
                'method' => $t->method,
                'description' => $t->description,
                'reference' => $t->reference_label,
                'is_reversal' => $t->reverses_transaction_id !== null,
            ])->values(),
        ];
    }
}
