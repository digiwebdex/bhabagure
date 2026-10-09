<?php

namespace App\Wallet\Http\Controllers;

use App\Wallet\Http\EntryRows;
use App\Wallet\Models\AuditLog;
use App\Wallet\Models\Deal;
use App\Wallet\Models\Transaction;
use App\Wallet\Services\EvidenceVault;
use App\Wallet\Services\WalletBook;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Deals (docs/phase-7-hr-attendance-bonus-wallet.md §8): a total, an advance no more than it, then payments against what
 * is due. View, Edit, Add payment and Delete (docs/wallet-edits.md); the advance and payments themselves are edited and
 * deleted as entries (BookController). Only the signed-in person's own; a deleted deal answers 404.
 */
final class DealController extends WalletController
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => self::deals($this->staff($request)->id)->latest('id')->get()->map(fn (Deal $deal) => self::row($deal))->all()]);
    }

    /** One deal (View): its figures and payments, and its own history (made, then each edit). */
    public function show(Request $request, int $id): JsonResponse
    {
        $owner = $this->staff($request)->id;
        $deal = self::deals($owner)->findOrFail($id);
        $history = AuditLog::query()->where('staff_id', $owner)->where('subject_type', $deal->getTable())->where('subject_id', $deal->id)
            ->whereIn('action', ['deal_created', 'deal_edited'])->orderBy('id')->get(['action', 'changes', 'created_at'])
            ->map(fn (AuditLog $log) => ['action' => $log->action, 'at' => $log->created_at?->toIso8601String(), 'changes' => $log->changes])->all();

        return response()->json(['data' => self::row($deal) + ['history' => $history]]);
    }

    public function create(Request $request, WalletBook $book): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'total' => ['required', 'numeric', 'min:1', 'max:999999999'],
            'advance' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'note' => ['nullable', 'string', 'max:300'],
            'reference' => ['nullable', 'string', 'max:300'],
            'occurred_on' => BookController::dateRule(),
            'evidence' => EvidenceVault::rules(),
        ]);

        return $this->attempt(function () use ($data, $request, $book) {
            $deal = $book->createDeal(trim($data['name']), (float) $data['total'], (float) ($data['advance'] ?? 0), filled($data['note'] ?? null) ? trim($data['note']) : null,
                filled($data['reference'] ?? null) ? trim($data['reference']) : __('wallet.advance'), $data['occurred_on'] ?? BookController::today(), $request->file('evidence'), $this->staff($request));

            return response()->json(['data' => self::row(self::deals($this->staff($request)->id)->findOrFail($deal->id))], 201);
        });
    }

    /** Name, total (no less than received) and note. */
    public function edit(Request $request, int $id, WalletBook $book): JsonResponse
    {
        $owner = $this->staff($request)->id;
        $deal = Deal::query()->ownedBy($owner)->notDeleted()->findOrFail($id);
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'total' => ['required', 'numeric', 'min:1', 'max:999999999'],
            'note' => ['nullable', 'string', 'max:300'],
            'reason' => ['nullable', 'string', 'max:300'],
        ]);

        return $this->attempt(function () use ($book, $deal, $data, $request, $owner) {
            $book->editDeal($deal, trim($data['name']), (float) $data['total'], filled($data['note'] ?? null) ? trim($data['note']) : null, BookController::reason($data), $this->staff($request));

            return response()->json(['data' => self::row(self::deals($owner)->findOrFail($deal->id))]);
        });
    }

    /** Deleted with its advance and payments: that money leaves the balance too (the owner's choice, 2026-10-09). */
    public function delete(Request $request, int $id, WalletBook $book): JsonResponse
    {
        $deal = Deal::query()->ownedBy($this->staff($request)->id)->notDeleted()->findOrFail($id);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:300']]);

        return $this->attempt(fn () => response()->json(['data' => ['removed' => $book->deleteDeal($deal, BookController::reason($data), $this->staff($request))]]));
    }

    /** Add payment: no more than what is due. */
    public function pay(Request $request, int $id, WalletBook $book): JsonResponse
    {
        $owner = $this->staff($request)->id;
        $deal = Deal::query()->ownedBy($owner)->notDeleted()->findOrFail($id);
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:1', 'max:999999999'],
            'reference' => ['nullable', 'string', 'max:300'],
            'occurred_on' => BookController::dateRule(),
            'evidence' => EvidenceVault::rules(),
        ]);

        return $this->attempt(function () use ($deal, $data, $request, $book, $owner) {
            $book->recordPayment($deal, (float) $data['amount'], filled($data['reference'] ?? null) ? trim($data['reference']) : __('wallet.due_payment'),
                $data['occurred_on'] ?? BookController::today(), $request->file('evidence'), $this->staff($request));

            return response()->json(['data' => self::row(self::deals($owner)->findOrFail($deal->id))], 201);
        });
    }

    /** The owner's deals that aren't deleted, each with its money (as entry rows). */
    private static function deals(int $owner): Builder
    {
        return Deal::query()->ownedBy($owner)->notDeleted()
            ->with(['transactions' => fn ($query) => $query->where('kind', '!=', Transaction::REVERSAL)->with(EntryRows::WITH)->orderBy('occurred_on')->orderBy('id')]);
    }

    /**
     * A deal's figures from its standing advance and payments; only those are listed (edited and deleted ones are in the
     * history with "Show edits and deletions").
     *
     * @return array<string, mixed>
     */
    private static function row(Deal $deal): array
    {
        $standing = $deal->transactions->filter(fn (Transaction $entry) => $entry->reversedBy === null)->values();
        $received = round($standing->sum(fn (Transaction $entry) => (float) $entry->amount), 2);
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
            'payments' => $standing->map(fn (Transaction $entry) => EntryRows::row($entry))->all(),
        ];
    }
}
