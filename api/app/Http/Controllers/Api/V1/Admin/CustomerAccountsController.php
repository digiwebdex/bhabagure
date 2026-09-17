<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Invoice;
use App\Services\Ledger\LedgerService;
use App\Support\Phone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Customers as the books see them (docs/phase-9-accounts.md §9): everyone who has been invoiced, with how many invoices,
 * for how much, and what is still owed — the Customers screen the client's old accounting service had — and one
 * customer's invoices with the payments against each.
 *
 * Every figure is summed from the issued invoices' own columns, so this screen and the Invoices screen cannot disagree.
 * A draft is not owed and a void invoice is not owed, so neither counts.
 */
class CustomerAccountsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'balance' => ['nullable', Rule::in(['all', 'due'])],
            'sort' => ['nullable', Rule::in(['name', 'due', 'recent'])],
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $base = $this->accounts($filters['search'] ?? null);
        $query = (clone $base)->when(($filters['balance'] ?? 'all') === 'due', fn (Builder $q) => $q->where('books.due', '>', 0));
        $sorted = match ($filters['sort'] ?? 'name') {
            'due' => (clone $query)->orderByDesc('books.due')->orderBy('customers.name'),
            // Customers never invoiced go last, not first: MySQL sorts NULL ahead of every date.
            'recent' => (clone $query)->orderByRaw('books.last_on IS NULL')->orderByDesc('books.last_on')->orderByDesc('customers.id'),
            default => (clone $query)->orderBy('customers.name')->orderBy('customers.id'),
        };
        $page = $sorted->paginate(25);

        return response()->json([
            'data' => collect($page->items())->map(fn (Customer $account) => self::row($account))->values(),
            'meta' => [
                'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(),
                'tabs' => ['all' => (clone $base)->count(), 'due' => (clone $base)->where('books.due', '>', 0)->count()],
                'totals' => [
                    'invoiced' => round((float) (clone $query)->sum('books.total'), 2),
                    'due' => round((float) (clone $query)->sum('books.due'), 2),
                ],
            ],
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $customer = Customer::query()->findOrFail($id);
        $today = now('Asia/Dhaka')->toDateString();
        $invoices = Invoice::query()->where('customer_id', $customer->id)->whereIn('status', [Invoice::ISSUED, Invoice::VOID])
            ->with(['customer:id,name,phone,email', 'issuedBy:id,name', 'updatedBy:id,name'])
            ->orderByDesc('issued_on')->orderByDesc('id')->get();
        $issued = $invoices->where('status', Invoice::ISSUED);
        $sum = fn (string $column) => LedgerService::amount($issued->sum(fn (Invoice $invoice) => LedgerService::paisa($invoice->{$column})));

        return response()->json(['data' => [
            'customer' => ['id' => $customer->id, 'name' => $customer->name, 'phone' => $customer->phone, 'email' => $customer->email],
            'totals' => [
                'invoices' => $issued->count(),
                'total' => (float) $sum('total_amount'),
                'paid' => (float) $sum('paid_amount'),
                'due' => (float) $sum('balance_due'),
            ],
            'invoices' => $invoices->map(fn (Invoice $invoice) => InvoiceBuilderController::row($invoice, $today) + [
                'payments' => InvoiceBuilderController::payments($invoice),
            ])->values(),
        ]]);
    }

    /** Each customer with their issued invoices summed beside them. */
    private function accounts(?string $search): Builder
    {
        $books = Invoice::query()->toBase()
            ->where('status', Invoice::ISSUED)->whereNotNull('customer_id')
            ->groupBy('customer_id')
            ->selectRaw('customer_id, COUNT(*) AS invoices, SUM(total_amount) AS total, SUM(paid_amount) AS paid, SUM(balance_due) AS due, MIN(issued_on) AS first_on, MAX(issued_on) AS last_on');
        $digits = $search !== null ? (Phone::normalizeBdMobile($search) ?? preg_replace('/\D/', '', $search)) : '';

        return Customer::query()
            ->leftJoinSub($books, 'books', 'books.customer_id', '=', 'customers.id')
            // A website enquiry nobody has invoiced is a lead, not an account. Everyone else is here, owing or not.
            ->where(fn (Builder $q) => $q->where('customers.stage', 'customer')->orWhereNotNull('books.customer_id'))
            ->when($search, fn (Builder $q) => $q->where(fn (Builder $match) => $match
                ->where('customers.name', 'like', "%{$search}%")
                ->orWhere('customers.email', 'like', "%{$search}%")
                ->when($digits !== '', fn (Builder $phone) => $phone->orWhere('customers.phone', 'like', "%{$digits}%"))))
            ->select(['customers.id', 'customers.name', 'customers.phone', 'customers.email', 'customers.created_at', 'books.invoices', 'books.total', 'books.paid', 'books.due', 'books.first_on', 'books.last_on']);
    }

    /** @return array<string, mixed> */
    private static function row(Customer $account): array
    {
        // Customer since the earlier of being added here and their first invoice: a customer carried across from the old
        // books was added today but has been buying since the spring.
        $added = $account->created_at->timezone('Asia/Dhaka')->toDateString();
        $since = $account->first_on !== null && $account->first_on < $added ? $account->first_on : $added;

        return [
            'id' => $account->id,
            'name' => $account->name,
            'phone' => $account->phone,
            'email' => $account->email,
            'invoices' => (int) ($account->invoices ?? 0),
            'total' => (float) ($account->total ?? 0),
            'paid' => (float) ($account->paid ?? 0),
            'due' => (float) ($account->due ?? 0),
            'customer_since' => substr($since, 0, 7),
            'last_invoice_on' => $account->last_on,
        ];
    }
}
