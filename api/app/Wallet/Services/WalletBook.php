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
 * The wallet's book (docs/phase-7-hr-attendance-bonus-wallet.md §8): cash in and out with a source, a reference and
 * evidence; deals with an advance and payments against what is due; reversals instead of deletes. Balance = in − out.
 *
 * "Standing" entries are the ones that count in totals and breakdowns: not reversals, and not reversed.
 */
final class WalletBook
{
    public function __construct(private readonly EvidenceVault $vault) {}

    /** @return array<string, mixed> */
    public function summary(): array
    {
        $monthStart = CarbonImmutable::now('Asia/Dhaka')->startOfMonth()->toDateString();
        $standing = fn () => self::standing(Transaction::query());
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
            'last_entry_at' => Transaction::query()->max('created_at'),
            'by_source' => $standing()->groupBy('source_name')
                ->selectRaw("source_name, sum(case when direction = 'in' then amount else 0 end) as total_in, sum(case when direction = 'out' then amount else 0 end) as total_out")
                ->orderByRaw('total_in desc')->get()
                ->map(fn ($row) => ['name' => $row->source_name, 'in' => round((float) $row->total_in, 2), 'out' => round((float) $row->total_out, 2)])->all(),
            'due_total' => round(Deal::query()->get()->sum(fn (Deal $deal) => self::due($deal)), 2),
        ];
    }

    /**
     * Cash in or out.
     *
     * @throws WalletRefused
     */
    public function record(string $direction, float $amount, int $sourceId, string $reference, string $occurredOn, ?UploadedFile $evidence, Staff $by): Transaction
    {
        $source = Source::query()->whereNull('archived_at')->find($sourceId) ?? throw new WalletRefused('source_unknown', 422);

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
     * Money received against what a deal still has due.
     *
     * @throws WalletRefused
     */
    public function recordPayment(Deal $deal, float $amount, string $reference, string $occurredOn, ?UploadedFile $evidence, Staff $by): Transaction
    {
        return $this->withEvidence($evidence, fn (?array $file) => DB::connection('wallet')->transaction(function () use ($deal, $amount, $reference, $occurredOn, $file, $by) {
            $locked = Deal::query()->whereKey($deal->id)->lockForUpdate()->firstOrFail();
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

    /**
     * Undoes an entry with one the other way. A deal's advance or payment reversed is due again.
     *
     * @throws WalletRefused
     */
    public function reverse(Transaction $entry, string $reason, Staff $by): Transaction
    {
        return DB::connection('wallet')->transaction(function () use ($entry, $reason, $by) {
            $locked = Transaction::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();
            if ($locked->kind === Transaction::REVERSAL) {
                throw new WalletRefused('not_reversible');
            }
            if (Transaction::query()->where('reverses_id', $locked->id)->exists()) {
                throw new WalletRefused('already_reversed');
            }
            $reversal = Transaction::query()->create([
                'direction' => $locked->direction === Transaction::IN ? Transaction::OUT : Transaction::IN,
                'amount' => $locked->amount, 'kind' => Transaction::REVERSAL, 'source_id' => $locked->source_id, 'source_name' => $locked->source_name,
                'deal_id' => $locked->deal_id, 'reference' => $locked->reference, 'occurred_on' => CarbonImmutable::now('Asia/Dhaka')->toDateString(),
                'reverses_id' => $locked->id, 'reason' => $reason, 'created_by_staff_id' => $by->id,
            ]);
            AuditLog::record('entry_reversed', $by->id, $locked, ['reversal' => $reversal->id, 'reason' => $reason]);

            return $reversal;
        });
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

    /** Not a reversal, and not reversed. */
    public static function standing(Builder $query): Builder
    {
        return $query->where('kind', '!=', Transaction::REVERSAL)->whereDoesntHave('reversedBy');
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
