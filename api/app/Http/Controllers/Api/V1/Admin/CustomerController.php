<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\LeadSource;
use App\Http\Controllers\Controller;
use App\Http\Resources\AdminCustomer;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\Staff;
use App\Services\Admin\Ownership;
use App\Services\Admin\OwnershipRefused;
use App\Services\AuditLogger;
use App\Support\Phone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Customers & leads (docs/phase-5-admin-core.md §4.4): the lead board, the customer list, profiles with an append-only
 * contact log, new leads, lost and reopened leads. Visibility is Customer::scopeVisibleTo everywhere; a pool lead is
 * claimed before anyone works it.
 */
class CustomerController extends Controller
{
    /** Cards per board column; the column's count is the full number. */
    private const BOARD_CARDS = 20;

    /** Converted leads stay on the board this long after their booking. */
    private const CONVERTED_DAYS = 30;

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'stage' => ['nullable', Rule::in(['lead', 'customer'])],
            'state' => ['nullable', Rule::in(Customer::LEAD_STATES)],
            'owner' => ['nullable', Rule::in(['mine', 'pool'])],
            'passport' => ['nullable', Rule::in(['missing', 'expiring'])],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        $staff = $request->user('staff');

        $page = AdminCustomer::withRowFacts($this->filtered($staff, $filters))->latest('id')->paginate(30);

        return response()->json([
            'data' => collect($page->items())->map(fn (Customer $c) => AdminCustomer::row($c, $staff)),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    /** The lead board: New · Contacted · Quoted · Converted (last 30 days), each with its full count and the first cards. */
    public function board(Request $request): JsonResponse
    {
        $staff = $request->user('staff');
        $filters = $request->validate(['owner' => ['nullable', Rule::in(['mine', 'pool'])]]);
        $columns = [];

        foreach (['new', 'contacted', 'quoted', 'converted'] as $state) {
            $query = fn () => $this->filtered($staff, $filters + ['state' => $state, 'board' => true]);
            $order = match ($state) {
                'new' => fn (Builder $q) => $q->oldest('customers.created_at'), // the longest wait first
                default => fn (Builder $q) => $q->latest('customers.updated_at'),
            };
            $columns[$state] = [
                'count' => $query()->count(),
                'cards' => $order(AdminCustomer::withRowFacts($query()))->limit(self::BOARD_CARDS)->get()->map(fn (Customer $c) => AdminCustomer::row($c, $staff))->values(),
            ];
        }

        return response()->json(['data' => $columns]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return $this->detail($request, $this->find($request, $id));
    }

    /** A new lead from the office (walk-in, phone, Facebook…). One active customer per phone number. */
    public function store(Request $request, AuditLogger $audit): JsonResponse
    {
        $staff = $request->user('staff');
        abort_unless($staff->can('customers.manage'), 403, __('auth.forbidden'));
        $request->merge(['phone' => Phone::normalizeBdMobile($request->input('phone')) ?? $request->input('phone')]);
        $data = $request->validate($this->rules() + [
            'phone' => ['required', 'regex:/^8801[3-9]\d{8}$/'],
            'source' => ['required', Rule::in(array_map(fn (LeadSource $s) => $s->value, LeadSource::forCustomers()))],
        ]);

        $existing = Customer::query()->where('phone', $data['phone'])->first();
        if ($existing) {
            return response()->json([
                'message' => __('booking.customer_exists'), 'code' => 'customer_exists',
                'customer' => Customer::query()->visibleTo($staff)->whereKey($existing->id)->exists() ? ['id' => $existing->id, 'name' => $existing->name] : null,
            ], Response::HTTP_CONFLICT);
        }
        if (isset($data['email']) && Customer::query()->where('email', $data['email'])->exists()) {
            return response()->json(['message' => __('customers.email_taken'), 'errors' => ['email' => [__('customers.email_taken')]]], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $customer = Customer::query()->create($data + ['stage' => 'lead', 'assigned_staff_id' => $staff->id, 'locale' => $data['locale'] ?? 'bn']);
        $audit->record('customer.created', $staff, $customer, ['source' => $customer->source]);

        return $this->detail($request, $customer, Response::HTTP_CREATED);
    }

    public function update(Request $request, int $id, AuditLogger $audit): JsonResponse
    {
        $customer = $this->find($request, $id, 'customers.manage');
        $data = $request->validate($this->rules($customer));
        $customer->fill($data);
        $changes = array_keys($customer->getDirty());
        $customer->save();
        if ($changes !== []) {
            $audit->record('customer.updated', $request->user('staff'), $customer, ['fields' => $changes]);
        }

        return $this->detail($request, $customer->fresh());
    }

    public function logContact(Request $request, int $id): JsonResponse
    {
        $customer = $this->find($request, $id, 'customers.manage');
        $data = $request->validate([
            'channel' => ['required', Rule::in(CustomerContact::CHANNELS)],
            'outcome' => ['required', Rule::in(CustomerContact::OUTCOMES)],
            'note' => ['nullable', 'string', 'max:2000'],
            'next_follow_up_at' => ['nullable', 'date', 'after:now'],
            'occurred_at' => ['nullable', 'date', 'before_or_equal:now'],
        ]);

        CustomerContact::query()->create([
            'customer_id' => $customer->id, 'staff_id' => $request->user('staff')->id, 'channel' => $data['channel'], 'outcome' => $data['outcome'],
            'note' => $data['note'] ?? null,
            'next_follow_up_at' => isset($data['next_follow_up_at']) ? Carbon::parse($data['next_follow_up_at'])->utc() : null,
            'occurred_at' => isset($data['occurred_at']) ? Carbon::parse($data['occurred_at'])->utc() : now(),
        ]);
        $customer->touch();

        return $this->detail($request, $customer->fresh(), Response::HTTP_CREATED);
    }

    public function markLost(Request $request, int $id, AuditLogger $audit): JsonResponse
    {
        $customer = $this->find($request, $id, 'customers.manage');
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:300']]);
        if ($customer->stage !== 'lead' || $customer->lost_at !== null) {
            return response()->json(['message' => __('customers.not_an_open_lead'), 'code' => 'not_an_open_lead'], Response::HTTP_CONFLICT);
        }

        $customer->forceFill(['lost_at' => now(), 'lost_reason' => $data['reason']])->save();
        $audit->record('customer.lost', $request->user('staff'), $customer, ['reason' => $data['reason']]);

        return $this->detail($request, $customer->fresh());
    }

    public function reopen(Request $request, int $id, AuditLogger $audit): JsonResponse
    {
        $customer = $this->find($request, $id, 'customers.manage');
        if ($customer->lost_at === null) {
            return response()->json(['message' => __('customers.not_lost'), 'code' => 'not_lost'], Response::HTTP_CONFLICT);
        }

        $customer->forceFill(['lost_at' => null, 'lost_reason' => null])->save();
        $audit->record('customer.reopened', $request->user('staff'), $customer);

        return $this->detail($request, $customer->fresh());
    }

    public function claim(Request $request, int $id, Ownership $ownership): JsonResponse
    {
        $customer = $this->find($request, $id, 'customers.manage', claiming: true);
        try {
            $ownership->claim($customer, $request->user('staff'));
        } catch (OwnershipRefused $e) {
            return response()->json(['message' => __("ownership.{$e->reason}"), 'code' => $e->reason], Response::HTTP_CONFLICT);
        }

        return $this->detail($request, $customer->fresh());
    }

    public function assign(Request $request, int $id, Ownership $ownership): JsonResponse
    {
        $customer = $this->find($request, $id, 'records.assign');
        $data = $request->validate([
            'staff_id' => ['present', 'nullable', 'integer', Rule::exists('staff', 'id')->whereNull('deleted_at')],
            'reason' => ['required', 'string', 'min:3', 'max:300'],
        ]);
        try {
            $ownership->assign($customer, $data['staff_id'] ? Staff::query()->find($data['staff_id']) : null, $request->user('staff'), $data['reason']);
        } catch (OwnershipRefused $e) {
            return response()->json(['message' => __("ownership.{$e->reason}"), 'code' => $e->reason], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->detail($request, $customer->fresh());
    }

    /** Only a record made by mistake: no bookings (quotations, when they exist, likewise). The audit log keeps the name. */
    public function destroy(Request $request, int $id, AuditLogger $audit): JsonResponse
    {
        $customer = $this->find($request, $id, 'customers.manage');
        if ($customer->bookings()->withTrashed()->exists()) {
            return response()->json(['message' => __('customers.delete_has_bookings'), 'code' => 'has_bookings'], Response::HTTP_CONFLICT);
        }

        DB::transaction(function () use ($customer, $request, $audit) {
            $customer->delete();
            $audit->record('customer.deleted', $request->user('staff'), $customer, ['name' => $customer->name]);
        });

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * @param  array{stage?: ?string, state?: ?string, owner?: ?string, passport?: ?string, search?: ?string, board?: bool}  $filters
     */
    private function filtered(Staff $staff, array $filters): Builder
    {
        $search = $filters['search'] ?? null;
        $phone = $search !== null ? Phone::normalizeBdMobile($search) : null;
        $today = now('Asia/Dhaka');

        return Customer::query()->visibleTo($staff)
            ->when($filters['stage'] ?? null, fn (Builder $q, string $stage) => $q->where('customers.stage', $stage))
            ->when($filters['state'] ?? null, fn (Builder $q, string $state) => $q->leadState($state))
            // The board shows leads, and a converted lead only while its booking is recent.
            ->when($filters['board'] ?? false, fn (Builder $q) => ($filters['state'] ?? null) === 'converted'
                ? $q->whereHas('bookings', fn (Builder $b) => $b->where('status', '!=', 'cancelled')->where('created_at', '>=', now()->subDays(self::CONVERTED_DAYS)))
                : $q->where('customers.stage', 'lead'))
            ->when(($filters['owner'] ?? null) === 'mine', fn (Builder $q) => $q->where('customers.assigned_staff_id', $staff->id))
            ->when(($filters['owner'] ?? null) === 'pool', fn (Builder $q) => $q->claimable())
            ->when(($filters['passport'] ?? null) === 'missing', fn (Builder $q) => $q->whereDoesntHave('travellerRecords', fn (Builder $t) => $t->whereNotNull('passport_number')))
            ->when(($filters['passport'] ?? null) === 'expiring', fn (Builder $q) => $q->whereHas('travellerRecords', fn (Builder $t) => $t->whereNotNull('passport_number')->whereDate('passport_expiry', '<', $today->copy()->addMonths(6)->toDateString())))
            ->when($search, fn (Builder $q) => $q->where(fn (Builder $w) => $w->where('customers.name', 'like', "%{$search}%")->orWhere('customers.email', 'like', "%{$search}%")
                ->orWhere('customers.phone', 'like', '%'.($phone ?? preg_replace('/\D/', '', $search) ?: $search).'%')));
    }

    /** @return array<string, list<mixed>> */
    private function rules(?Customer $customer = null): array
    {
        return [
            'name' => [$customer ? 'sometimes' : 'required', 'string', 'max:160'],
            'email' => ['nullable', 'email', 'max:190', Rule::unique('customers', 'email')->ignore($customer?->id)->whereNull('deleted_at')],
            'interest' => ['nullable', 'string', 'max:190'],
            'address' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'locale' => ['nullable', Rule::in(['bn', 'en'])],
        ];
    }

    /** A visible customer; for any change one this staff member works (see-all roles, or the owner). */
    private function find(Request $request, int $id, ?string $permission = null, bool $claiming = false): Customer
    {
        $staff = $request->user('staff');
        abort_if($permission !== null && ! $staff->can($permission), 403, __('auth.forbidden'));
        $customer = Customer::query()->visibleTo($staff)->findOrFail($id);

        if ($permission !== null && ! $claiming && ! Customer::seesAll($staff) && $customer->assigned_staff_id !== $staff->id) {
            abort(response()->json(['message' => __('ownership.claim_first'), 'code' => 'claim_first'], Response::HTTP_CONFLICT));
        }

        return $customer;
    }

    private function detail(Request $request, Customer $customer, int $status = Response::HTTP_OK): JsonResponse
    {
        $staff = $request->user('staff');
        $row = AdminCustomer::withRowFacts(Customer::query()->whereKey($customer->id))->firstOrFail();

        return response()->json(['data' => AdminCustomer::detail($row, $staff)], $status);
    }
}
