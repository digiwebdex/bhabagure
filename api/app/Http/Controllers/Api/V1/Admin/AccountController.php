<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Services\AuditLogger;
use App\Services\Ledger\AccountBooks;
use App\Support\Ledger\AccountGroups;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The chart of accounts (docs/phase-9-accounts.md §2). The software's own accounts — the ones it posts bookings,
 * payments and invoices to — are system accounts: staff may reword them, never remove them. Staff add their own
 * accounts for anything else they want to track, and the number is given out of that kind's range.
 */
class AccountController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request, AccountBooks $books): JsonResponse
    {
        $filters = $request->validate(['to' => ['nullable', 'date_format:Y-m-d']]);
        $rows = $books->chart($filters['to'] ?? null);

        return response()->json([
            'data' => $rows->values(),
            'meta' => [
                'types' => Account::TYPES,
                // The sections each kind is read under, in order, so the screen can show them all — an empty one says
                // so rather than disappearing (docs/phase-9-accounts.md §2).
                'groups' => collect(Account::TYPES)->mapWithKeys(fn (string $type) => [$type => AccountGroups::describe($type)]),
                'totals' => collect(Account::TYPES)->mapWithKeys(fn (string $type) => [
                    $type => round($rows->where('type', $type)->sum('balance'), 2),
                ]),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $account = Account::query()->create([
            'code' => $data['code'] ?? self::nextCode($data['type']),
            'name_en' => $data['name'],
            // The staff panel is English only; the Bangla name stays in step for anything printed in Bangla.
            'name_bn' => $data['name'],
            'type' => $data['type'],
            'group' => $data['group'] ?? AccountGroups::DEFAULTS[$data['type']],
            'description' => $data['description'] ?? null,
            'is_system' => false,
            'is_money' => $data['is_money'] ?? false,
            'created_by_staff_id' => $request->user('staff')->id,
        ]);
        $this->audit->record('account.created', $request->user('staff'), $account, ['code' => $account->code, 'name' => $account->name_en, 'type' => $account->type]);

        return response()->json(['data' => self::row($account)], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $account = Account::query()->findOrFail($id);
        $data = $this->validated($request, $account);
        // A system account's number and kind are what the code posts to; only its wording is staff's to change.
        $account->fill(['name_en' => $data['name'], 'name_bn' => $data['name'], 'description' => $data['description'] ?? null]);
        if (! $account->is_system) {
            $account->fill(['type' => $data['type'], 'code' => $data['code'] ?? $account->code, 'is_money' => $data['is_money'] ?? false]);
        }
        // A system account can be moved to another section: where it is read is staff's, what it posts to is not.
        $account->fill(['group' => $data['group'] ?? $account->group ?? AccountGroups::DEFAULTS[$account->type]]);
        $account->save();
        $this->audit->record('account.updated', $request->user('staff'), $account, ['code' => $account->code, 'name' => $account->name_en]);

        return response()->json(['data' => self::row($account)]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $account = Account::query()->findOrFail($id);
        $problem = match (true) {
            $account->is_system => __('accounts.system_account'),
            $account->lines()->exists() => __('accounts.has_entries'),
            default => null,
        };
        if ($problem !== null) {
            return response()->json(['message' => $problem, 'code' => 'account_in_use'], 409);
        }

        $this->audit->record('account.deleted', $request->user('staff'), $account, ['code' => $account->code, 'name' => $account->name_en]);
        $account->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Account $account = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::in(Account::TYPES)],
            // The section it is read under. It must belong to the kind: a liability can't sit under Operating Expense.
            'group' => ['nullable', 'string', 'max:40'],
            'description' => ['nullable', 'string', 'max:300'],
            'code' => ['nullable', 'string', 'regex:/^\d{4}$/', Rule::unique('accounts', 'code')->ignore($account?->id)],
            // A float somebody holds, counted inside the company balance (docs/phase-9-accounts.md §6).
            'is_money' => ['nullable', 'boolean'],
        ]);
        if (isset($data['group']) && ! AccountGroups::belongsTo($data['group'], $data['type'])) {
            throw ValidationException::withMessages(['group' => __('accounts.group_kind')]);
        }
        if (($data['is_money'] ?? false) && $data['type'] !== 'asset') {
            throw ValidationException::withMessages(['is_money' => __('accounts.money_is_an_asset')]);
        }
        // Changing this would move the company balance under everyone's feet, so it is settled before the first entry.
        if ($account !== null && ($data['is_money'] ?? false) !== $account->isMoney() && $account->lines()->exists()) {
            throw ValidationException::withMessages(['is_money' => __('accounts.money_has_entries')]);
        }
        if (isset($data['code'])) {
            [$first, $last] = Account::RANGES[$data['type']];
            if ((int) $data['code'] < $first || (int) $data['code'] > $last) {
                throw ValidationException::withMessages(['code' => __('accounts.code_range', ['first' => $first, 'last' => $last])]);
            }
        }

        return $data;
    }

    /** The first free number in the kind's range. */
    private static function nextCode(string $type): string
    {
        [$first, $last] = Account::RANGES[$type];
        $taken = Account::query()->whereBetween('code', [(string) $first, (string) $last])->pluck('code')->map(fn ($code) => (int) $code)->all();
        for ($code = $first; $code <= $last; $code++) {
            if (! in_array($code, $taken, true)) {
                return (string) $code;
            }
        }

        throw ValidationException::withMessages(['type' => __('accounts.range_full')]);
    }

    /** @return array<string, mixed> */
    private static function row(Account $account): array
    {
        return [
            'id' => $account->id, 'code' => $account->code, 'name' => $account->name_en, 'type' => $account->type,
            'group' => $account->group, 'description' => $account->description, 'is_system' => $account->is_system, 'is_money' => $account->isMoney(),
            'debit' => 0.0, 'credit' => 0.0, 'balance' => 0.0, 'entries' => 0, 'last_entry_on' => null,
        ];
    }
}
