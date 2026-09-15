<?php

namespace App\Http\Controllers\Api\V1\Admin\Concerns;

use App\Enums\InquiryType;
use App\Enums\NotificationChannel;
use App\Enums\NotificationEvent;
use App\Http\Resources\AdminNotification;
use App\Models\Customer;
use App\Models\Inquiry;
use App\Models\NotificationMessage;
use App\Models\NotificationTemplate;
use App\Models\Staff;
use App\Services\Admin\Ownership;
use App\Services\Admin\OwnershipRefused;
use App\Services\AuditLogger;
use App\Services\Notifications\MessageRenderer;
use App\Services\Notifications\NotificationPlanner;
use App\Services\Notifications\NotificationSettings;
use App\Services\Notifications\NotificationVariables;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;

/**
 * A quotation-request queue: the website's air-ticket enquiries (Air ticketing, docs/phase-5-admin-core.md §4.7) or hotel
 * quotation requests (Hotel requests, docs/phase-8-visa-quotes-pricing-downloads.md §4.B). Oldest open requests first,
 * flagged after 24 hours; claimed from the pool, answered with a reply the customer gets by WhatsApp and email, and
 * marked quoted. The routes require `<queue>.view`; working a request needs `<queue>.manage`.
 */
trait WorksQuoteRequests
{
    abstract protected function type(): InquiryType;

    /** @return array<string, mixed> */
    abstract protected function present(Inquiry $inquiry, Staff $viewer, ?Carbon $now = null): array;

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
            ->withCount('replies')->withMax('replies', 'created_at')
            // Open work oldest first, so the longest wait is on top; quoted history newest first.
            ->when($state === 'open', fn (Builder $q) => $q->oldest('created_at')->oldest('id'), fn (Builder $q) => $q->latest('created_at')->latest('id'))
            ->paginate(30);
        $now = now();

        return response()->json([
            'data' => collect($page->items())->map(fn (Inquiry $inquiry) => $this->present($inquiry, $staff, $now)),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    /** The request with the replies sent to the customer, each with its WhatsApp and email status. */
    public function show(Request $request, int $id): JsonResponse
    {
        $inquiry = $this->find($request, $id);
        $messages = NotificationMessage::query()->with('recipient')->where('event', NotificationEvent::InquiryReply->value)
            ->where('related_type', $inquiry->getMorphClass())->where('related_id', $inquiry->id)->oldest('id')->get();
        $locale = $inquiry->locale ?? 'bn';
        $template = NotificationTemplate::query()->where('event', NotificationEvent::InquiryReply->value)->where('channel', NotificationChannel::WhatsApp->value)->first();

        return response()->json(['data' => $this->row($request, $inquiry) + [
            'notification_groups' => AdminNotification::groups($messages),
            // The reply box says when WhatsApp can't go out (the email still does) and previews the WhatsApp exactly: the
            // sender line, then the template around the typed text, which stands in for {{reply}}.
            'notifications_number_published' => NotificationSettings::notificationsNumber() !== null,
            'notifications_sender_line' => MessageRenderer::senderLine(),
            'reply_template' => $template
                ? app(MessageRenderer::class)->fill($template->body($locale), app(NotificationVariables::class)->for(NotificationEvent::InquiryReply, $inquiry, $locale, NotificationChannel::WhatsApp, ['reply' => '{{reply}}']))
                : '{{reply}}',
            'customer_opted_out' => $inquiry->customer?->whatsapp_opted_out_at !== null,
        ]]);
    }

    public function claim(Request $request, int $id, Ownership $ownership): JsonResponse
    {
        $inquiry = $this->find($request, $id, 'manage');
        try {
            $ownership->claim($inquiry, $request->user('staff'));
        } catch (OwnershipRefused $e) {
            return $this->refused("ownership.{$e->reason}", $e->reason);
        }

        return $this->one($request, $inquiry->fresh());
    }

    public function assign(Request $request, int $id, Ownership $ownership): JsonResponse
    {
        $staff = $request->user('staff');
        abort_unless($staff->can('records.assign'), 403, __('auth.forbidden'));
        $inquiry = $this->find($request, $id);
        $data = $request->validate([
            'staff_id' => ['present', 'nullable', 'integer', Rule::exists('staff', 'id')->whereNull('deleted_at')],
            'reason' => ['required', 'string', 'min:3', 'max:300'],
        ]);
        try {
            $ownership->assign($inquiry, $data['staff_id'] ? Staff::query()->find($data['staff_id']) : null, $staff, $data['reason']);
        } catch (OwnershipRefused $e) {
            return $this->refused("ownership.{$e->reason}", $e->reason, 422);
        }

        return $this->one($request, $inquiry->fresh());
    }

    /** "Mark as quoted": records who and when. Whoever works an unowned request becomes its owner. */
    public function markQuoted(Request $request, int $id, Ownership $ownership, AuditLogger $audit): JsonResponse
    {
        $inquiry = $this->find($request, $id, 'manage');
        $result = $this->quote($inquiry, $request->user('staff'), $ownership, $audit);
        if ($result !== null) {
            return $this->refused('inquiries.already_quoted', $result);
        }

        return $this->one($request, $inquiry->fresh());
    }

    /** Back to the open queue — by whoever marked it, or someone who can reassign work. The owner stays. */
    public function undoQuoted(Request $request, int $id, AuditLogger $audit): JsonResponse
    {
        $inquiry = $this->find($request, $id, 'manage');
        $staff = $request->user('staff');
        if ($inquiry->status !== Inquiry::QUOTED) {
            return $this->refused('inquiries.not_quoted', 'not_quoted');
        }
        abort_unless($inquiry->quoted_by_staff_id === $staff->id || $staff->can('records.assign'), 403, __('auth.forbidden'));

        $inquiry->forceFill(['status' => Inquiry::OPEN, 'quoted_at' => null, 'quoted_by_staff_id' => null])->save();
        $audit->record('inquiry.quote_undone', $staff, $inquiry);

        return $this->one($request, $inquiry->fresh());
    }

    /**
     * A reply to the customer: WhatsApp from the notifications number and email, to the number and address on the form,
     * each logged with its delivery status. Replying to an unowned request claims it; `mark_quoted` also marks it quoted.
     * A reply is sent even while WhatsApp can't deliver — the email still goes, and the log says why WhatsApp didn't.
     */
    public function reply(Request $request, int $id, NotificationPlanner $planner, Ownership $ownership, AuditLogger $audit): JsonResponse
    {
        $inquiry = $this->find($request, $id, 'manage');
        $staff = $request->user('staff');
        abort_unless($staff->can('notifications.send'), 403, __('auth.forbidden'));
        $data = $request->validate([
            'text' => ['required', 'string', 'min:2', 'max:1000'],
            'mark_quoted' => ['boolean'],
        ]);
        if ($inquiry->assigned_staff_id !== null && $inquiry->assigned_staff_id !== $staff->id && ! Inquiry::seesAll($staff)) {
            return $this->refused('ownership.claim_first', 'claim_first');
        }

        foreach ([['staff-whatsapp-minute:'.$staff->id, 'per_minute', 60], ['staff-whatsapp-day:'.$staff->id, 'per_day', 86400]] as [$key, $limit, $decay]) {
            if (RateLimiter::tooManyAttempts($key, (int) config("bhabaghure.notifications.staff_send.{$limit}"))) {
                return response()->json(['message' => __('notifications.too_many', ['seconds' => RateLimiter::availableIn($key)]), 'code' => 'rate_limited'], 429);
            }
        }
        RateLimiter::hit('staff-whatsapp-minute:'.$staff->id, 60);
        RateLimiter::hit('staff-whatsapp-day:'.$staff->id, 86400);

        try {
            DB::transaction(function () use ($inquiry, $staff, $data, $planner, $ownership, $audit) {
                if ($inquiry->assigned_staff_id === null) {
                    $ownership->claim($inquiry, $staff);
                }
                // Requests from before every enquiry got a lead record: the reply's recipient is that record.
                if ($inquiry->customer_id === null) {
                    $inquiry->forceFill(['customer_id' => Customer::leadForEnquiry($inquiry->phone, $inquiry->name, $inquiry->email, $inquiry->locale ?? 'bn')])->save();
                    $inquiry->unsetRelation('customer');
                }
                $rows = $planner->inquiryReplied($inquiry, trim($data['text']), $staff);
                $audit->record('inquiry.replied', $staff, $inquiry, ['notifications' => array_map(fn (NotificationMessage $row) => $row->id, $rows)]);
                if ($data['mark_quoted'] ?? false) {
                    $this->quote($inquiry, $staff, $ownership, $audit);
                }
            });
        } catch (OwnershipRefused $e) {
            return $this->refused("ownership.{$e->reason}", $e->reason);
        }

        return $this->show($request, $inquiry->id);
    }

    /** @return string|null why it wasn't marked, or null once it is */
    private function quote(Inquiry $inquiry, Staff $staff, Ownership $ownership, AuditLogger $audit): ?string
    {
        return DB::transaction(function () use ($inquiry, $staff, $ownership, $audit) {
            $locked = Inquiry::query()->ofType($this->type())->lockForUpdate()->findOrFail($inquiry->id);
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
    }

    private function visible(Staff $staff): Builder
    {
        return Inquiry::query()->ofType($this->type())->visibleTo($staff);
    }

    /** @param  'manage'|null  $ability */
    private function find(Request $request, int $id, ?string $ability = null): Inquiry
    {
        $staff = $request->user('staff');
        abort_if($ability !== null && ! $staff->can("{$this->type()->queuePermission()}.{$ability}"), 403, __('auth.forbidden'));

        return $this->visible($staff)->findOrFail($id);
    }

    /** @return array<string, mixed> */
    private function row(Request $request, Inquiry $inquiry): array
    {
        $inquiry->load(['assignedStaff', 'quotedBy'])->loadCount('replies')->loadMax('replies', 'created_at');

        return $this->present($inquiry, $request->user('staff'));
    }

    private function one(Request $request, Inquiry $inquiry): JsonResponse
    {
        return response()->json(['data' => $this->row($request, $inquiry)]);
    }

    private function refused(string $message, string $code, int $status = 409): JsonResponse
    {
        return response()->json(['message' => __($message), 'code' => $code], $status);
    }
}
