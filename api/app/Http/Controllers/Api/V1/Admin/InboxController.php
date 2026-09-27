<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\StaffStatus;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\CannedReply;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Customer;
use App\Models\Staff;
use App\Services\Inbox\InboxDesk;
use App\Services\Inbox\InboxFiles;
use App\Support\Money;
use App\Support\Phone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Communication → Inbox (docs/admin-inbox.md): customers' WhatsApp and Messenger chats. Reading needs inbox.view;
 * replying, assigning to oneself, closing and linking a customer inbox.reply (admins and sales agents, decided
 * 2026-09-27); assigning to someone else inbox.manage.
 */
class InboxController extends Controller
{
    public const VIEWS = ['all', 'unread', 'mine', 'closed'];

    public function index(Request $request): JsonResponse
    {
        /** @var Staff $staff */
        $staff = $request->user('staff');
        $filters = $request->validate([
            'view' => ['nullable', Rule::in(self::VIEWS)],
            'channel' => ['nullable', Rule::in(Conversation::CHANNELS)],
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $page = self::filtered($filters, $staff)->with(['assignee', 'customer'])->orderByDesc('last_message_at')->orderByDesc('id')->paginate(30);

        return response()->json([
            'data' => collect($page->items())->map(self::row(...))->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                // The filter chips' numbers, for the channel picked.
                'counts' => collect(self::VIEWS)->mapWithKeys(fn (string $view) => [$view => self::filtered(['view' => $view, 'channel' => $filters['channel'] ?? null], $staff)->count()]),
                'unread_by_channel' => collect(Conversation::CHANNELS)->mapWithKeys(fn (string $channel) => [$channel => self::filtered(['view' => 'unread', 'channel' => $channel], $staff)->count()]),
            ],
        ]);
    }

    /** The list under the inbox's filters — also the sidebar badge (NavBadges, unread). */
    public static function filtered(array $filters, Staff $staff): Builder
    {
        return Conversation::query()->filtered($filters, $staff);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        /** @var Staff $staff */
        $staff = $request->user('staff');
        $conversation = Conversation::query()->with(['assignee', 'customer'])->findOrFail($id);
        $before = $request->integer('before') ?: null;
        $messages = $conversation->messages()->with('staff')
            ->when($before !== null, fn ($q) => $q->where('id', '<', $before))
            ->orderByDesc('id')->limit(60)->get()->reverse()->values();

        return response()->json(['data' => self::row($conversation) + [
            'can_reply' => $conversation->canReply(),
            'reply_window_ends_at' => $conversation->channel === Conversation::MESSENGER ? $conversation->last_incoming_at?->copy()->addHours(Conversation::MESSENGER_WINDOW_HOURS)->toIso8601String() : null,
            'customer' => $this->customer($conversation, $staff),
            'messages' => $messages->map(self::message(...))->all(),
            'has_older' => $messages->isNotEmpty() && $conversation->messages()->where('id', '<', $messages->first()->id)->exists(),
            // Who the chat can be handed to: only for those who may hand it over.
            'assignees' => $staff->can('inbox.manage') ? self::assignees() : null,
        ]]);
    }

    public function reply(Request $request, int $id, InboxDesk $desk): JsonResponse
    {
        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:4000'],
            'file' => ['nullable', 'file', 'mimes:'.implode(',', InboxFiles::MIMES), 'max:'.config('bhabaghure.notifications.inbox.max_attachment_kb')],
        ]);
        $conversation = Conversation::query()->findOrFail($id);
        $message = $desk->reply($conversation, $request->user('staff'), $data['body'] ?? null, $request->file('file'));

        return response()->json(['data' => self::message($message->load('staff'))], Response::HTTP_CREATED);
    }

    public function read(int $id, InboxDesk $desk): JsonResponse
    {
        $desk->markRead(Conversation::query()->findOrFail($id));

        return response()->json(['data' => null]);
    }

    public function assign(Request $request, int $id, InboxDesk $desk): JsonResponse
    {
        /** @var Staff $staff */
        $staff = $request->user('staff');
        $data = $request->validate(['staff_id' => ['nullable', 'integer', Rule::exists('staff', 'id')]]);
        $to = isset($data['staff_id']) ? Staff::query()->findOrFail($data['staff_id']) : null;
        // Taking a chat or letting it go is anyone's; handing it to a colleague is inbox.manage.
        abort_unless(
            $staff->can('inbox.manage') || ($to?->id ?? $staff->id) === $staff->id,
            Response::HTTP_FORBIDDEN, __('auth.forbidden'),
        );
        abort_if($to !== null && ! $to->can('inbox.view'), Response::HTTP_UNPROCESSABLE_ENTITY, __('auth.forbidden'));
        $conversation = Conversation::query()->findOrFail($id);
        $desk->assign($conversation, $to, $staff);

        return $this->show($request, $id);
    }

    public function status(Request $request, int $id, string $action, InboxDesk $desk): JsonResponse
    {
        $desk->setStatus(Conversation::query()->findOrFail($id), $action === 'close' ? Conversation::CLOSED : Conversation::OPEN, $request->user('staff'));

        return $this->show($request, $id);
    }

    public function linkCustomer(Request $request, int $id, InboxDesk $desk): JsonResponse
    {
        /** @var Staff $staff */
        $staff = $request->user('staff');
        abort_unless($staff->can('customers.view'), Response::HTTP_FORBIDDEN, __('auth.forbidden'));
        $data = $request->validate(['customer_id' => ['nullable', 'integer']]);
        $customer = isset($data['customer_id']) ? Customer::query()->visibleTo($staff)->findOrFail($data['customer_id']) : null;
        $desk->link(Conversation::query()->findOrFail($id), $customer, $staff);

        return $this->show($request, $id);
    }

    public function createLead(Request $request, int $id, InboxDesk $desk): JsonResponse
    {
        /** @var Staff $staff */
        $staff = $request->user('staff');
        abort_unless($staff->can('customers.manage'), Response::HTTP_FORBIDDEN, __('auth.forbidden'));
        $request->merge(['phone' => Phone::normalizeBdMobile((string) $request->input('phone')) ?? $request->input('phone')]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'regex:/^8801[3-9]\d{8}$/'],
        ]);
        $desk->createLead(Conversation::query()->findOrFail($id), trim($data['name']), $data['phone'], $staff);

        return $this->show($request, $id);
    }

    public function file(int $id): HttpResponse
    {
        $message = ConversationMessage::query()->whereNotNull('attachment_path')->findOrFail($id);

        return self::serve($message);
    }

    /** GET /api/v1/public/inbox-files/{message}/{name}: the signed link WhatsApp or Messenger fetches a staff attachment from. */
    public function signedFile(int $message): HttpResponse
    {
        $row = ConversationMessage::query()->where('origin', ConversationMessage::STAFF)->whereNotNull('attachment_path')->findOrFail($message);

        return self::serve($row);
    }

    private static function serve(ConversationMessage $message): HttpResponse
    {
        $name = Str::ascii(preg_replace('/[^\w.\- ]+/u', '_', (string) $message->attachment_name) ?: "file-{$message->id}");

        return response(InboxFiles::contents($message))
            ->header('Content-Type', $message->attachment_mime ?: 'application/octet-stream')
            ->header('Content-Disposition', 'inline; filename="'.addcslashes($name, '"\\').'"')
            ->header('Cache-Control', 'no-store, private')
            ->header('X-Content-Type-Options', 'nosniff')
            ->header('Content-Security-Policy', "default-src 'none'; img-src 'self' data:; media-src 'self'; style-src 'unsafe-inline'; sandbox");
    }

    public function cannedReplies(): JsonResponse
    {
        return response()->json(['data' => CannedReply::query()->orderBy('sort_order')->orderBy('id')->get(['id', 'title', 'body', 'sort_order'])]);
    }

    public function storeCannedReply(Request $request): JsonResponse
    {
        $reply = CannedReply::query()->create($this->cannedData($request) + ['sort_order' => (int) CannedReply::query()->max('sort_order') + 1]);

        return response()->json(['data' => $reply->only(['id', 'title', 'body', 'sort_order'])], Response::HTTP_CREATED);
    }

    public function updateCannedReply(Request $request, int $id): JsonResponse
    {
        $reply = CannedReply::query()->findOrFail($id);
        $reply->update($this->cannedData($request));

        return response()->json(['data' => $reply->only(['id', 'title', 'body', 'sort_order'])]);
    }

    public function destroyCannedReply(int $id): JsonResponse
    {
        CannedReply::query()->findOrFail($id)->delete();

        return response()->json(['data' => null]);
    }

    /** @return array{title: string, body: string} */
    private function cannedData(Request $request): array
    {
        return $request->validate(['title' => ['required', 'string', 'max:80'], 'body' => ['required', 'string', 'max:2000']]);
    }

    /** @return array<string, mixed> */
    public static function row(Conversation $conversation): array
    {
        return [
            'id' => $conversation->id,
            'channel' => $conversation->channel,
            'name' => $conversation->name ?? $conversation->customer?->name,
            'phone' => $conversation->phone,
            'status' => $conversation->status,
            'unread_count' => $conversation->unread_count,
            'last_message_at' => $conversation->last_message_at?->toIso8601String(),
            'last_message_preview' => $conversation->last_message_preview,
            'last_message_direction' => $conversation->last_message_direction,
            'assignee' => $conversation->assignee ? ['id' => $conversation->assignee->id, 'name' => $conversation->assignee->name] : null,
            'customer_id' => $conversation->customer_id,
        ];
    }

    /** @return array<string, mixed> */
    public static function message(ConversationMessage $message): array
    {
        return [
            'id' => $message->id,
            'direction' => $message->direction,
            'origin' => $message->origin,
            'staff' => $message->staff ? ['id' => $message->staff->id, 'name' => $message->staff->name] : null,
            'body' => $message->body,
            'attachment' => $message->attachment_kind === null ? null : [
                'kind' => $message->attachment_kind,
                'name' => $message->attachment_name,
                'mime' => $message->attachment_mime,
                'bytes' => $message->attachment_bytes,
                // Still being fetched from WhatsApp / Messenger, or couldn't be.
                'ready' => $message->attachment_path !== null,
                'unavailable' => $message->attachment_path === null && $message->error === 'media_unavailable',
            ],
            'status' => $message->status,
            'error' => $message->status === 'failed' ? $message->error : null,
            'sent_at' => $message->sent_at->toIso8601String(),
        ];
    }

    /** @return list<array{id: int, name: string}> active staff who can read the inbox */
    private static function assignees(): array
    {
        return Staff::query()->where('status', StaffStatus::Active)->orderBy('name')->get()
            ->filter(fn (Staff $member) => $member->can('inbox.view'))
            ->map(fn (Staff $member) => ['id' => $member->id, 'name' => $member->name])->values()->all();
    }

    /** The linked customer as far as this staff member may see them, with their latest bookings. */
    private function customer(Conversation $conversation, Staff $staff): ?array
    {
        if ($conversation->customer_id === null) {
            return null;
        }
        $customer = $staff->can('customers.view') ? Customer::query()->visibleTo($staff)->find($conversation->customer_id) : null;
        if (! $customer) {
            return ['id' => $conversation->customer_id, 'visible' => false];
        }
        $bookings = Booking::query()->visibleTo($staff)->where('customer_id', $customer->id)->latest('id')->limit(5)->get();

        return [
            'id' => $customer->id,
            'visible' => true,
            'name' => $customer->name,
            'phone' => $customer->phone,
            'email' => $customer->email,
            'stage' => $customer->stage,
            'bookings' => $bookings->map(fn (Booking $booking) => [
                'id' => $booking->id,
                'reference' => $booking->reference,
                'package_title' => $booking->package_title_en ?? $booking->package_title_bn,
                'travel_start' => $booking->travel_start?->toDateString(),
                'status' => $booking->status->value,
                'total' => Money::toNumber($booking->total_amount),
            ])->all(),
        ];
    }
}
