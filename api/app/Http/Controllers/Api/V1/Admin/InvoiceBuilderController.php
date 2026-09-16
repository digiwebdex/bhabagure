<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\LeadSource;
use App\Enums\NotificationChannel;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\SiteSetting;
use App\Models\Transaction;
use App\Services\AuditLogger;
use App\Services\Invoices\InvoiceBuilder;
use App\Services\Ledger\LedgerService;
use App\Services\Notifications\NotificationPlanner;
use App\Support\Phone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use LogicException;

/**
 * Invoices staff write themselves (docs/phase-9-accounts.md §5): the list with its tabs and filters, and the builder
 * behind it. A booking's own invoice is issued from the booking and only appears here to be read.
 */
class InvoiceBuilderController extends Controller
{
    public function __construct(private readonly InvoiceBuilder $builder, private readonly AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'state' => ['nullable', Rule::in(['all', 'draft', 'unpaid', 'partial', 'paid', 'overdue', 'void'])],
            'customer_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $today = now('Asia/Dhaka')->toDateString();
        $state = $filters['state'] ?? 'all';

        $query = Invoice::query()->with(['customer:id,name,phone,email', 'issuedBy:id,name', 'updatedBy:id,name'])
            ->when($filters['customer_id'] ?? null, fn (Builder $q, int $id) => $q->where('customer_id', $id))
            ->when($filters['from'] ?? null, fn (Builder $q, string $from) => $q->where('issued_on', '>=', $from))
            ->when($filters['to'] ?? null, fn (Builder $q, string $to) => $q->where('issued_on', '<=', $to))
            ->when($filters['search'] ?? null, fn (Builder $q, string $search) => $q->where(fn (Builder $inner) => $inner
                ->where('invoice_number', 'like', "%{$search}%")->orWhere('billed_name', 'like', "%{$search}%")
                ->orWhere('title', 'like', "%{$search}%")->orWhere('booking_reference', 'like', "%{$search}%")))
            ->when($state !== 'all', fn (Builder $q) => match ($state) {
                'draft' => $q->where('status', Invoice::DRAFT),
                'void' => $q->where('status', Invoice::VOID),
                'overdue' => $q->where('status', Invoice::ISSUED)->where('payment_status', '!=', 'paid')->whereNotNull('due_on')->where('due_on', '<', $today),
                default => $q->where('status', Invoice::ISSUED)->where('payment_status', $state),
            });

        $page = (clone $query)->latest('id')->paginate(25);
        $counts = Invoice::query()->selectRaw('
            COUNT(*) AS total,
            SUM(status = ?) AS draft,
            SUM(status = ? AND payment_status = ?) AS unpaid,
            SUM(status = ? AND payment_status = ?) AS partial,
            SUM(status = ? AND payment_status = ?) AS paid,
            SUM(status = ? AND payment_status <> ? AND due_on IS NOT NULL AND due_on < ?) AS overdue
        ', [Invoice::DRAFT, Invoice::ISSUED, 'unpaid', Invoice::ISSUED, 'partial', Invoice::ISSUED, 'paid', Invoice::ISSUED, 'paid', $today])->first();

        return response()->json([
            'data' => collect($page->items())->map(fn (Invoice $invoice) => self::row($invoice, $today)),
            'meta' => [
                'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(),
                'tabs' => [
                    'all' => (int) $counts->total, 'draft' => (int) $counts->draft, 'unpaid' => (int) $counts->unpaid,
                    'partial' => (int) $counts->partial, 'paid' => (int) $counts->paid, 'overdue' => (int) $counts->overdue,
                ],
                'totals' => [
                    'invoiced' => (float) (clone $query)->where('status', Invoice::ISSUED)->sum('total_amount'),
                    'due' => (float) (clone $query)->where('status', Invoice::ISSUED)->sum('balance_due'),
                ],
            ],
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $invoice = Invoice::query()->with(['items', 'customer:id,name,phone,email', 'issuedBy:id,name', 'updatedBy:id,name'])->findOrFail($id);

        return response()->json(['data' => self::detail($invoice) + [
            // What has been paid against it, the way the invoice view lists it: date, how, how much, and the reference.
            'payments' => $invoice->transactions()->where('category', LedgerService::CATEGORY_PAYMENT)->with('reversal:id,reverses_transaction_id')->get()
                ->map(fn (Transaction $payment) => [
                    'id' => $payment->id,
                    'date' => $payment->occurred_at?->timezone('Asia/Dhaka')->toDateString(),
                    'method' => $payment->method,
                    'amount' => (float) $payment->amount,
                    'note' => $payment->external_ref ?? $payment->reference_label,
                    'reversed' => $payment->reversal !== null,
                ])->values(),
            // The letterhead the printed invoice carries, so the on-screen view reads the same.
            'company' => [
                'name' => SiteSetting::get('company', [])['name']['en'] ?? 'Bhabaghure Holidays Aviation',
                'address' => SiteSetting::get('address', ''),
                'email' => SiteSetting::get('contact', [])['email'] ?? null,
                'phone' => implode(', ', array_filter([SiteSetting::get('contact', [])['phone'] ?? null, SiteSetting::get('contact', [])['phoneAlt'] ?? null])),
            ],
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $invoice = $this->builder->createDraft($this->party($data), $data, $request->user('staff'));

        return response()->json(['data' => self::detail($invoice->load('items'))], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $invoice = Invoice::query()->findOrFail($id);
        $data = $this->validated($request);

        try {
            $updated = $this->builder->updateDraft($invoice, $this->party($data), $data, $request->user('staff'));
        } catch (LogicException $e) {
            return response()->json(['message' => __('invoices.issued_frozen'), 'code' => 'invoice_issued'], 409);
        }

        return response()->json(['data' => self::detail($updated->load('items'))]);
    }

    public function issue(Request $request, int $id): JsonResponse
    {
        $invoice = Invoice::query()->findOrFail($id);

        try {
            $issued = $this->builder->issue($invoice, $request->user('staff'));
        } catch (LogicException $e) {
            return response()->json(['message' => __('invoices.needs_a_line'), 'code' => 'invoice_empty'], 409);
        }

        return response()->json(['data' => self::detail($issued->load('items'))]);
    }

    /**
     * A draft nobody wants, thrown away. An issued invoice is never deleted — the customer has a copy and the books
     * have the entry — so that one is voided on its deal instead, which reverses the journal (§5).
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $invoice = Invoice::query()->findOrFail($id);
        if ($invoice->status !== Invoice::DRAFT) {
            return response()->json(['message' => __('invoices.issued_no_delete'), 'code' => 'invoice_issued'], 409);
        }

        $this->audit->record('invoice.draft_deleted', $request->user('staff'), $invoice, ['title' => $invoice->title]);
        $invoice->items()->delete();
        $invoice->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    /**
     * A reminder of what is still owed, in the staff member's own words, by SMS or email (docs/phase-9-accounts.md §5).
     * It goes through the same pipeline as every other message, so the Notifications log shows whether it arrived.
     */
    public function remind(Request $request, int $id, NotificationPlanner $planner): JsonResponse
    {
        $staff = $request->user('staff');
        abort_unless($staff->can('notifications.send'), 403, __('auth.forbidden'));
        $invoice = Invoice::query()->with('customer')->findOrFail($id);
        $data = $request->validate([
            'channels' => ['required', 'array', 'min:1'],
            'channels.*' => [Rule::in(['sms', 'email'])],
            'text' => ['required', 'string', 'min:5', 'max:1000'],
            'subject' => ['nullable', 'string', 'max:160'],
            'email' => ['nullable', 'email', 'max:190'],
        ]);
        if ($invoice->status !== Invoice::ISSUED) {
            return response()->json(['message' => __('invoices.reminder_needs_issue'), 'code' => 'invoice_not_issued'], 409);
        }

        $channels = array_map(fn (string $channel) => NotificationChannel::from($channel), array_values(array_unique($data['channels'])));
        $rows = DB::transaction(function () use ($planner, $invoice, $data, $staff, $channels) {
            $rows = $planner->paymentReminder($invoice, trim($data['text']), $data['subject'] ?? null, $staff, $channels, $data['email'] ?? null);
            $this->audit->record('invoice.reminder_sent', $staff, $invoice, ['channels' => array_map(fn ($row) => $row->channel->value, $rows)]);

            return $rows;
        });

        // Nothing sent means we have no number or address on file for that channel — say so rather than claim success.
        if ($rows === []) {
            return response()->json(['message' => __('invoices.reminder_no_address'), 'code' => 'no_address'], 422);
        }

        return response()->json(['data' => ['sent' => array_map(fn ($row) => $row->channel->value, $rows)]], 201);
    }

    /**
     * Who the invoice is for. Every invoice belongs to a customer or a B2B client — the invoices table insists on it,
     * so that what is owed can always be traced to someone. A name and number typed here make the customer record, or
     * find the one that number already belongs to.
     *
     * @param  array<string, mixed>  $data
     */
    private function party(array $data): Customer|Client
    {
        if (isset($data['client_id'])) {
            return Client::query()->findOrFail($data['client_id']);
        }
        if (isset($data['customer_id'])) {
            return Customer::query()->findOrFail($data['customer_id']);
        }

        $phone = Phone::normalizeBdMobile($data['customer']['phone']) ?? $data['customer']['phone'];

        return Customer::query()->firstOrCreate(['phone' => $phone], [
            'name' => trim($data['customer']['name']),
            'email' => $data['customer']['email'] ?? null,
            'stage' => 'customer',
            'source' => LeadSource::WalkIn->value,
        ]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'customer_id' => ['required_without_all:client_id,customer', 'nullable', 'integer', 'exists:customers,id', 'prohibits:client_id,customer'],
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            // Someone not on file yet: their name and number make the customer record the invoice is billed to.
            'customer' => ['nullable', 'array'],
            'customer.name' => ['required_with:customer', 'string', 'max:160'],
            'customer.phone' => ['required_with:customer', 'regex:/^8801[3-9]\d{8}$/'],
            'customer.email' => ['nullable', 'email', 'max:190'],
            'title' => ['required', 'string', 'max:160'],
            'po_number' => ['nullable', 'string', 'max:60'],
            'note' => ['nullable', 'string', 'max:1000'],
            'footer' => ['nullable', 'string', 'max:500'],
            'due_on' => ['nullable', 'date_format:Y-m-d'],
            'discount_label' => ['nullable', 'string', 'max:60'],
            'discount_amount' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'delivery_charge' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'vat_rate' => ['nullable', 'numeric', 'between:0,100'],
            'lines' => ['required', 'array', 'min:1', 'max:50'],
            'lines.*.title' => ['required', 'string', 'max:160'],
            'lines.*.detail' => ['nullable', 'string', 'max:300'],
            'lines.*.note' => ['nullable', 'string', 'max:300'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:99999'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'lines.*.discount_amount' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'lines.*.vat_rate' => ['nullable', 'numeric', 'between:0,100'],
        ]);
    }

    /** @return array<string, mixed> */
    private static function row(Invoice $invoice, string $today): array
    {
        $overdue = $invoice->status === Invoice::ISSUED && $invoice->payment_status !== 'paid' && $invoice->due_on !== null && $invoice->due_on->toDateString() < $today;

        return [
            'id' => $invoice->id,
            'number' => $invoice->invoice_number,
            'kind' => $invoice->kind,
            'title' => $invoice->title ?? $invoice->package_title_en,
            'customer' => $invoice->customer ? ['id' => $invoice->customer->id, 'name' => $invoice->customer->name, 'phone' => $invoice->customer->phone, 'email' => $invoice->customer->email] : null,
            'billed_name' => $invoice->billed_name,
            'issued_on' => $invoice->issued_on?->toDateString(),
            'due_on' => $invoice->due_on?->toDateString(),
            // Who wrote it and who last touched it, as the invoice screen shows under the number.
            'created_by' => $invoice->sales_agent_name ?? $invoice->issuedBy?->name,
            'updated_by' => $invoice->updatedBy?->name,
            // Days late, counted from the due date forward: Carbon's own difference is signed and would read negative.
            'days_overdue' => $overdue ? (int) $invoice->due_on->startOfDay()->diffInDays(Carbon::parse($today)) : 0,
            'total' => (float) $invoice->total_amount,
            'paid' => (float) $invoice->paid_amount,
            'due' => (float) $invoice->balance_due,
            'status' => $invoice->status,
            'payment_status' => $invoice->payment_status,
            'overdue' => $overdue,
            'booking_id' => $invoice->booking_id,
            'actions' => [
                'edit' => $invoice->status === Invoice::DRAFT,
                'issue' => $invoice->status === Invoice::DRAFT,
                'pay' => $invoice->status === Invoice::ISSUED && LedgerService::paisa($invoice->balance_due) > 0 && $invoice->booking_id === null,
                'void' => $invoice->status === Invoice::ISSUED && LedgerService::paisa($invoice->paid_amount) === 0 && $invoice->booking_id === null,
                'share' => $invoice->status !== Invoice::DRAFT,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function detail(Invoice $invoice): array
    {
        return self::row($invoice, now('Asia/Dhaka')->toDateString()) + [
            'note' => $invoice->note,
            'footer' => $invoice->footer,
            'po_number' => $invoice->po_number,
            'discount_label' => $invoice->discount_label,
            'discount_amount' => (float) $invoice->discount_amount,
            'delivery_charge' => (float) $invoice->delivery_charge,
            'subtotal' => (float) $invoice->subtotal_amount,
            'vat_rate' => (float) $invoice->vat_rate,
            'vat_amount' => (float) $invoice->vat_amount,
            'customer_id' => $invoice->customer_id,
            'client_id' => $invoice->client_id,
            'lines' => $invoice->items->sortBy('sort_order')->map(InvoiceBuilder::line(...))->values(),
            // The customer's own copy: the same link the portal and the messages use.
            'share_url' => $invoice->status === Invoice::DRAFT ? null : url("/api/v1/public/invoices/{$invoice->share_token}"),
            'pdf_url' => $invoice->status === Invoice::DRAFT ? null : url("/api/v1/public/invoices/{$invoice->share_token}/pdf"),
        ];
    }
}
