<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AdminCashEntry;
use App\Models\Client;
use App\Models\Customer;
use App\Models\Invoice;
use App\Services\Invoices\DealService;
use App\Services\Invoices\InvoicePdf;
use App\Services\Ledger\EvidenceStore;
use App\Services\Ledger\LedgerService;
use App\Services\Ledger\PaymentExceedsBalance;
use App\Support\Money;
use App\Support\Phone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use LogicException;

/**
 * "Deals · advance & due" (docs/phase-5-admin-core.md §4.6, question 3): standalone invoices for a customer or a company,
 * paid through the ledger. Viewing needs payments.view; creating and voiding need invoices.manage; recording a payment
 * needs transactions.create_manual, as on a booking.
 */
class DealController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate(['state' => ['nullable', Rule::in(['open', 'paid', 'void', 'all'])], 'search' => ['nullable', 'string', 'max:100']]);
        $state = $filters['state'] ?? 'open';
        $search = $filters['search'] ?? null;

        $deals = Invoice::query()->where('kind', Invoice::KIND_DEAL)
            ->when($state === 'open', fn (Builder $q) => $q->where('status', Invoice::ISSUED)->where('balance_due', '>', 0))
            ->when($state === 'paid', fn (Builder $q) => $q->where('status', Invoice::ISSUED)->where('balance_due', '<=', 0))
            ->when($state === 'void', fn (Builder $q) => $q->where('status', Invoice::VOID))
            ->when($search, fn (Builder $q) => $q->where(fn (Builder $inner) => $inner->where('billed_name', 'like', "%{$search}%")
                ->orWhere('title', 'like', "%{$search}%")->orWhere('invoice_number', 'like', "%{$search}%")))
            ->latest('id')->paginate(20);

        return response()->json([
            'data' => collect($deals->items())->map(AdminCashEntry::deal(...)),
            'meta' => [
                'current_page' => $deals->currentPage(), 'last_page' => $deals->lastPage(), 'total' => $deals->total(),
                // "Total due" in the card heading: every open deal, whatever the filter.
                'total_due' => Money::toNumber(Invoice::query()->where('kind', Invoice::KIND_DEAL)->where('status', Invoice::ISSUED)->where('balance_due', '>', 0)->sum('balance_due')),
            ],
        ]);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(['data' => AdminCashEntry::deal($this->deal($id))]);
    }

    public function store(Request $request, DealService $deals, EvidenceStore $evidence): JsonResponse
    {
        $staff = $request->user('staff');
        abort_unless($staff->can('invoices.manage'), 403, __('auth.forbidden'));
        if ($request->filled('company.contact_phone')) {
            $request->merge(['company' => ['contact_phone' => Phone::normalizeBdMobile($request->input('company.contact_phone')) ?? $request->input('company.contact_phone')] + $request->input('company')]);
        }
        $data = $request->validate([
            'customer_id' => ['nullable', 'integer', 'required_without_all:client_id,company', 'prohibits:client_id,company', Rule::exists('customers', 'id')->whereNull('deleted_at')],
            'client_id' => ['nullable', 'integer', 'required_without_all:customer_id,company', 'prohibits:customer_id,company', Rule::exists('clients', 'id')->whereNull('deleted_at')],
            'company' => ['nullable', 'array', 'required_without_all:customer_id,client_id'],
            'company.name' => ['required_with:company', 'string', 'min:2', 'max:160'],
            'company.type' => ['required_with:company', Rule::in(['corporate', 'b2b_agent'])],
            'company.contact_phone' => ['nullable', 'regex:/^8801[3-9]\d{8}$/'],
            'company.contact_email' => ['nullable', 'email', 'max:190'],
            'title' => ['required', 'string', 'min:3', 'max:255'],
            'note' => ['nullable', 'string', 'max:500'],
            'total' => ['required', 'numeric', 'min:1', 'max:9999999999'],
            'advance' => ['nullable', 'numeric', 'min:0', 'lte:total'],
            'advance_method' => ['required_unless:advance,null,0', 'nullable', Rule::in(LedgerService::STAFF_METHODS)],
            'advance_reference' => ['nullable', 'string', 'max:120'],
            // An advance is money received: its receipt comes with it.
            'advance_evidence' => (float) $request->input('advance', 0) > 0 ? EvidenceStore::rules() : ['prohibited'],
        ]);

        $party = match (true) {
            isset($data['customer_id']) => Customer::query()->findOrFail($data['customer_id']),
            isset($data['client_id']) => Client::query()->findOrFail($data['client_id']),
            default => Client::query()->firstOrCreate(
                ['name' => trim($data['company']['name']), 'type' => $data['company']['type']],
                ['contact_phone' => $data['company']['contact_phone'] ?? null, 'contact_email' => $data['company']['contact_email'] ?? null, 'status' => 'active'],
            ),
        };

        $invoice = $evidence->with($request->file('advance_evidence'), fn (?string $path) => $deals->create($party, trim($data['title']), $data['total'], ($data['note'] ?? null) ?: null,
            (float) ($data['advance'] ?? 0) > 0 ? ['amount' => $data['advance'], 'method' => $data['advance_method'], 'reference' => $data['advance_reference'] ?? null, 'evidence' => $path] : null,
            $staff));

        return response()->json(['data' => AdminCashEntry::deal($invoice)], Response::HTTP_CREATED);
    }

    public function pay(Request $request, int $id, DealService $deals, EvidenceStore $evidence): JsonResponse
    {
        $staff = $request->user('staff');
        abort_unless($staff->can('transactions.create_manual'), 403, __('auth.forbidden'));
        $invoice = $this->deal($id);
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:1', 'max:9999999999'],
            'method' => ['required', Rule::in(LedgerService::STAFF_METHODS)],
            'reference' => ['nullable', 'string', 'max:120'],
            'occurred_on' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.now('Asia/Dhaka')->toDateString()],
            'evidence' => EvidenceStore::rules(),
        ]);

        try {
            $evidence->with($request->file('evidence'), fn (?string $path) => $deals->pay($invoice, $data['amount'], $data['method'], ($data['reference'] ?? null) ?: null,
                isset($data['occurred_on']) && $data['occurred_on'] !== now('Asia/Dhaka')->toDateString() ? Carbon::parse("{$data['occurred_on']} 12:00", 'Asia/Dhaka')->utc() : null,
                $path, $staff));
        } catch (PaymentExceedsBalance) {
            return response()->json(['message' => __('payments.exceeds_due'), 'code' => 'exceeds_due'], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (LogicException) {
            return response()->json(['message' => __('payments.deal_closed'), 'code' => 'deal_closed'], Response::HTTP_CONFLICT);
        }

        return response()->json(['data' => AdminCashEntry::deal($invoice->fresh())], Response::HTTP_CREATED);
    }

    public function void(Request $request, int $id, DealService $deals): JsonResponse
    {
        $staff = $request->user('staff');
        abort_unless($staff->can('invoices.manage'), 403, __('auth.forbidden'));
        $invoice = $this->deal($id);
        $reason = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']])['reason'];

        try {
            $deals->void($invoice, $reason, $staff);
        } catch (LogicException) {
            return response()->json(['message' => __('payments.void_has_payments'), 'code' => 'has_payments'], Response::HTTP_CONFLICT);
        }

        return response()->json(['data' => AdminCashEntry::deal($invoice->fresh())]);
    }

    public function pdf(Request $request, int $id, InvoicePdf $pdf): Response
    {
        $invoice = $this->deal($id);
        $options = $request->validate(['header' => ['nullable', 'boolean'], 'lang' => ['nullable', Rule::in(['bn', 'en'])]]);
        $header = (bool) ($options['header'] ?? true);

        return response($pdf->pdf($invoice, $header, $options['lang'] ?? 'bn'))
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', "inline; filename=\"{$invoice->invoice_number}".($header ? '' : '-pad').'.pdf"')
            ->header('Cache-Control', 'no-store');
    }

    /** Companies to bill a deal to. */
    public function clients(Request $request): JsonResponse
    {
        $search = $request->validate(['search' => ['nullable', 'string', 'max:100']])['search'] ?? null;

        return response()->json(['data' => Client::query()->when($search, fn (Builder $q) => $q->where('name', 'like', "%{$search}%"))
            ->orderBy('name')->limit(10)->get(['id', 'name', 'type', 'contact_phone', 'contact_email'])]);
    }

    private function deal(int $id): Invoice
    {
        return Invoice::query()->where('kind', Invoice::KIND_DEAL)->findOrFail($id);
    }
}
