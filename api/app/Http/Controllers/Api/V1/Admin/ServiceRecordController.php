<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AdminServiceRecord;
use App\Models\ServiceRecord;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The Tickets of Air ticketing and the Bookings of Hotel requests (docs/invoice-services.md): what issued invoices sold.
 * Read with the queue's own `.view` permission; changed with its `.manage`, or by anyone who works invoices, since the
 * invoice shows and changes the same record.
 */
class ServiceRecordController extends Controller
{
    private const KINDS = ['air' => ServiceRecord::AIR, 'hotel' => ServiceRecord::HOTEL];

    private const QUEUES = ['air' => 'air_inquiries', 'hotel' => 'hotel_inquiries'];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * GET admin/service-records?kind=air|hotel&filter=&search=&page=: still to come by default, the soonest first, so
     * the next departure is on top; what has passed or been cancelled newest first.
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(array_keys(self::KINDS))],
            'filter' => ['nullable', Rule::in(['upcoming', 'soon', 'changed', 'past', 'cancelled', 'all'])],
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $staff = $request->user('staff');
        abort_unless($staff->can(self::QUEUES[$data['kind']].'.view'), 403, __('auth.forbidden'));

        $type = self::KINDS[$data['kind']];
        $today = now('Asia/Dhaka')->toDateString();
        $soonUntil = now('Asia/Dhaka')->addDays(ServiceRecord::SOON_DAYS)->toDateString();
        $filter = $data['filter'] ?? 'upcoming';
        $search = trim((string) ($data['search'] ?? ''));
        $like = '%'.addcslashes($search, '%_\\').'%';
        $base = fn () => ServiceRecord::query()->where('type', $type);

        $page = $base()
            ->with(['invoice.customer:id,locale', 'updatedBy:id,name'])
            ->when($filter === 'upcoming', fn (Builder $q) => $q->sold()->whereDate('starts_on', '>=', $today))
            ->when($filter === 'soon', fn (Builder $q) => $q->sold()->whereDate('starts_on', '>=', $today)->whereDate('starts_on', '<=', $soonUntil))
            ->when($filter === 'changed', fn (Builder $q) => $q->sold()->where('status', ServiceRecord::SCHEDULE_CHANGED))
            ->when($filter === 'past', fn (Builder $q) => $q->sold()->whereDate('starts_on', '<', $today))
            ->when($filter === 'cancelled', fn (Builder $q) => $q->cancelled())
            ->when($filter === 'all', fn (Builder $q) => $q->where(fn (Builder $inner) => $inner->sold()->orWhere(fn (Builder $void) => $void->cancelled())))
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $inner) => $inner
                ->where('reference', 'like', $like)->orWhere('passenger_name', 'like', $like)
                ->orWhere('route_from', 'like', $like)->orWhere('route_to', 'like', $like)->orWhere('hotel_name', 'like', $like)
                ->orWhereHas('invoice', fn (Builder $invoice) => $invoice->where('billed_name', 'like', $like)->orWhere('billed_phone', 'like', $like)->orWhere('invoice_number', 'like', $like))))
            ->when(in_array($filter, ['upcoming', 'soon', 'changed'], true), fn (Builder $q) => $q->orderBy('starts_on')->orderBy('id'), fn (Builder $q) => $q->orderByDesc('starts_on')->orderByDesc('id'))
            ->paginate(30);

        return response()->json([
            'data' => collect($page->items())->map(fn (ServiceRecord $record) => AdminServiceRecord::row($record, $staff, $today)),
            'meta' => [
                'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(),
                // On the filters, so what needs doing shows from any of them.
                'counts' => [
                    'soon' => $base()->sold()->whereDate('starts_on', '>=', $today)->whereDate('starts_on', '<=', $soonUntil)->count(),
                    'changed' => $base()->sold()->where('status', ServiceRecord::SCHEDULE_CHANGED)->count(),
                ],
            ],
        ]);
    }

    /**
     * PUT admin/service-records/{id}: the status, and with it a PNR that has come through or a new flight time. Schedule
     * changed needs a note saying what the new schedule is: it is what the customer's WhatsApp message tells them.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $record = ServiceRecord::query()->with(['invoice.customer:id,locale', 'updatedBy:id,name'])->whereIn('type', self::KINDS)->findOrFail($id);
        $staff = $request->user('staff');
        abort_unless($staff->canAny(["{$record->queuePermission()}.manage", 'invoices.manage']), 403, __('auth.forbidden'));
        if ($record->invoice->status === 'void') {
            return response()->json(['message' => __('services.invoice_cancelled'), 'code' => 'invoice_cancelled'], 409);
        }

        $air = $record->type === ServiceRecord::AIR;
        $data = $request->validate([
            'status' => ['required', Rule::in(ServiceRecord::statusesFor($record->type))],
            'reference' => ['nullable', 'string', 'max:60'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => [$air && $record->trip_type === ServiceRecord::ONE_WAY ? 'nullable' : 'required', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'schedule_note' => [$air ? 'required_if:status,'.ServiceRecord::SCHEDULE_CHANGED : 'nullable', 'nullable', 'string', 'max:1000'],
        ], [
            'schedule_note.required_if' => __('services.schedule_note_required'),
            'starts_on.required' => __('services.field.starts_on'),
            'ends_on.required' => __('services.field.ends_on'),
            'ends_on.after_or_equal' => __('services.field.ends_after'),
        ]);

        $before = AdminServiceRecord::fields($record);
        $changed = $data['status'] === ServiceRecord::SCHEDULE_CHANGED;
        $note = $changed ? trim((string) $data['schedule_note']) : null;
        $reference = trim((string) ($data['reference'] ?? ''));
        $record->fill([
            'status' => $data['status'],
            'reference' => $reference === '' ? null : ($air ? mb_strtoupper($reference) : $reference),
            'starts_on' => $data['starts_on'],
            'ends_on' => $air && $record->trip_type === ServiceRecord::ONE_WAY ? null : ($data['ends_on'] ?? null),
            'schedule_note' => $note,
            'schedule_changed_at' => ! $changed ? null : ($note !== $record->schedule_note ? now() : $record->schedule_changed_at),
            'updated_by_staff_id' => $staff->id,
        ])->save();
        $this->audit->record('service_record.updated', $staff, $record, ['before' => $before, 'after' => AdminServiceRecord::fields($record)]);

        return response()->json(['data' => AdminServiceRecord::row($record->load('updatedBy:id,name'), $staff)]);
    }
}
