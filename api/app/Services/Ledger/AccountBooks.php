<?php

namespace App\Services\Ledger;

use App\Models\Account;
use App\Models\JournalLine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * What the books say (docs/phase-9-accounts.md §4): every figure on the Accounts screens is read from the journal, so
 * the chart of accounts, the account's own transactions and the general ledger can never disagree with each other.
 *
 * An account's balance is its own way round: assets and expenses rise on the debit side, liabilities, income and
 * equity on the credit side, so a positive balance always means "more of what this account is for".
 */
final class AccountBooks
{
    /** Credit-natured kinds: their balance is credits − debits. */
    public const CREDIT_NATURED = ['liability', 'income', 'equity'];

    /**
     * The chart of accounts with each account's balance and how many entries it carries.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function chart(?string $to = null): Collection
    {
        $sums = $this->sums(to: $to);

        return Account::query()->orderByRaw('FIELD(type, ?, ?, ?, ?, ?)', Account::TYPES)->orderBy('code')->get()
            ->map(function (Account $account) use ($sums) {
                $row = $sums[$account->id] ?? ['debit' => 0.0, 'credit' => 0.0, 'entries' => 0, 'last_entry_on' => null];

                return [
                    'id' => $account->id,
                    'code' => $account->code,
                    'name' => $account->name_en,
                    'name_bn' => $account->name_bn,
                    'type' => $account->type,
                    // The section the chart reads it under (docs/phase-9-accounts.md §2).
                    'group' => $account->group,
                    'description' => $account->description,
                    'is_system' => $account->is_system,
                    'is_money' => $account->isMoney(),
                    'archived_at' => $account->archived_at?->toIso8601String(),
                    'debit' => round($row['debit'], 2),
                    'credit' => round($row['credit'], 2),
                    'balance' => self::balance($account->type, $row['debit'], $row['credit']),
                    'entries' => (int) $row['entries'],
                    // When the account was last used, which is how the chart says whether it is live or forgotten.
                    'last_entry_on' => $row['last_entry_on'],
                ];
            });
    }

    /**
     * One account's entries in a period, with the balance carried forward from before it.
     *
     * @return array{opening: float, closing: float, rows: list<array<string, mixed>>}
     */
    public function accountTransactions(Account $account, ?string $from = null, ?string $to = null): array
    {
        $before = $from === null ? ['debit' => 0.0, 'credit' => 0.0] : ($this->sums(to: Carbon::parse($from)->subDay()->toDateString(), account: $account)[$account->id] ?? ['debit' => 0.0, 'credit' => 0.0]);
        $opening = self::balance($account->type, $before['debit'], $before['credit']);
        $sign = in_array($account->type, self::CREDIT_NATURED, true) ? -1 : 1;

        $running = $opening;
        $rows = $this->lines($from, $to)->where('journal_lines.account_id', $account->id)
            ->orderBy('journal_entries.entry_date')->orderBy('journal_entries.id')->orderBy('journal_lines.id')
            ->get(['journal_lines.id', 'journal_lines.debit', 'journal_lines.credit', 'journal_entries.id as entry_id', 'journal_entries.entry_date', 'journal_entries.description', 'journal_entries.source_type', 'journal_entries.booking_id', 'journal_entries.reverses_journal_entry_id'])
            ->map(function ($line) use (&$running, $sign) {
                $running = round($running + $sign * ((float) $line->debit - (float) $line->credit), 2);

                return [
                    'entry_id' => (int) $line->entry_id,
                    'date' => $line->entry_date,
                    'description' => $line->description,
                    'source' => $line->source_type,
                    'booking_id' => $line->booking_id ? (int) $line->booking_id : null,
                    'is_reversal' => $line->reverses_journal_entry_id !== null,
                    'debit' => (float) $line->debit,
                    'credit' => (float) $line->credit,
                    'balance' => $running,
                ];
            })->all();

        return ['opening' => $opening, 'closing' => $running, 'rows' => $rows];
    }

    /**
     * The general ledger: one row per account with its movement in the period, and the totals that must match.
     *
     * @return array{rows: list<array<string, mixed>>, totals: array{debit: float, credit: float}}
     */
    public function trialBalance(?string $from = null, ?string $to = null): array
    {
        $sums = $this->sums($from, $to);
        $rows = Account::query()->orderByRaw('FIELD(type, ?, ?, ?, ?, ?)', Account::TYPES)->orderBy('code')->get()
            ->map(fn (Account $account) => [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name_en,
                'type' => $account->type,
                'debit' => round($sums[$account->id]['debit'] ?? 0, 2),
                'credit' => round($sums[$account->id]['credit'] ?? 0, 2),
                'balance' => self::balance($account->type, $sums[$account->id]['debit'] ?? 0, $sums[$account->id]['credit'] ?? 0),
            ])
            ->filter(fn (array $row) => $row['debit'] != 0.0 || $row['credit'] != 0.0)
            ->values()->all();

        return [
            'rows' => $rows,
            'totals' => ['debit' => round(array_sum(array_column($rows, 'debit')), 2), 'credit' => round(array_sum(array_column($rows, 'credit')), 2)],
        ];
    }

    /** An account's balance in its own direction. */
    public static function balance(string $type, float|string $debit, float|string $credit): float
    {
        $difference = (float) $debit - (float) $credit;

        return round(in_array($type, self::CREDIT_NATURED, true) ? -$difference : $difference, 2);
    }

    /**
     * Debits, credits and entry counts per account id.
     *
     * @return array<int, array{debit: float, credit: float, entries: int}>
     */
    private function sums(?string $from = null, ?string $to = null, ?Account $account = null): array
    {
        return $this->lines($from, $to)
            ->when($account !== null, fn (Builder $query) => $query->where('journal_lines.account_id', $account->id))
            ->groupBy('journal_lines.account_id')
            ->selectRaw('journal_lines.account_id, SUM(journal_lines.debit) AS debit, SUM(journal_lines.credit) AS credit, COUNT(DISTINCT journal_lines.journal_entry_id) AS entries, MAX(journal_entries.entry_date) AS last_entry_on')
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->account_id => [
                'debit' => (float) $row->debit, 'credit' => (float) $row->credit, 'entries' => (int) $row->entries, 'last_entry_on' => $row->last_entry_on,
            ]])
            ->all();
    }

    /** @return Builder<JournalLine> */
    private function lines(?string $from, ?string $to): Builder
    {
        return JournalLine::query()->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->when($from !== null, fn (Builder $query) => $query->where('journal_entries.entry_date', '>=', $from))
            ->when($to !== null, fn (Builder $query) => $query->where('journal_entries.entry_date', '<=', $to));
    }
}
