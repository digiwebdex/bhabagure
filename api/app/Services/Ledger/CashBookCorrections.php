<?php

namespace App\Services\Ledger;

use App\Models\Account;
use App\Models\Booking;
use App\Models\Invoice;
use App\Models\Staff;
use App\Models\Transaction;
use App\Models\TransactionCorrection;
use App\Services\AuditLogger;
use App\Support\Ledger\CashCategories;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Edit and Delete on the cash book (client, 2026-10-01; docs/transaction-edits.md). The books stay append-only, so
 * nothing is overwritten:
 *  - a delete cancels the entry with a reversing entry carrying the entry's own date, so the month it belongs to reads
 *    as if it had never been;
 *  - an edit does the same, then records the corrected entry — same receipt, same booking or invoice — on the date the
 *    edit gives.
 * Every balance (money accounts, a booking's or an invoice's amount due, the reports) is worked out from the entries, so
 * all of them follow. Each one is logged (transaction_corrections) and audited. `transactions.edit` (super admin and
 * admin by default).
 */
final class CashBookCorrections
{
    public function __construct(private readonly LedgerService $ledger, private readonly AuditLogger $audit) {}

    /** Why the entry can't be deleted, or null. */
    public static function deleteBlocked(Transaction $row): ?string
    {
        return match (true) {
            $row->reverses_transaction_id !== null => 'is_reversal',
            ($row->relationLoaded('reversal') ? $row->reversal : $row->reversal()->first()) !== null => 'reversed',
            $row->method === 'sslcommerz' => 'online',
            $row->category === CashCategories::VAT_PAID => 'vat',
            ! LedgerService::reversible($row) => 'fee_line',
            default => null,
        };
    }

    /**
     * Why the entry can't be edited, or null. Beyond what stops a delete: a salary paid from Payroll and a bonus paid out
     * are deleted and paid again there, so they stay tied to the month or the withdrawal; and a payment sent with a
     * bKash charge is deleted and recorded again on its booking, where the charge is worked out.
     */
    public static function editBlocked(Transaction $row): ?string
    {
        return self::deleteBlocked($row) ?? match (true) {
            ($row->relationLoaded('payrollItem') ? $row->payrollItem : $row->payrollItem()->first()) !== null => 'payroll',
            ($row->relationLoaded('bonusWithdrawal') ? $row->bonusWithdrawal : $row->bonusWithdrawal()->first()) !== null => 'bonus',
            $row->category === LedgerService::CATEGORY_PAYMENT && $row->external_ref !== null
                && Transaction::query()->where('category', LedgerService::CATEGORY_ONLINE_CHARGE)->where('method', $row->method)->where('external_ref', "{$row->external_ref}:charge")->exists() => 'charge',
            default => null,
        };
    }

    /** The reversing entry that cancelled it. */
    public function delete(Transaction $entry, string $reason, Staff $by): Transaction
    {
        return DB::transaction(function () use ($entry, $reason, $by) {
            $entry = Transaction::query()->whereKey($entry->id)->lockForUpdate()->with('moneyAccount')->firstOrFail();
            if (($blocked = self::deleteBlocked($entry)) !== null) {
                throw new CorrectionRefused($blocked);
            }

            $reversal = $this->ledger->reversePayment($entry, "deleted — {$reason}", $by, $entry->occurred_at);
            TransactionCorrection::query()->create([
                'kind' => TransactionCorrection::DELETE, 'original_transaction_id' => $entry->id, 'reversal_transaction_id' => $reversal->id,
                'reason' => $reason, 'staff_id' => $by->id,
            ]);
            $this->audit->record('cash.deleted', $by, $entry, ['reversal' => $reversal->id, 'reason' => $reason, 'entry' => self::values($entry)]);

            return $reversal;
        });
    }

    /**
     * The corrected entry. $data: occurred_on (Y-m-d), account (code), category, business_line, description, reference,
     * amount, reason. A customer payment keeps its category and stays with its booking or invoice.
     *
     * @param  array<string, mixed>  $data
     */
    public function edit(Transaction $entry, array $data, Staff $by): Transaction
    {
        return DB::transaction(function () use ($entry, $data, $by) {
            $entry = Transaction::query()->whereKey($entry->id)->lockForUpdate()->with('moneyAccount')->firstOrFail();
            if (($blocked = self::editBlocked($entry)) !== null) {
                throw new CorrectionRefused($blocked);
            }

            $before = self::values($entry);
            $account = Account::query()->where('code', $data['account'])->firstOrFail();
            $payment = $entry->category === LedgerService::CATEGORY_PAYMENT;
            $after = [
                'date' => $data['occurred_on'],
                'account' => $account->code,
                'category' => $payment ? $entry->category : $data['category'],
                'business_line' => $payment ? $entry->business_line : (($data['business_line'] ?? null) ?: null),
                'description' => trim((string) ($data['description'] ?? '')) ?: ($payment ? $entry->description : ''),
                'reference' => trim((string) ($data['reference'] ?? '')) ?: null,
                'amount' => Money::toNumber(LedgerService::amount(LedgerService::paisa($data['amount']))),
            ];
            $changes = [];
            foreach ($after as $field => $value) {
                if ($before[$field] !== $value) {
                    $changes[$field] = [$before[$field], $value];
                }
            }
            if ($changes === []) {
                throw ValidationException::withMessages(['amount' => __('payments.edit_nothing')]);
            }

            // Same account: the method it was paid by (a cheque stays a cheque). A new one: the method that account means.
            $method = $account->code === $before['account'] ? $entry->method : LedgerService::methodForAccount($account->code);
            // Same day: its own moment, so it keeps its place in the list. Another day: midday that day in Dhaka.
            $occurredAt = $after['date'] === $before['date'] ? $entry->occurred_at : Carbon::parse("{$after['date']} 12:00", 'Asia/Dhaka')->utc();
            $reason = trim((string) ($data['reason'] ?? '')) ?: null;

            $reversal = $this->ledger->reversePayment($entry, 'edited'.($reason ? " — {$reason}" : ''), $by, $entry->occurred_at);
            try {
                $replacement = match (true) {
                    $payment && $entry->booking_id !== null => $this->ledger->recordPayment(
                        Booking::query()->findOrFail($entry->booking_id), $after['amount'], $method, $after['description'],
                        // The reference is shown from reference_label; external_ref stays with the original, unique per method.
                        externalRef: null, staff: $by, referenceLabel: $after['reference'], occurredAt: $occurredAt,
                        evidencePath: $entry->evidence_path, into: $account,
                    ),
                    $payment => $this->ledger->recordDealPayment(
                        Invoice::query()->findOrFail($entry->invoice_id), $after['amount'], $method, $after['description'], $by,
                        $after['reference'], $entry->evidence_path, $occurredAt, $account,
                    ),
                    default => $this->ledger->recordManualEntry(
                        $entry->direction, $after['amount'], $method, $after['category'], $after['business_line'], $after['description'],
                        $by, $after['reference'], $entry->evidence_path, $occurredAt, $account,
                    ),
                };
            } catch (PaymentExceedsBalance) {
                throw ValidationException::withMessages(['amount' => __('payments.edit_exceeds_due')]);
            } catch (InvalidArgumentException) {
                throw ValidationException::withMessages(['category' => __('payments.edit_category')]);
            }

            TransactionCorrection::query()->create([
                'kind' => TransactionCorrection::EDIT, 'original_transaction_id' => $entry->id, 'reversal_transaction_id' => $reversal->id,
                'replacement_transaction_id' => $replacement->id, 'changes' => $changes, 'reason' => $reason, 'staff_id' => $by->id,
            ]);
            $this->audit->record('cash.edited', $by, $entry, ['replacement' => $replacement->id, 'reversal' => $reversal->id, 'changes' => $changes, 'reason' => $reason]);

            return $replacement;
        });
    }

    /** @return array{date: string, account: ?string, category: string, business_line: ?string, description: string, reference: ?string, amount: float|int} */
    public static function values(Transaction $entry): array
    {
        $account = $entry->moneyAccount?->code ?? (LedgerService::METHOD_ACCOUNTS[$entry->method] ?? null);

        return [
            'date' => $entry->occurred_at->timezone('Asia/Dhaka')->toDateString(),
            'account' => $account,
            'category' => $entry->category,
            'business_line' => $entry->business_line,
            'description' => (string) $entry->description,
            'reference' => $entry->reference_label ?? $entry->external_ref,
            'amount' => Money::toNumber($entry->amount),
        ];
    }
}
