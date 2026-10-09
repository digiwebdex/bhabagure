<?php

namespace App\Wallet\Services;

use App\Models\Staff;
use App\Wallet\Models\AuditLog;
use App\Wallet\Models\Deal;
use App\Wallet\Models\Source;
use App\Wallet\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * One person's wallet book (docs/phase-7-hr-attendance-bonus-wallet.md §8; one per person since 2026-10-09,
 * docs/personal-wallets.md — every read and write is limited to the signed-in owner's own rows): cash in and out with a
 * source, a reference and evidence; deals with an advance and payments against what is due. Balance = in − out.
 *
 * Edit and Delete (docs/wallet-edits.md) keep the ledger append-only: Delete cancels an entry with a reversal on its own
 * date, and Edit does the same and records the corrected entry in its place. "Standing" entries are the ones that count
 * in totals and breakdowns: not reversals, and not cancelled.
 */
final class WalletBook
{
    public function __construct(private readonly EvidenceVault $vault) {}

    /**
     * One person's figures (docs/personal-wallets.md): only their own entries and deals.
     *
     * @return array<string, mixed>
     */
    public function summary(Staff $owner): array
    {
        $monthStart = CarbonImmutable::now('Asia/Dhaka')->startOfMonth()->toDateString();
        $standing = fn () => self::standing(Transaction::query()->ownedBy($owner->id));
        $sum = fn (Builder $query) => round((float) $query->sum('amount'), 2);

        $in = $sum($standing()->where('direction', Transaction::IN));
        $out = $sum($standing()->where('direction', Transaction::OUT));

        return [
            'balance' => round($in - $out, 2),
            'total_in' => $in,
            'total_out' => $out,
            'count_in' => $standing()->where('direction', Transaction::IN)->count(),
            'count_out' => $standing()->where('direction', Transaction::OUT)->count(),
            'month_in' => $sum($standing()->where('direction', Transaction::IN)->where('occurred_on', '>=', $monthStart)),
            'last_entry_at' => Transaction::query()->ownedBy($owner->id)->max('created_at'),
            'by_source' => self::bySource($owner),
            'due_total' => round(Deal::query()->ownedBy($owner->id)->notDeleted()->get()->sum(fn (Deal $deal) => self::due($deal)), 2),
        ];
    }

    /**
     * Cash in or out.
     *
     * @throws WalletRefused
     */
    public function record(string $direction, float $amount, int $sourceId, string $reference, string $occurredOn, ?UploadedFile $evidence, Staff $by): Transaction
    {
        // Only one of this person's own sources.
        $source = Source::query()->ownedBy($by->id)->whereNull('archived_at')->find($sourceId) ?? throw new WalletRefused('source_unknown', 422);

        return $this->withEvidence($evidence, fn (?array $file) => DB::connection('wallet')->transaction(function () use ($direction, $amount, $source, $reference, $occurredOn, $file, $by) {
            $entry = Transaction::query()->create([
                'direction' => $direction, 'amount' => $amount, 'kind' => Transaction::ENTRY, 'source_id' => $source->id, 'source_name' => $source->name,
                'reference' => $reference, 'occurred_on' => $occurredOn, 'created_by_staff_id' => $by->id,
            ] + self::evidenceColumns($file));
            AuditLog::record('entry_recorded', $by->id, $entry, ['direction' => $direction, 'amount' => $amount]);

            return $entry;
        }));
    }

    /**
     * Edit (docs/wallet-edits.md): the entry is cancelled by a reversal on its own date and the corrected one recorded in
     * its place, pointing back at it. Evidence is kept unless a new file replaces it or it is removed; the old file stays
     * with the old version. A deal's advance or payment stays cash in under its deal, and can't go above what the deal
     * has due with its own amount counted back in.
     *
     * @param  array{direction: string|null, amount: float, source_id: int|null, reference: string, occurred_on: string}  $values
     *
     * @throws WalletRefused
     */
    public function edit(Transaction $entry, array $values, ?UploadedFile $evidence, bool $removeEvidence, ?string $reason, Staff $by): Transaction
    {
        return $this->withEvidence($evidence, fn (?array $file) => DB::connection('wallet')->transaction(function () use ($entry, $values, $file, $removeEvidence, $reason, $by) {
            // The deal first, then its entry: the order deleteDeal() locks them in, so the two never wait on each other.
            $deal = $entry->deal_id !== null ? Deal::query()->ownedBy($by->id)->whereKey($entry->deal_id)->lockForUpdate()->firstOrFail() : null;
            $original = self::lockStanding($entry, $by);
            $fields = ['amount' => $values['amount'], 'reference' => $values['reference'], 'occurred_on' => $values['occurred_on']]
                + ($file !== null || $removeEvidence ? self::evidenceColumns($file) : [
                    'evidence_path' => $original->evidence_path, 'evidence_mime' => $original->evidence_mime, 'evidence_name' => $original->evidence_name,
                ]);

            if ($deal !== null) {
                if ($values['amount'] > self::due($deal) + (float) $original->amount) {
                    throw new WalletRefused('payment_over_due', 422);
                }
                $fields += ['direction' => $original->direction, 'source_id' => null, 'source_name' => $deal->name];
            } else {
                $source = self::sourceFor($values['source_id'] ?? $original->source_id, $original, $by);
                $fields += ['direction' => $values['direction'] ?? $original->direction, 'source_id' => $source->id, 'source_name' => $source->name];
            }

            if (self::sameAs($original, $fields)) {
                throw new WalletRefused('nothing_changed', 422);
            }

            self::cancel($original, $reason, $by);
            $corrected = Transaction::query()->create($fields + [
                'kind' => $original->kind, 'deal_id' => $original->deal_id, 'replaces_id' => $original->id, 'created_by_staff_id' => $by->id,
            ]);
            AuditLog::record('entry_edited', $by->id, $original, ['corrected' => $corrected->id, 'reason' => $reason]);

            return $corrected;
        }));
    }

    /**
     * Delete (docs/wallet-edits.md): the entry is cancelled by a reversal on its own date, so it leaves the balance and
     * the list; "Show edits and deletions" still shows it. A deal's advance or payment deleted is due again.
     *
     * @throws WalletRefused
     */
    public function delete(Transaction $entry, ?string $reason, Staff $by): Transaction
    {
        return DB::connection('wallet')->transaction(function () use ($entry, $reason, $by) {
            $original = self::lockStanding($entry, $by);
            $reversal = self::cancel($original, $reason, $by);
            AuditLog::record('entry_deleted', $by->id, $original, ['direction' => $original->direction, 'amount' => (float) $original->amount, 'reason' => $reason]);

            return $reversal;
        });
    }

    /**
     * A deal, and its advance as cash in when there is one.
     *
     * @throws WalletRefused
     */
    public function createDeal(string $name, float $total, float $advance, ?string $note, string $reference, string $occurredOn, ?UploadedFile $evidence, Staff $by): Deal
    {
        if ($advance > $total) {
            throw new WalletRefused('advance_over_total', 422);
        }

        return $this->withEvidence($evidence, fn (?array $file) => DB::connection('wallet')->transaction(function () use ($name, $total, $advance, $note, $reference, $occurredOn, $file, $by) {
            $deal = Deal::query()->create(['name' => $name, 'total' => $total, 'note' => $note, 'created_by_staff_id' => $by->id]);
            if ($advance > 0) {
                Transaction::query()->create([
                    'direction' => Transaction::IN, 'amount' => $advance, 'kind' => Transaction::DEAL_ADVANCE, 'source_name' => $name, 'deal_id' => $deal->id,
                    'reference' => $reference, 'occurred_on' => $occurredOn, 'created_by_staff_id' => $by->id,
                ] + self::evidenceColumns($file));
            }
            AuditLog::record('deal_created', $by->id, $deal, ['total' => $total, 'advance' => $advance]);

            return $deal;
        }));
    }

    /**
     * A deal's name, total and note, changed in place (a deal is not ledger, docs/wallet-edits.md); its money shows under
     * the new name everywhere. The total can't go below what the deal has received.
     *
     * @throws WalletRefused
     */
    public function editDeal(Deal $deal, string $name, float $total, ?string $note, ?string $reason, Staff $by): Deal
    {
        return DB::connection('wallet')->transaction(function () use ($deal, $name, $total, $note, $reason, $by) {
            $locked = Deal::query()->ownedBy($by->id)->notDeleted()->whereKey($deal->id)->lockForUpdate()->firstOrFail();
            if (round($total, 2) < self::received($locked)) {
                throw new WalletRefused('total_below_received', 422);
            }
            $changes = array_filter([
                'name' => [$locked->name, $name],
                'total' => [round((float) $locked->total, 2), round($total, 2)],
                'note' => [$locked->note, $note],
            ], fn (array $pair) => $pair[0] !== $pair[1]);
            if ($changes === []) {
                throw new WalletRefused('nothing_changed', 422);
            }
            $locked->forceFill(['name' => $name, 'total' => $total, 'note' => $note])->save();
            AuditLog::record('deal_edited', $by->id, $locked, ['changes' => $changes, 'reason' => $reason]);

            return $locked;
        });
    }

    /**
     * Delete a deal (docs/wallet-edits.md): it leaves the list and the total due, and its advance and payments are
     * cancelled with it, so that money leaves the balance too.
     *
     * @return float what left the balance with it
     *
     * @throws WalletRefused
     */
    public function deleteDeal(Deal $deal, ?string $reason, Staff $by): float
    {
        return DB::connection('wallet')->transaction(function () use ($deal, $reason, $by) {
            $locked = Deal::query()->ownedBy($by->id)->notDeleted()->whereKey($deal->id)->lockForUpdate()->firstOrFail();
            $money = self::standing(Transaction::query()->ownedBy($by->id))->where('deal_id', $locked->id)->orderBy('id')->lockForUpdate()->get();
            foreach ($money as $entry) {
                self::cancel($entry, $reason, $by);
            }
            $locked->forceFill(['deleted_at' => now(), 'deleted_reason' => $reason])->save();
            $removed = round((float) $money->sum(fn (Transaction $entry) => (float) $entry->amount), 2);
            AuditLog::record('deal_deleted', $by->id, $locked, ['total' => round((float) $locked->total, 2), 'removed' => $removed, 'reason' => $reason]);

            return $removed;
        });
    }

    /**
     * Money received against what a deal still has due.
     *
     * @throws WalletRefused
     */
    public function recordPayment(Deal $deal, float $amount, string $reference, string $occurredOn, ?UploadedFile $evidence, Staff $by): Transaction
    {
        return $this->withEvidence($evidence, fn (?array $file) => DB::connection('wallet')->transaction(function () use ($deal, $amount, $reference, $occurredOn, $file, $by) {
            $locked = Deal::query()->ownedBy($by->id)->notDeleted()->whereKey($deal->id)->lockForUpdate()->firstOrFail();
            if ($amount > self::due($locked)) {
                throw new WalletRefused('payment_over_due', 422);
            }
            $payment = Transaction::query()->create([
                'direction' => Transaction::IN, 'amount' => $amount, 'kind' => Transaction::DEAL_PAYMENT, 'source_name' => $locked->name, 'deal_id' => $locked->id,
                'reference' => $reference, 'occurred_on' => $occurredOn, 'created_by_staff_id' => $by->id,
            ] + self::evidenceColumns($file));
            AuditLog::record('deal_payment_recorded', $by->id, $payment, ['deal' => $locked->id, 'amount' => $amount]);

            return $payment;
        }));
    }

    /** What a deal has received: its standing advance and payments. */
    public static function received(Deal $deal): float
    {
        return round((float) self::standing(Transaction::query())->where('deal_id', $deal->id)->where('direction', Transaction::IN)->sum('amount'), 2);
    }

    public static function due(Deal $deal): float
    {
        return max(0.0, round((float) $deal->total - self::received($deal), 2));
    }

    /** Not a reversal, and not cancelled (edited or deleted). */
    public static function standing(Builder $query): Builder
    {
        return $query->where('kind', '!=', Transaction::REVERSAL)->whereDoesntHave('reversedBy');
    }

    /**
     * What came in from and went out to each source or deal, under the name it has now (renamed since or not).
     *
     * @return list<array{name: string, in: float, out: float}>
     */
    private static function bySource(Staff $owner): array
    {
        $groups = self::standing(Transaction::query()->ownedBy($owner->id))
            ->groupBy('source_id', 'deal_id', 'source_name')
            ->selectRaw("source_id, deal_id, source_name, sum(case when direction = 'in' then amount else 0 end) as total_in, sum(case when direction = 'out' then amount else 0 end) as total_out")
            ->get();
        $sources = Source::query()->ownedBy($owner->id)->whereIn('id', $groups->pluck('source_id')->filter()->unique()->all())->pluck('name', 'id');
        $deals = Deal::query()->ownedBy($owner->id)->whereIn('id', $groups->pluck('deal_id')->filter()->unique()->all())->pluck('name', 'id');

        return $groups
            ->groupBy(fn (Transaction $row) => (string) ($row->deal_id !== null ? ($deals[$row->deal_id] ?? $row->source_name) : ($sources[$row->source_id] ?? $row->source_name)))
            ->map(fn ($rows, $name) => ['name' => (string) $name, 'in' => round((float) $rows->sum('total_in'), 2), 'out' => round((float) $rows->sum('total_out'), 2)])
            ->sortByDesc('in')->values()->all();
    }

    /**
     * One of the owner's entries, locked, that still stands: not a reversal, and not already edited or deleted.
     *
     * @throws WalletRefused
     */
    private static function lockStanding(Transaction $entry, Staff $by): Transaction
    {
        $locked = Transaction::query()->ownedBy($by->id)->whereKey($entry->id)->lockForUpdate()->firstOrFail();
        if ($locked->kind === Transaction::REVERSAL) {
            throw new WalletRefused('not_changeable');
        }
        if (Transaction::query()->where('reverses_id', $locked->id)->exists()) {
            throw new WalletRefused('already_changed');
        }

        return $locked;
    }

    /** The reversal that cancels an entry, dated as the entry so its month shows the figure as it now stands. */
    private static function cancel(Transaction $entry, ?string $reason, Staff $by): Transaction
    {
        return Transaction::query()->create([
            'direction' => $entry->direction === Transaction::IN ? Transaction::OUT : Transaction::IN,
            'amount' => $entry->amount, 'kind' => Transaction::REVERSAL, 'source_id' => $entry->source_id, 'source_name' => $entry->source_name,
            'deal_id' => $entry->deal_id, 'reference' => $entry->reference, 'occurred_on' => $entry->occurred_on->toDateString(),
            'reverses_id' => $entry->id, 'reason' => $reason, 'created_by_staff_id' => $by->id,
        ]);
    }

    /**
     * An edited entry's source: the one it has (even if deleted from the list since), or another of the owner's listed
     * ones.
     *
     * @throws WalletRefused
     */
    private static function sourceFor(?int $sourceId, Transaction $original, Staff $by): Source
    {
        $query = Source::query()->ownedBy($by->id);
        if ($sourceId !== (int) $original->source_id) {
            $query->whereNull('archived_at');
        }

        return ($sourceId === null ? null : $query->find($sourceId)) ?? throw new WalletRefused('source_unknown', 422);
    }

    /** @param array<string, mixed> $fields */
    private static function sameAs(Transaction $entry, array $fields): bool
    {
        return $entry->direction === $fields['direction']
            && round((float) $entry->amount, 2) === round((float) $fields['amount'], 2)
            && (int) $entry->source_id === (int) $fields['source_id']
            && $entry->reference === $fields['reference']
            && $entry->occurred_on->toDateString() === $fields['occurred_on']
            && $entry->evidence_path === $fields['evidence_path'];
    }

    /**
     * Stores the evidence first, and removes it again if the entry isn't written.
     *
     * @template T
     *
     * @param  callable(array{path: string, mime: string, name: string}|null): T  $write
     * @return T
     */
    private function withEvidence(?UploadedFile $evidence, callable $write): mixed
    {
        $file = $evidence ? $this->vault->put($evidence) : null;
        try {
            return $write($file);
        } catch (Throwable $e) {
            $this->vault->forget($file['path'] ?? null);
            throw $e;
        }
    }

    /**
     * @param  array{path: string, mime: string, name: string}|null  $file
     * @return array<string, string|null>
     */
    private static function evidenceColumns(?array $file): array
    {
        return ['evidence_path' => $file['path'] ?? null, 'evidence_mime' => $file['mime'] ?? null, 'evidence_name' => $file['name'] ?? null];
    }
}
