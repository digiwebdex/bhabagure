<?php

namespace App\Wallet\Http\Controllers;

use App\Wallet\Http\EntryRows;
use App\Wallet\Models\AuditLog;
use App\Wallet\Models\ReferencePreset;
use App\Wallet\Models\Source;
use App\Wallet\Models\Transaction;
use App\Wallet\Services\EvidenceVault;
use App\Wallet\Services\WalletBook;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * The wallet screen's data (docs/phase-7-hr-attendance-bonus-wallet.md §8): summary and breakdown, cash in and out,
 * history, each entry with its versions, Edit and Delete (docs/wallet-edits.md), evidence, sources and saved
 * references; deals are DealController. Everything is the signed-in person's own (docs/personal-wallets.md): another
 * wallet's ids answer 404, for the super admin too.
 */
final class BookController extends WalletController
{
    public function summary(Request $request, WalletBook $book): JsonResponse
    {
        return response()->json(['data' => $book->summary($this->staff($request))]);
    }

    /** Newest first, as the book stands; with `history`, edited and deleted entries too. Reversals are never rows. */
    public function history(Request $request): JsonResponse
    {
        $filters = $request->validate(['direction' => ['nullable', Rule::in(['all', 'in', 'out'])], 'page' => ['nullable', 'integer', 'min:1'], 'history' => ['nullable', 'boolean']]);
        $direction = $filters['direction'] ?? 'all';
        $query = Transaction::query()->ownedBy($this->staff($request)->id)->with(EntryRows::WITH)->where('kind', '!=', Transaction::REVERSAL);
        $page = ($request->boolean('history') ? $query : WalletBook::standing($query))
            ->when($direction !== 'all', fn ($query) => $query->where('direction', $direction))
            ->orderByDesc('occurred_on')->orderByDesc('id')->paginate(50);

        return response()->json([
            'data' => collect($page->items())->map(fn (Transaction $entry) => EntryRows::row($entry))->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    /** One entry, with every version of it (View). */
    public function entry(Request $request, int $id): JsonResponse
    {
        $owner = $this->staff($request)->id;
        $entry = Transaction::query()->ownedBy($owner)->where('kind', '!=', Transaction::REVERSAL)->with(EntryRows::WITH)->findOrFail($id);

        return response()->json(['data' => EntryRows::withVersions($entry, $owner)]);
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

        return $this->attempt(fn () => response()->json(['data' => EntryRows::row($book->record(
            $data['direction'], (float) $data['amount'], (int) $data['source_id'], trim($data['reference']), $data['occurred_on'] ?? self::today(), $request->file('evidence'), $this->staff($request),
        )->load(EntryRows::WITH))], 201));
    }

    /** Edit (docs/wallet-edits.md): answers with the corrected entry, which takes the old one's place. */
    public function edit(Request $request, int $id, WalletBook $book): JsonResponse
    {
        $entry = Transaction::query()->ownedBy($this->staff($request)->id)->findOrFail($id);
        $data = $request->validate([
            // Cash in or out, and the source, only for an ordinary entry: a deal's money stays under its deal.
            'direction' => ['nullable', Rule::in([Transaction::IN, Transaction::OUT])],
            'amount' => ['required', 'numeric', 'min:1', 'max:999999999'],
            'source_id' => ['nullable', 'integer'],
            'reference' => ['required', 'string', 'min:2', 'max:300'],
            'occurred_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.self::today()],
            'evidence' => EvidenceVault::rules(),
            'remove_evidence' => ['nullable', 'boolean'],
            'reason' => ['nullable', 'string', 'max:300'],
        ]);

        return $this->attempt(fn () => response()->json(['data' => EntryRows::row($book->edit($entry, [
            'direction' => $data['direction'] ?? null,
            'amount' => (float) $data['amount'],
            'source_id' => isset($data['source_id']) ? (int) $data['source_id'] : null,
            'reference' => trim($data['reference']),
            'occurred_on' => $data['occurred_on'],
        ], $request->file('evidence'), $request->boolean('remove_evidence'), self::reason($data), $this->staff($request))->load(EntryRows::WITH))]));
    }

    /** Delete (docs/wallet-edits.md): the entry leaves the balance and the list; the history keeps it. */
    public function delete(Request $request, int $id, WalletBook $book): JsonResponse
    {
        $entry = Transaction::query()->ownedBy($this->staff($request)->id)->findOrFail($id);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:300']]);

        return $this->attempt(function () use ($book, $entry, $data, $request) {
            $book->delete($entry, self::reason($data), $this->staff($request));

            return response()->json(['data' => ['deleted' => true]]);
        });
    }

    /** Any version's evidence, the old ones too. */
    public function evidence(Request $request, int $id, EvidenceVault $vault): Response
    {
        $entry = Transaction::query()->ownedBy($this->staff($request)->id)->whereNotNull('evidence_path')->findOrFail($id);
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

    /** This person's sources; a new wallet starts with Personal and Other. */
    public function sources(Request $request): JsonResponse
    {
        $owner = $this->staff($request)->id;
        Source::ensureDefaults($owner);

        return response()->json(['data' => self::sourceList($owner)]);
    }

    public function addSource(Request $request): JsonResponse
    {
        $owner = $this->staff($request)->id;
        Source::ensureDefaults($owner);
        $name = trim($request->validate(['name' => ['required', 'string', 'min:2', 'max:120']])['name']);
        $source = Source::query()->ownedBy($owner)->where('name', $name)->first();
        if ($source !== null && $source->archived_at === null) {
            return self::refused('source_exists');
        }
        $source ??= new Source(['owner_staff_id' => $owner, 'name' => $name, 'sort_order' => 100]);
        $source->forceFill(['archived_at' => null])->save();

        return response()->json(['data' => self::sourceList($owner)], 201);
    }

    /** A new name, shown on its entries too (docs/wallet-edits.md). */
    public function renameSource(Request $request, int $id): JsonResponse
    {
        $owner = $this->staff($request)->id;
        $source = Source::query()->ownedBy($owner)->whereNull('archived_at')->findOrFail($id);
        $name = trim($request->validate(['name' => ['required', 'string', 'min:2', 'max:120']])['name']);
        $taken = Source::query()->ownedBy($owner)->where('name', $name)->whereKeyNot($source->id)->first();
        if ($taken !== null) {
            return self::refused($taken->archived_at === null ? 'source_exists' : 'source_name_deleted');
        }
        $before = $source->name;
        $source->forceFill(['name' => $name])->save();
        AuditLog::record('source_renamed', $owner, $source, ['name' => [$before, $name]]);

        return response()->json(['data' => self::sourceList($owner)]);
    }

    /** Delete: hidden from the form; entries already made keep its name, and adding it again brings it back. */
    public function archiveSource(Request $request, int $id): JsonResponse
    {
        $owner = $this->staff($request)->id;
        Source::query()->ownedBy($owner)->findOrFail($id)->forceFill(['archived_at' => now()])->save();

        return response()->json(['data' => self::sourceList($owner)]);
    }

    public function presets(Request $request): JsonResponse
    {
        return response()->json(['data' => self::presetList($this->staff($request)->id)]);
    }

    public function addPreset(Request $request): JsonResponse
    {
        $owner = $this->staff($request)->id;
        $data = $request->validate(['direction' => ['required', Rule::in([Transaction::IN, Transaction::OUT])], 'label' => ['required', 'string', 'min:2', 'max:120']]);
        ReferencePreset::query()->firstOrCreate(['owner_staff_id' => $owner, 'direction' => $data['direction'], 'label' => trim($data['label'])]);

        return response()->json(['data' => self::presetList($owner)], 201);
    }

    /** A saved reference reworded; entries already made keep the words they were saved with. */
    public function editPreset(Request $request, int $id): JsonResponse
    {
        $owner = $this->staff($request)->id;
        $preset = ReferencePreset::query()->ownedBy($owner)->findOrFail($id);
        $label = trim($request->validate(['label' => ['required', 'string', 'min:2', 'max:120']])['label']);
        if (ReferencePreset::query()->ownedBy($owner)->where('direction', $preset->direction)->where('label', $label)->whereKeyNot($preset->id)->exists()) {
            return self::refused('preset_exists');
        }
        $preset->forceFill(['label' => $label])->save();

        return response()->json(['data' => self::presetList($owner)]);
    }

    public function removePreset(Request $request, int $id): JsonResponse
    {
        $owner = $this->staff($request)->id;
        ReferencePreset::query()->ownedBy($owner)->whereKey($id)->delete();

        return response()->json(['data' => self::presetList($owner)]);
    }

    /** @return Collection<int, Source> */
    private static function sourceList(int $owner)
    {
        return Source::query()->ownedBy($owner)->active()->get(['id', 'name']);
    }

    /** @return Collection<int, ReferencePreset> */
    private static function presetList(int $owner)
    {
        return ReferencePreset::query()->ownedBy($owner)->orderBy('id')->get(['id', 'direction', 'label']);
    }

    private static function refused(string $code): JsonResponse
    {
        return response()->json(['message' => __("wallet.{$code}"), 'code' => $code], 409);
    }

    /** @param array<string, mixed> $data */
    public static function reason(array $data): ?string
    {
        return filled($data['reason'] ?? null) ? trim($data['reason']) : null;
    }

    /** @return array<int, string> */
    public static function dateRule(): array
    {
        return ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.self::today()];
    }

    public static function today(): string
    {
        return CarbonImmutable::now('Asia/Dhaka')->toDateString();
    }
}
