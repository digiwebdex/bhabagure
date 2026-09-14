<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AdminAirInquiry;
use App\Models\Inquiry;
use App\Models\Staff;
use App\Services\Admin\Ownership;
use App\Services\Admin\OwnershipRefused;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The Air ticketing queue (docs/phase-5-admin-core.md §4.7): the website's air-ticket enquiries, oldest open ones first,
 * flagged after 24 hours. Staff contact the customer from their own WhatsApp or email and mark the enquiry quoted.
 * Air quotes themselves (fares, PNRs) are a later module.
 */
class AirInquiryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'state' => ['nullable', Rule::in(['open', 'quoted', 'all'])],
            'stale' => ['nullable', 'boolean'],
            'owner' => ['nullable', Rule::in(['mine', 'pool'])],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        $staff = $request->user('staff');
        $state = $filters['state'] ?? 'open';

        $page = $this->visible($staff)
            ->filtered($filters, $staff)
            ->with(['assignedStaff', 'quotedBy'])
            // Open work oldest first, so the longest wait is on top; quoted history newest first.
            ->when($state === 'open', fn (Builder $q) => $q->oldest('created_at')->oldest('id'), fn (Builder $q) => $q->latest('created_at')->latest('id'))
            ->paginate(30);
        $now = now();

        return response()->json([
            'data' => collect($page->items())->map(fn (Inquiry $inquiry) => AdminAirInquiry::row($inquiry, $staff, $now)),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return $this->one($request, $this->find($request, $id));
    }

    public function claim(Request $request, int $id, Ownership $ownership): JsonResponse
    {
        $inquiry = $this->find($request, $id, 'air_inquiries.manage');
        try {
            $ownership->claim($inquiry, $request->user('staff'));
        } catch (OwnershipRefused $e) {
            return $this->refused("ownership.{$e->reason}", $e->reason);
        }

        return $this->one($request, $inquiry->fresh());
    }

    public function assign(Request $request, int $id, Ownership $ownership): JsonResponse
    {
        $inquiry = $this->find($request, $id, 'records.assign');
        $data = $request->validate([
            'staff_id' => ['present', 'nullable', 'integer', Rule::exists('staff', 'id')->whereNull('deleted_at')],
            'reason' => ['required', 'string', 'min:3', 'max:300'],
        ]);
        try {
            $ownership->assign($inquiry, $data['staff_id'] ? Staff::query()->find($data['staff_id']) : null, $request->user('staff'), $data['reason']);
        } catch (OwnershipRefused $e) {
            return $this->refused("ownership.{$e->reason}", $e->reason, 422);
        }

        return $this->one($request, $inquiry->fresh());
    }

    /** "Mark as quoted": records who and when. Whoever works an unowned enquiry becomes its owner. */
    public function markQuoted(Request $request, int $id, Ownership $ownership, AuditLogger $audit): JsonResponse
    {
        $inquiry = $this->find($request, $id, 'air_inquiries.manage');
        $staff = $request->user('staff');

        $result = DB::transaction(function () use ($inquiry, $staff, $ownership, $audit) {
            $locked = Inquiry::query()->airQuotes()->lockForUpdate()->findOrFail($inquiry->id);
            if ($locked->status !== Inquiry::OPEN) {
                return 'already_quoted';
            }
            if ($locked->assigned_staff_id === null) {
                $ownership->claim($locked, $staff);
            }
            $locked->forceFill(['status' => Inquiry::QUOTED, 'quoted_at' => now(), 'quoted_by_staff_id' => $staff->id])->save();
            $audit->record('inquiry.quoted', $staff, $locked);

            return null;
        });
        if ($result !== null) {
            return $this->refused('inquiries.already_quoted', $result);
        }

        return $this->one($request, $inquiry->fresh());
    }

    /** Back to the open queue — by whoever marked it, or someone who can reassign work. The owner stays. */
    public function undoQuoted(Request $request, int $id, AuditLogger $audit): JsonResponse
    {
        $inquiry = $this->find($request, $id, 'air_inquiries.manage');
        $staff = $request->user('staff');
        if ($inquiry->status !== Inquiry::QUOTED) {
            return $this->refused('inquiries.not_quoted', 'not_quoted');
        }
        abort_unless($inquiry->quoted_by_staff_id === $staff->id || $staff->can('records.assign'), 403, __('auth.forbidden'));

        $inquiry->forceFill(['status' => Inquiry::OPEN, 'quoted_at' => null, 'quoted_by_staff_id' => null])->save();
        $audit->record('inquiry.quote_undone', $staff, $inquiry);

        return $this->one($request, $inquiry->fresh());
    }

    private function visible(Staff $staff): Builder
    {
        return Inquiry::query()->airQuotes()->visibleTo($staff);
    }

    private function find(Request $request, int $id, ?string $permission = null): Inquiry
    {
        $staff = $request->user('staff');
        abort_if($permission !== null && ! $staff->can($permission), 403, __('auth.forbidden'));

        return $this->visible($staff)->findOrFail($id);
    }

    private function one(Request $request, Inquiry $inquiry): JsonResponse
    {
        return response()->json(['data' => AdminAirInquiry::row($inquiry->load(['assignedStaff', 'quotedBy']), $request->user('staff'))]);
    }

    private function refused(string $message, string $code, int $status = 409): JsonResponse
    {
        return response()->json(['message' => __($message), 'code' => $code], $status);
    }
}
