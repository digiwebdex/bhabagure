<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\BonusAccount;
use App\Models\BonusTransaction;
use App\Models\BonusWithdrawal;
use App\Models\Staff;
use App\Services\Bonus\BonusDesk;
use App\Services\Bonus\BonusRefused;
use App\Services\Ledger\EvidenceStore;
use App\Services\Ledger\LedgerService;
use App\Support\Numerals;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * HR → Staff & bonus (docs/phase-7-hr-attendance-bonus-wallet.md §7): a person's bonus ledger with manual credits and
 * reversals, and the withdrawals queue — approve, reject, mark paid. Reading needs bonus.manage or commission.view_all
 * (the route); every change needs bonus.manage.
 */
class BonusController extends Controller
{
    public const FILTERS = ['open', 'pending', 'approved', 'paid', 'rejected', 'cancelled', 'all'];

    public function ledger(Request $request, int $id): JsonResponse
    {
        $person = Staff::query()->findOrFail($id);

        return response()->json(['data' => self::ledgerPayload($person, $request->user('staff'))]);
    }

    public function credit(Request $request, int $id, BonusDesk $desk): JsonResponse
    {
        $this->ensureManage($request);
        $person = Staff::query()->findOrFail($id);
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:1', 'max:9999999'],
            'reason' => ['required', 'string', 'min:3', 'max:300'],
        ]);

        return $this->attempt(fn () => $desk->credit($person, (float) $data['amount'], $data['reason'], $request->user('staff')),
            fn () => response()->json(['data' => self::ledgerPayload($person, $request->user('staff'))], Response::HTTP_CREATED));
    }

    public function reverse(Request $request, int $id, BonusDesk $desk): JsonResponse
    {
        $this->ensureManage($request);
        $entry = BonusTransaction::query()->with('account.staff')->findOrFail($id);
        $reason = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:300']])['reason'];

        return $this->attempt(fn () => $desk->reverse($entry, $reason, $request->user('staff')),
            fn () => response()->json(['data' => self::ledgerPayload($entry->account->staff, $request->user('staff'))]));
    }

    /** The filter is `withdrawals`, not `status`: the Staff screen it lives on already filters its own list by status. */
    public function withdrawals(Request $request): JsonResponse
    {
        $status = $request->validate(['withdrawals' => ['nullable', Rule::in(self::FILTERS)], 'page' => ['nullable', 'integer', 'min:1']])['withdrawals'] ?? 'open';
        $page = self::filtered($status)->with(['staff', 'decidedBy', 'paidBy', 'cashTransaction'])->orderByRaw("status = 'pending' desc")->latest('id')->paginate(30);
        $viewer = $request->user('staff');

        return response()->json([
            'data' => collect($page->items())->map(fn (BonusWithdrawal $withdrawal) => self::withdrawalRow($withdrawal, $viewer))->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'status_counts' => collect(self::FILTERS)->mapWithKeys(fn (string $filter) => [$filter => self::filtered($filter)->count()]),
                'methods' => LedgerService::STAFF_METHODS,
            ],
        ]);
    }

    /** Withdrawals waiting for someone: pending, or approved and not yet paid (the Staff badge). */
    public static function filtered(string $status): Builder
    {
        return BonusWithdrawal::query()->when($status === 'open', fn (Builder $query) => $query->open())
            ->when(! in_array($status, ['open', 'all'], true), fn (Builder $query) => $query->where('status', $status));
    }

    public function approve(Request $request, int $id, BonusDesk $desk): JsonResponse
    {
        $this->ensureManage($request);
        $withdrawal = BonusWithdrawal::query()->findOrFail($id);
        $note = $request->validate(['note' => ['nullable', 'string', 'max:300']])['note'] ?? null;

        return $this->decided(fn () => $desk->approve($withdrawal, $note, $request->user('staff')), $request);
    }

    public function reject(Request $request, int $id, BonusDesk $desk): JsonResponse
    {
        $this->ensureManage($request);
        $withdrawal = BonusWithdrawal::query()->findOrFail($id);
        $note = $request->validate(['note' => ['required', 'string', 'min:3', 'max:300']])['note'];

        return $this->decided(fn () => $desk->reject($withdrawal, $note, $request->user('staff')), $request);
    }

    public function pay(Request $request, int $id, BonusDesk $desk, EvidenceStore $evidence): JsonResponse
    {
        $this->ensureManage($request);
        $withdrawal = BonusWithdrawal::query()->findOrFail($id);
        $data = $request->validate([
            'method' => ['required', Rule::in(LedgerService::STAFF_METHODS)],
            'reference' => ['nullable', 'string', 'max:120'],
            'occurred_on' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.now('Asia/Dhaka')->toDateString()],
            'evidence' => EvidenceStore::rules(),
        ]);

        return $this->decided(fn () => $evidence->with($request->file('evidence'), fn (?string $path) => $desk->markPaid(
            $withdrawal, ['method' => $data['method'], 'reference' => $data['reference'] ?? null, 'occurred_on' => $data['occurred_on'] ?? null], $path, $request->user('staff'),
        )), $request);
    }

    /** @return array<string, mixed> */
    public static function ledgerPayload(Staff $person, Staff $viewer): array
    {
        // Reading never opens an account: someone who never had a bonus simply has none yet.
        $accountId = BonusAccount::query()->where('staff_id', $person->id)->value('id') ?? 0;
        $entries = BonusTransaction::query()->with(['createdBy', 'booking:id,reference', 'reversedBy:id,reverses_id'])
            ->where('bonus_account_id', $accountId)->latest('id')->limit(100)->get();
        $mayChange = $viewer->can('bonus.manage') && ($person->id !== $viewer->id || $viewer->isSuperAdmin());
        [$balance, $held] = [BonusDesk::balance($accountId), BonusDesk::held($accountId)];
        $available = round($balance - $held, 2);

        return [
            'staff' => ['id' => $person->id, 'name' => $person->name, 'employee_code' => $person->employee_code],
            'balance' => $balance,
            'held' => $held,
            'available' => $available,
            'entries' => $entries->map(fn (BonusTransaction $entry) => self::entryRow($entry, $available))->all(),
            'withdrawals' => BonusWithdrawal::query()->with(['staff', 'decidedBy', 'paidBy', 'cashTransaction'])->where('bonus_account_id', $accountId)
                ->latest('id')->limit(20)->get()->map(fn (BonusWithdrawal $withdrawal) => self::withdrawalRow($withdrawal, $viewer))->all(),
            'actions' => ['credit' => $mayChange, 'reverse' => $mayChange],
        ];
    }

    /** @return array<string, mixed> */
    /** @param float $available the account's available balance: a credit larger than it can't be taken back (BonusDesk::reverse) */
    public static function entryRow(BonusTransaction $entry, float $available = 0.0): array
    {
        return [
            'id' => $entry->id,
            'direction' => $entry->direction,
            'amount' => (float) $entry->amount,
            'kind' => $entry->kind,
            'reason' => $entry->reason,
            'booking' => $entry->booking ? ['id' => $entry->booking->id, 'reference' => $entry->booking->reference] : null,
            'withdrawal_id' => $entry->bonus_withdrawal_id,
            'reverses_id' => $entry->reverses_id,
            'reversed' => $entry->reversedBy !== null,
            'reversible' => in_array($entry->kind, [BonusTransaction::MANUAL, BonusTransaction::COMMISSION], true) && $entry->reversedBy === null
                && ($entry->direction !== BonusTransaction::CREDIT || (float) $entry->amount <= $available),
            'by' => $entry->createdBy?->name,
            'created_at' => $entry->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function withdrawalRow(BonusWithdrawal $withdrawal, Staff $viewer): array
    {
        $own = $withdrawal->staff_id === $viewer->id && ! $viewer->isSuperAdmin();
        $manage = $viewer->can('bonus.manage') && ! $own;

        return [
            'id' => $withdrawal->id,
            'staff' => ['id' => $withdrawal->staff->id, 'name' => $withdrawal->staff->name, 'employee_code' => $withdrawal->staff->employee_code],
            'amount' => (float) $withdrawal->amount,
            'note' => $withdrawal->note,
            'status' => $withdrawal->status,
            'requested_at' => $withdrawal->created_at?->toIso8601String(),
            'decided_by' => $withdrawal->decidedBy?->name,
            'decided_at' => $withdrawal->decided_at?->toIso8601String(),
            'decision_note' => $withdrawal->decision_note,
            'paid_at' => $withdrawal->paid_at?->toIso8601String(),
            'paid_by' => $withdrawal->paidBy?->name,
            'method' => $withdrawal->cashTransaction?->method,
            'reference' => $withdrawal->cashTransaction?->reference_label,
            'actions' => [
                'approve' => $manage && $withdrawal->status === BonusWithdrawal::PENDING,
                'reject' => $manage && in_array($withdrawal->status, BonusWithdrawal::OPEN, true),
                'pay' => $manage && $withdrawal->status === BonusWithdrawal::APPROVED,
            ],
        ];
    }

    /**
     * @param  Closure(): mixed  $action
     * @param  Closure(): JsonResponse  $respond
     */
    private function attempt(Closure $action, Closure $respond): JsonResponse
    {
        try {
            DB::transaction(fn () => $action());
        } catch (BonusRefused $e) {
            return self::refused($e);
        }

        return $respond();
    }

    /** @param Closure(): BonusWithdrawal $action */
    private function decided(Closure $action, Request $request): JsonResponse
    {
        try {
            $withdrawal = DB::transaction(fn () => $action());
        } catch (BonusRefused $e) {
            return self::refused($e);
        }

        return response()->json(['data' => self::withdrawalRow($withdrawal->fresh(['staff', 'decidedBy', 'paidBy', 'cashTransaction']), $request->user('staff'))]);
    }

    public static function refused(BonusRefused $e): JsonResponse
    {
        $status = in_array($e->reason, ['own_bonus', 'not_yours'], true) ? Response::HTTP_FORBIDDEN : Response::HTTP_CONFLICT;

        return response()->json(['message' => __("bonus.{$e->reason}", ['amount' => Numerals::bdt(BonusDesk::MIN_WITHDRAWAL, app()->getLocale() === 'en' ? 'en' : 'bn')]), 'code' => $e->reason], $status);
    }

    private function ensureManage(Request $request): void
    {
        abort_unless($request->user('staff')->can('bonus.manage'), Response::HTTP_FORBIDDEN, __('auth.forbidden'));
    }
}
