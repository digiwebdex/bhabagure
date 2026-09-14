<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\TransactionDirection;
use App\Http\Controllers\Controller;
use App\Http\Resources\AdminCashEntry;
use App\Models\Account;
use App\Models\OpeningBalance;
use App\Models\PaymentAttempt;
use App\Models\Transaction;
use App\Services\AuditLogger;
use App\Services\Ledger\EvidenceStore;
use App\Services\Ledger\LedgerService;
use App\Services\Ledger\PaymentFigures;
use App\Support\Ledger\CashCategories;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use LogicException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Payments & invoices (docs/phase-5-admin-core.md §4.6): the month's money by method, the cash book, manual cash in and
 * out, online payments that need a person, and the company balance. Everything reads the append-only cash book and
 * journal; nothing here edits a row — ✕ is a reversing entry.
 *
 * The whole group needs payments.view. The company balance needs ledger.view_company_balance and answers 403 without
 * it — checked here, not by hiding the card.
 */
class PaymentsController extends Controller
{
    /** Method cards (§4.6): customer money received in a Dhaka month, net of reversals, grouped as the design shows them. */
    public function summary(Request $request, LedgerService $ledger): JsonResponse
    {
        $month = $request->validate(['month' => ['nullable', 'date_format:Y-m']])['month'] ?? now('Asia/Dhaka')->format('Y-m');
        $cards = PaymentFigures::methodCards($month);
        $staff = $request->user('staff');

        return response()->json(['data' => [
            'month' => $month,
            'methods' => $cards,
            // "Collected": the cash that arrived — the Dashboard shows the same figure.
            'collected' => Money::toNumber($cards->sum('amount')),
            // Invoiced sales in the month, beside it, so both meanings of "revenue" are labelled.
            'invoiced' => PaymentFigures::invoiced($month),
            'review_count' => PaymentFigures::reviewQueue()->count(),
            'balance' => $staff->can('ledger.view_company_balance') ? $this->balances($ledger) : null,
        ]]);
    }

    /** The company balance by money account, with each account's opening balance. 403 without the permission. */
    public function balance(Request $request, LedgerService $ledger): JsonResponse
    {
        abort_unless($request->user('staff')->can('ledger.view_company_balance'), 403, __('auth.forbidden'));

        return response()->json(['data' => $this->balances($ledger)]);
    }

    public function cashBook(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'direction' => ['nullable', Rule::in(['in', 'out'])],
            'method' => ['nullable', Rule::in(array_keys(PaymentFigures::METHOD_GROUPS))],
            'category' => ['nullable', Rule::in(CashCategories::all())],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $page = self::filtered($filters)->with(AdminCashEntry::RELATIONS)->orderByDesc('occurred_at')->orderByDesc('id')->paginate(30);
        $staff = $request->user('staff');

        return response()->json([
            'data' => collect($page->items())->map(fn (Transaction $entry) => AdminCashEntry::row($entry, $staff)),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    /** What a manual entry can be: methods, categories by direction, business lines, and whether opening balances are still open. */
    public function options(): JsonResponse
    {
        $accounts = Account::query()->whereIn('code', Account::MONEY)->get()->keyBy('code');
        $opened = OpeningBalance::query()->pluck('account_id')->all();

        return response()->json(['data' => [
            'methods' => LedgerService::STAFF_METHODS,
            'categories' => ['in' => CashCategories::forDirection('in'), 'out' => CashCategories::forDirection('out')],
            'business_lines' => CashCategories::BUSINESS_LINES,
            'money_accounts' => collect(Account::MONEY)->map(fn (string $code) => [
                'code' => $code, 'name_en' => $accounts[$code]->name_en, 'name_bn' => $accounts[$code]->name_bn, 'has_opening_balance' => in_array($accounts[$code]->id, $opened, true),
            ])->values(),
        ]]);
    }

    /** Manual cash in or out, with its receipt (image or PDF, private). */
    public function store(Request $request, LedgerService $ledger, EvidenceStore $evidence, AuditLogger $audit): JsonResponse
    {
        $staff = $request->user('staff');
        abort_unless($staff->can('transactions.create_manual'), 403, __('auth.forbidden'));
        $data = $request->validate([
            'direction' => ['required', Rule::in(['in', 'out'])],
            'amount' => ['required', 'numeric', 'min:1', 'max:9999999999'],
            'method' => ['required', Rule::in(LedgerService::STAFF_METHODS)],
            'category' => ['required', Rule::in(CashCategories::forDirection((string) $request->input('direction')))],
            'business_line' => ['nullable', Rule::in(CashCategories::BUSINESS_LINES)],
            'description' => ['required', 'string', 'min:3', 'max:300'],
            'reference' => ['nullable', 'string', 'max:120'],
            'occurred_on' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.now('Asia/Dhaka')->toDateString()],
            'evidence' => EvidenceStore::rules(),
        ]);

        $entry = $evidence->with($request->file('evidence'), fn (?string $path) => DB::transaction(function () use ($ledger, $audit, $data, $staff, $path) {
            $entry = $ledger->recordManualEntry(
                TransactionDirection::from($data['direction']), $data['amount'], $data['method'], $data['category'], $data['business_line'] ?? null,
                trim($data['description']), $staff, ($data['reference'] ?? null) ?: null, $path, self::onDay($data['occurred_on'] ?? null),
            );
            $audit->record('cash.manual_entry', $staff, $entry, ['direction' => $data['direction'], 'amount' => $entry->amount, 'category' => $data['category']]);

            return $entry;
        }));

        return response()->json(['data' => AdminCashEntry::row($entry->load(AdminCashEntry::RELATIONS), $staff)], Response::HTTP_CREATED);
    }

    /** ✕ on a cash-book row: an opposite entry with a reason, where reversal is allowed. */
    public function reverse(Request $request, int $id, LedgerService $ledger, AuditLogger $audit): JsonResponse
    {
        $staff = $request->user('staff');
        abort_unless($staff->can('transactions.create_manual'), 403, __('auth.forbidden'));
        $entry = Transaction::query()->findOrFail($id);
        $reason = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:300']])['reason'];

        try {
            $reversal = DB::transaction(function () use ($ledger, $audit, $entry, $reason, $staff) {
                $reversal = $ledger->reversePayment($entry, $reason, $staff);
                $audit->record('cash.reversed', $staff, $entry, ['reversal' => $reversal->id, 'reason' => $reason]);

                return $reversal;
            });
        } catch (LogicException $e) {
            return response()->json(['message' => __('payments.not_reversible'), 'code' => 'not_reversible'], Response::HTTP_CONFLICT);
        }

        return response()->json(['data' => AdminCashEntry::row($reversal->load(AdminCashEntry::RELATIONS), $staff)], Response::HTTP_CREATED);
    }

    public function evidence(int $id): StreamedResponse
    {
        $entry = Transaction::query()->whereNotNull('evidence_path')->findOrFail($id);
        abort_unless(Storage::disk('local')->exists($entry->evidence_path), 404);

        return Storage::disk('local')->response($entry->evidence_path, "evidence-{$entry->id}.".pathinfo($entry->evidence_path, PATHINFO_EXTENSION), [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'",
        ]);
    }

    /** Online payments a person must look at: held for review, or settled with more collected than the customer was shown. */
    public function reviewIndex(Request $request): JsonResponse
    {
        $attempts = PaymentFigures::reviewQueue()->with('booking:id,reference,customer_id', 'booking.customer:id,name,phone')->oldest('id')->limit(100)->get();

        return response()->json(['data' => $attempts->map(fn (PaymentAttempt $attempt) => [
            'id' => $attempt->id,
            'tran_id' => $attempt->tran_id,
            'status' => $attempt->status->value,
            'reason' => $attempt->failure_reason,
            'booking' => ['id' => $attempt->booking->id, 'reference' => $attempt->booking->reference, 'customer' => $attempt->booking->customer?->name],
            'amount' => Money::toNumber($attempt->amount),
            'online_charge' => Money::toNumber($attempt->online_charge),
            'gateway_amount' => Money::toNumber($attempt->gateway_amount),
            'gateway_surcharge' => Money::toNumber($attempt->gateway_surcharge),
            'card_type' => $attempt->card_type,
            'risk_level' => $attempt->risk_level,
            'at' => ($attempt->closed_at ?? $attempt->updated_at)->toIso8601String(),
        ])]);
    }

    public function markReviewed(Request $request, int $id, AuditLogger $audit): JsonResponse
    {
        $staff = $request->user('staff');
        $note = $request->validate(['note' => ['required', 'string', 'min:3', 'max:300']])['note'];
        $attempt = PaymentFigures::reviewQueue()->findOrFail($id);
        $attempt->forceFill(['reviewed_at' => now(), 'reviewed_by_staff_id' => $staff->id, 'review_note' => $note])->save();
        $audit->record('payment.reviewed', $staff, $attempt->booking, ['tran_id' => $attempt->tran_id, 'note' => $note]);

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    /** One per money account, once. Needs both the company balance and manual entries. */
    public function storeOpeningBalance(Request $request, LedgerService $ledger, AuditLogger $audit): JsonResponse
    {
        $staff = $request->user('staff');
        abort_unless($staff->can('ledger.view_company_balance') && $staff->can('transactions.create_manual'), 403, __('auth.forbidden'));
        $data = $request->validate([
            'account' => ['required', Rule::in(Account::MONEY)],
            'amount' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'as_of' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now('Asia/Dhaka')->toDateString()],
            'note' => ['nullable', 'string', 'max:300'],
        ]);
        $account = Account::query()->where('code', $data['account'])->firstOrFail();

        try {
            DB::transaction(function () use ($ledger, $audit, $account, $data, $staff) {
                $opening = $ledger->postOpeningBalance($account, $data['amount'], $data['as_of'], $data['note'] ?? null, $staff);
                $audit->record('ledger.opening_balance', $staff, $opening, ['account' => $account->code, 'amount' => $opening->amount, 'as_of' => $data['as_of']]);
            });
        } catch (LogicException|InvalidArgumentException) {
            return response()->json(['message' => __('payments.opening_exists'), 'code' => 'opening_exists'], Response::HTTP_CONFLICT);
        }

        return response()->json(['data' => $this->balances($ledger)], Response::HTTP_CREATED);
    }

    /** @return array<string, mixed> */
    private function balances(LedgerService $ledger): array
    {
        $paisa = $ledger->moneyBalances();
        $accounts = Account::query()->whereIn('code', array_keys($paisa))->get()->keyBy('code');
        $openings = OpeningBalance::query()->with('createdBy:id,name')->get()->keyBy('account_id');

        return [
            'total' => Money::toNumber(LedgerService::amount(array_sum($paisa))),
            'accounts' => collect($paisa)->map(fn (int $value, string $code) => [
                'code' => $code,
                'name_en' => $accounts[$code]->name_en,
                'name_bn' => $accounts[$code]->name_bn,
                'balance' => Money::toNumber(LedgerService::amount($value)),
                // The shared account from before the split, shown only while something is left in it.
                'legacy' => $code === Account::MOBILE_WALLETS,
                'opening' => ($opening = $openings[$accounts[$code]->id] ?? null) ? [
                    'amount' => Money::toNumber($opening->amount), 'as_of' => $opening->as_of->toDateString(), 'by' => $opening->createdBy?->name,
                ] : null,
            ])->values(),
        ];
    }

    /** @param array<string, ?string> $filters */
    private static function filtered(array $filters): Builder
    {
        $search = $filters['search'] ?? null;

        return Transaction::query()
            ->when($filters['direction'] ?? null, fn (Builder $q, string $direction) => $q->where('direction', $direction))
            ->when($filters['method'] ?? null, fn (Builder $q, string $group) => $q->whereIn('method', PaymentFigures::METHOD_GROUPS[$group]))
            ->when($filters['category'] ?? null, fn (Builder $q, string $category) => $q->where('category', $category))
            ->when($filters['from'] ?? null, fn (Builder $q, string $from) => $q->where('occurred_at', '>=', Carbon::parse($from, 'Asia/Dhaka')->startOfDay()->utc()))
            ->when($filters['to'] ?? null, fn (Builder $q, string $to) => $q->where('occurred_at', '<=', Carbon::parse($to, 'Asia/Dhaka')->endOfDay()->utc()))
            ->when($search, fn (Builder $q) => $q->where(fn (Builder $inner) => $inner
                ->where('description', 'like', "%{$search}%")->orWhere('reference_label', 'like', "%{$search}%")->orWhere('external_ref', 'like', "%{$search}%")
                ->orWhereHas('booking', fn (Builder $b) => $b->where('reference', 'like', "%{$search}%"))
                ->orWhereHas('invoice', fn (Builder $i) => $i->where('invoice_number', 'like', "%{$search}%"))
                ->orWhereHas('customer', fn (Builder $c) => $c->where('name', 'like', "%{$search}%"))));
    }

    /** Today's entries at the moment they're made; a backdated one at noon that day in Dhaka. */
    private static function onDay(?string $date): ?Carbon
    {
        return $date === null || $date === now('Asia/Dhaka')->toDateString() ? null : Carbon::parse("{$date} 12:00", 'Asia/Dhaka')->utc();
    }
}
