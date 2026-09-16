<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Services\AuditLogger;
use App\Services\Ledger\LedgerService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Journal entries (docs/phase-9-accounts.md §3): every posting the software made — a booking's invoice, a payment, an
 * opening balance — and the adjustments staff write themselves. Nothing here is edited or deleted: a wrong entry is
 * reversed and both stay, so the books can always be read back.
 */
class JournalController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'account_id' => ['nullable', 'integer', 'exists:accounts,id'],
            'kind' => ['nullable', Rule::in(['manual', 'system'])],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $page = JournalEntry::query()
            ->with(['lines.account:id,code,name_en,type', 'staff:id,name'])
            ->when($filters['from'] ?? null, fn (Builder $q, string $from) => $q->where('entry_date', '>=', $from))
            ->when($filters['to'] ?? null, fn (Builder $q, string $to) => $q->where('entry_date', '<=', $to))
            ->when($filters['kind'] ?? null, fn (Builder $q, string $kind) => $kind === 'manual' ? $q->whereNull('source_type') : $q->whereNotNull('source_type'))
            ->when($filters['account_id'] ?? null, fn (Builder $q, int $id) => $q->whereHas('lines', fn (Builder $l) => $l->where('account_id', $id)))
            ->when($filters['search'] ?? null, fn (Builder $q, string $search) => $q->where('description', 'like', "%{$search}%"))
            ->latest('entry_date')->latest('id')->paginate(25);

        $reversed = JournalEntry::query()->whereIn('reverses_journal_entry_id', collect($page->items())->pluck('id'))->pluck('reverses_journal_entry_id')->flip();

        return response()->json([
            'data' => collect($page->items())->map(fn (JournalEntry $entry) => [
                'id' => $entry->id,
                'date' => $entry->entry_date->toDateString(),
                'description' => $entry->description,
                'manual' => $entry->source_type === null,
                'source' => $entry->source_type,
                'booking_id' => $entry->booking_id,
                'reverses' => $entry->reverses_journal_entry_id,
                'reversed' => $reversed->has($entry->id),
                'staff' => $entry->staff?->name,
                'lines' => $entry->lines->map(fn ($line) => [
                    'account_id' => $line->account_id,
                    'code' => $line->account->code,
                    'account' => $line->account->name_en,
                    'debit' => (float) $line->debit,
                    'credit' => (float) $line->credit,
                ])->values(),
                'total' => (float) $entry->lines->sum('debit'),
                'actions' => ['reverse' => $entry->source_type === null && $entry->reverses_journal_entry_id === null && ! $reversed->has($entry->id)],
            ]),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function store(Request $request, LedgerService $ledger): JsonResponse
    {
        $data = $request->validate([
            'entry_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now('Asia/Dhaka')->toDateString()],
            'description' => ['required', 'string', 'min:3', 'max:500'],
            'lines' => ['required', 'array', 'min:2', 'max:20'],
            'lines.*.account_id' => ['required', 'integer', 'exists:accounts,id'],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0', 'max:99999999', 'decimal:0,2'],
            'lines.*.credit' => ['nullable', 'numeric', 'min:0', 'max:99999999', 'decimal:0,2'],
        ]);

        $accounts = Account::query()->whereIn('id', array_column($data['lines'], 'account_id'))->get()->keyBy('id');
        $lines = [];
        foreach ($data['lines'] as $index => $line) {
            $debit = LedgerService::paisa($line['debit'] ?? 0);
            $credit = LedgerService::paisa($line['credit'] ?? 0);
            if ($debit > 0 && $credit > 0) {
                throw ValidationException::withMessages(["lines.{$index}.debit" => __('accounts.one_side_each')]);
            }
            $account = $accounts[$line['account_id']];
            if ($account->isMoney()) {
                throw ValidationException::withMessages(["lines.{$index}.account_id" => __('accounts.money_account')]);
            }
            if ($debit > 0 || $credit > 0) {
                $lines[] = [$account->code, $debit, $credit];
            }
        }
        if (count($lines) < 2) {
            throw ValidationException::withMessages(['lines' => __('accounts.two_sides')]);
        }
        $debits = array_sum(array_column($lines, 1));
        $credits = array_sum(array_column($lines, 2));
        if ($debits !== $credits) {
            throw ValidationException::withMessages(['lines' => __('accounts.unbalanced', [
                'debit' => LedgerService::amount($debits), 'credit' => LedgerService::amount($credits),
            ])]);
        }

        $staff = $request->user('staff');
        $entry = DB::transaction(fn () => $ledger->recordJournalEntry($lines, $data['description'], new \DateTimeImmutable($data['entry_date']), $staff));
        // The amounts are in the entry itself; the log records which one it was.
        $this->audit->record('journal.posted', $staff, $entry, ['entry_date' => $data['entry_date'], 'lines' => count($lines)]);

        return response()->json(['data' => ['id' => $entry->id]], 201);
    }

    public function reverse(Request $request, int $id, LedgerService $ledger): JsonResponse
    {
        $entry = JournalEntry::query()->findOrFail($id);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:300']]);
        $staff = $request->user('staff');

        try {
            $reversal = DB::transaction(fn () => $ledger->reverseJournalEntry($entry, $data['reason'], $staff));
        } catch (LogicException $e) {
            return response()->json([
                'message' => $entry->source_type !== null ? __('accounts.not_manual') : __('accounts.already_reversed'),
                'code' => 'not_reversible',
            ], 409);
        }
        $this->audit->record('journal.reversed', $staff, $entry, ['reversal_id' => $reversal->id, 'reason' => $data['reason']]);

        return response()->json(['data' => ['id' => $reversal->id]], 201);
    }
}
