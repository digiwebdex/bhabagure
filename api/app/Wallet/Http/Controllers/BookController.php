<?php

namespace App\Wallet\Http\Controllers;

use App\Wallet\Models\Deal;
use App\Wallet\Models\ReferencePreset;
use App\Wallet\Models\Source;
use App\Wallet\Models\Transaction;
use App\Wallet\Services\EvidenceVault;
use App\Wallet\Services\WalletBook;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * The wallet screen's data (docs/phase-7-hr-attendance-bonus-wallet.md §8): summary and breakdown, cash in and out,
 * history with reversal, evidence, deals and their payments, sources and saved references.
 */
final class BookController extends WalletController
{
    public function summary(WalletBook $book): JsonResponse
    {
        return response()->json(['data' => $book->summary()]);
    }

    public function history(Request $request): JsonResponse
    {
        $direction = $request->validate(['direction' => ['nullable', Rule::in(['all', 'in', 'out'])], 'page' => ['nullable', 'integer', 'min:1']])['direction'] ?? 'all';
        $page = Transaction::query()->with(['deal:id,name', 'reversedBy:id,reverses_id'])
            ->when($direction !== 'all', fn ($query) => $query->where('direction', $direction))
            ->orderByDesc('occurred_on')->orderByDesc('id')->paginate(50);

        return response()->json([
            'data' => collect($page->items())->map(fn (Transaction $entry) => self::row($entry))->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function record(Request $request, WalletBook $book): JsonResponse
    {
        $data = $request->validate([
            'direction' => ['required', Rule::in([Transaction::IN, Transaction::OUT])],
            'amount' => ['required', 'numeric', 'min:1', 'max:999999999'],
            'source_id' => ['required', 'integer'],
            'reference' => ['required', 'string', 'min:2', 'max:300'],
            'occurred_on' => self::dateRule(),
            'evidence' => EvidenceVault::rules(),
        ]);

        return $this->attempt(fn () => response()->json(['data' => self::row($book->record(
            $data['direction'], (float) $data['amount'], (int) $data['source_id'], trim($data['reference']), $data['occurred_on'] ?? self::today(), $request->file('evidence'), $this->staff($request),
        ))], 201));
    }

    public function reverse(Request $request, int $id, WalletBook $book): JsonResponse
    {
        $entry = Transaction::query()->findOrFail($id);
        $reason = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:300']])['reason'];

        return $this->attempt(fn () => response()->json(['data' => self::row($book->reverse($entry, $reason, $this->staff($request)))], 201));
    }

    public function evidence(int $id, EvidenceVault $vault): Response
    {
        $entry = Transaction::query()->whereNotNull('evidence_path')->findOrFail($id);
        $bytes = $vault->read($entry->evidence_path);
        $extension = pathinfo((string) $entry->evidence_name, PATHINFO_EXTENSION) ?: 'bin';

        return response($bytes, 200, [
            'Content-Type' => $entry->evidence_mime ?: 'application/octet-stream',
            'Content-Disposition' => "inline; filename=\"evidence-{$entry->id}.{$extension}\"",
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'",
        ]);
    }

    public function deals(): JsonResponse
    {
        return response()->json(['data' => Deal::query()->with(['transactions' => fn ($query) => $query->with('reversedBy:id,reverses_id')->orderBy('id')])->latest('id')->get()
            ->map(fn (Deal $deal) => self::dealRow($deal))->all()]);
    }

    public function createDeal(Request $request, WalletBook $book): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'total' => ['required', 'numeric', 'min:1', 'max:999999999'],
            'advance' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'note' => ['nullable', 'string', 'max:300'],
            'reference' => ['nullable', 'string', 'max:300'],
            'occurred_on' => self::dateRule(),
            'evidence' => EvidenceVault::rules(),
        ]);

        return $this->attempt(function () use ($data, $request, $book) {
            $deal = $book->createDeal(trim($data['name']), (float) $data['total'], (float) ($data['advance'] ?? 0), filled($data['note'] ?? null) ? trim($data['note']) : null,
                filled($data['reference'] ?? null) ? trim($data['reference']) : __('wallet.advance'), $data['occurred_on'] ?? self::today(), $request->file('evidence'), $this->staff($request));

            return response()->json(['data' => self::dealRow($deal->load(['transactions.reversedBy']))], 201);
        });
    }

    public function recordPayment(Request $request, int $id, WalletBook $book): JsonResponse
    {
        $deal = Deal::query()->findOrFail($id);
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:1', 'max:999999999'],
            'reference' => ['nullable', 'string', 'max:300'],
            'occurred_on' => self::dateRule(),
            'evidence' => EvidenceVault::rules(),
        ]);

        return $this->attempt(function () use ($deal, $data, $request, $book) {
            $book->recordPayment($deal, (float) $data['amount'], filled($data['reference'] ?? null) ? trim($data['reference']) : __('wallet.due_payment'),
                $data['occurred_on'] ?? self::today(), $request->file('evidence'), $this->staff($request));

            return response()->json(['data' => self::dealRow($deal->fresh(['transactions.reversedBy']))], 201);
        });
    }

    public function sources(): JsonResponse
    {
        return response()->json(['data' => Source::query()->active()->get(['id', 'name'])]);
    }

    public function addSource(Request $request): JsonResponse
    {
        $name = trim($request->validate(['name' => ['required', 'string', 'min:2', 'max:120']])['name']);
        $source = Source::query()->where('name', $name)->first();
        if ($source !== null && $source->archived_at === null) {
            return response()->json(['message' => __('wallet.source_exists'), 'code' => 'source_exists'], 409);
        }
        $source ??= new Source(['name' => $name, 'sort_order' => 100]);
        $source->forceFill(['archived_at' => null])->save();

        return response()->json(['data' => Source::query()->active()->get(['id', 'name'])], 201);
    }

    /** Hidden from the form; entries already made keep its name. */
    public function archiveSource(int $id): JsonResponse
    {
        Source::query()->findOrFail($id)->forceFill(['archived_at' => now()])->save();

        return response()->json(['data' => Source::query()->active()->get(['id', 'name'])]);
    }

    public function presets(): JsonResponse
    {
        return response()->json(['data' => ReferencePreset::query()->orderBy('id')->get(['id', 'direction', 'label'])]);
    }

    public function addPreset(Request $request): JsonResponse
    {
        $data = $request->validate(['direction' => ['required', Rule::in([Transaction::IN, Transaction::OUT])], 'label' => ['required', 'string', 'min:2', 'max:120']]);
        ReferencePreset::query()->firstOrCreate(['direction' => $data['direction'], 'label' => trim($data['label'])]);

        return response()->json(['data' => ReferencePreset::query()->orderBy('id')->get(['id', 'direction', 'label'])], 201);
    }

    public function removePreset(int $id): JsonResponse
    {
        ReferencePreset::query()->whereKey($id)->delete();

        return response()->json(['data' => ReferencePreset::query()->orderBy('id')->get(['id', 'direction', 'label'])]);
    }

    /** @return array<string, mixed> */
    public static function row(Transaction $entry): array
    {
        return [
            'id' => $entry->id,
            'direction' => $entry->direction,
            'amount' => (float) $entry->amount,
            'kind' => $entry->kind,
            'source' => $entry->source_name,
            'deal' => $entry->deal_id ? ['id' => $entry->deal_id, 'name' => $entry->relationLoaded('deal') ? $entry->deal?->name : null] : null,
            'reference' => $entry->reference,
            'reason' => $entry->reason,
            'occurred_on' => $entry->occurred_on->toDateString(),
            'has_evidence' => $entry->evidence_path !== null,
            'reverses_id' => $entry->reverses_id,
            'reversed' => $entry->relationLoaded('reversedBy') && $entry->reversedBy !== null,
            'created_at' => $entry->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function dealRow(Deal $deal): array
    {
        $received = round($deal->transactions->filter(fn (Transaction $entry) => $entry->kind !== Transaction::REVERSAL && $entry->reversedBy === null)->sum(fn (Transaction $entry) => (float) $entry->amount), 2);
        $due = max(0.0, round((float) $deal->total - $received, 2));

        return [
            'id' => $deal->id,
            'name' => $deal->name,
            'total' => (float) $deal->total,
            'note' => $deal->note,
            'received' => $received,
            'due' => $due,
            'settled' => $due <= 0,
            'created_at' => $deal->created_at?->toIso8601String(),
            'payments' => $deal->transactions->map(fn (Transaction $entry) => [
                'id' => $entry->id, 'kind' => $entry->kind, 'amount' => (float) $entry->amount, 'reference' => $entry->reference,
                'occurred_on' => $entry->occurred_on->toDateString(), 'reversed' => $entry->reversedBy !== null,
            ])->values()->all(),
        ];
    }

    /** @return array<int, string> */
    private static function dateRule(): array
    {
        return ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.self::today()];
    }

    private static function today(): string
    {
        return CarbonImmutable::now('Asia/Dhaka')->toDateString();
    }
}
