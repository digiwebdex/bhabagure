<?php

namespace App\Http\Resources;

use App\Models\Invoice;
use App\Models\Staff;
use App\Models\Transaction;
use App\Services\Ledger\CashBookCorrections;
use App\Services\Ledger\LedgerService;
use App\Support\Money;

/** Cash-book rows for the admin (docs/phase-5-admin-core.md §4.6). Amounts are numbers; evidence is a flag, never a path. */
final class AdminCashEntry
{
    public const RELATIONS = [
        'booking:id,reference,customer_id', 'invoice:id,invoice_number,kind,title', 'customer:id,name,phone,email', 'client:id,name,contact_phone,contact_email',
        'recordedBy:id,name', 'reversal:id,reverses_transaction_id,occurred_at', 'moneyAccount:id,code,name_en', 'approval.approvedBy:id,name',
        // Edit and Delete (docs/transaction-edits.md): what replaced or cancelled it, and what Edit must leave alone.
        'correction.staff:id,name', 'replaces.staff:id,name', 'payrollItem:id,transaction_id', 'bonusWithdrawal:id,cash_transaction_id',
    ];

    /** @return array<string, mixed> */
    public static function row(Transaction $entry, Staff $viewer): array
    {
        $deleteBlocked = CashBookCorrections::deleteBlocked($entry);
        $editBlocked = CashBookCorrections::editBlocked($entry);
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
            // The account the money landed in or left. Entries from before the column fall back to the method's account.
            'account' => $entry->moneyAccount
                ? ['code' => $entry->moneyAccount->code, 'name' => $entry->moneyAccount->name_en]
                : (isset(LedgerService::METHOD_ACCOUNTS[$entry->method]) ? ['code' => LedgerService::METHOD_ACCOUNTS[$entry->method], 'name' => null] : null),
            // Who has checked it, if anyone (docs/phase-9-accounts.md §7). The entry itself is never touched.
            'approved' => $entry->approval ? [
                'at' => $entry->approval->approved_at->toIso8601String(),
                'by' => $entry->approval->approvedBy?->name,
                'note' => $entry->approval->note,
            ] : null,
            'reverses_id' => $entry->reverses_transaction_id,
            'reversed_by' => $entry->reversal ? ['id' => $entry->reversal->id, 'occurred_at' => $entry->reversal->occurred_at->toIso8601String()] : null,
            // An edit's corrected entry: what it replaced, who changed what, and why (docs/transaction-edits.md).
            'edited' => $entry->replaces ? [
                'from_id' => $entry->replaces->original_transaction_id,
                'at' => $entry->replaces->created_at?->toIso8601String(),
                'by' => $entry->replaces->staff?->name,
                'changes' => $entry->replaces->changes,
                'reason' => $entry->replaces->reason,
            ] : null,
            // An entry that was edited or deleted (seen with "Show edits and deletions").
            'correction' => $entry->correction ? [
                'kind' => $entry->correction->kind,
                'replacement_id' => $entry->correction->replacement_transaction_id,
                'at' => $entry->correction->created_at?->toIso8601String(),
                'by' => $entry->correction->staff?->name,
                'reason' => $entry->correction->reason,
            ] : null,
            'has_evidence' => $entry->evidence_path !== null,
            'actions' => [
                'edit' => $viewer->can('transactions.edit') && $editBlocked === null,
                'delete' => $viewer->can('transactions.edit') && $deleteBlocked === null,
                'approve' => $viewer->can('transactions.approve'),
            ],
            // Why Edit or Delete is unavailable, for its tooltip.
            'edit_blocked' => $editBlocked ?? ($viewer->can('transactions.edit') ? null : 'permission'),
            'delete_blocked' => $deleteBlocked ?? ($viewer->can('transactions.edit') ? null : 'permission'),
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
