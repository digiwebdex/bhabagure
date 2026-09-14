<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\NotificationChannel;
use App\Enums\NotificationEvent;
use App\Enums\NotificationStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\AdminNotification;
use App\Jobs\DeliverNotification;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Inquiry;
use App\Models\Invoice;
use App\Models\NotificationMessage;
use App\Models\NotificationTemplate;
use App\Models\PackageDeparture;
use App\Models\Staff;
use App\Services\AuditLogger;
use App\Services\Notifications\MessageRenderer;
use App\Services\Notifications\NotificationPlanner;
use App\Services\Notifications\NotificationSettings;
use App\Services\Notifications\NotificationVariables;
use App\Services\Notifications\Sms\DisabledSmsGateway;
use App\Services\Notifications\Sms\SmsGateway;
use App\Services\Notifications\WhatsApp\WhatsAppGateway;
use App\Support\Sms\SmsParts;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * WhatsApp and email notifications for staff (docs/phase-4-whatsapp.md §5): the connection overview, the message log,
 * templates, alert recipients, and sending a message from a booking or customer record. Numbers are always looked up
 * from the record — nothing here accepts a typed phone number, for any role.
 */
class NotificationController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function overview(Request $request, WhatsAppGateway $gateway, SmsGateway $smsGateway): JsonResponse
    {
        // Asked for, or never checked yet (webhooks keep it current after that).
        if ($request->boolean('refresh') || NotificationSettings::sessionStatus()['checkedAt'] === null) {
            NotificationSettings::recordSessionStatus($gateway->sessionStatus());
        }
        $key = (string) config('bhabaghure.notifications.whatsapp.api_key');
        $whatsapp = config('bhabaghure.notifications.whatsapp');
        $sms = config('bhabaghure.notifications.sms');
        $smsKey = (string) $sms['api_key'];

        return response()->json(['data' => [
            'sms' => [
                'mode' => $sms['mode'],
                'provider' => $smsGateway->name(),
                // Why SMS isn't sending, when it isn't: sms_off, sms_not_configured, sms_sender_id_missing, sms_insecure_url.
                'disabled_reason' => $smsGateway instanceof DisabledSmsGateway ? $smsGateway->reason() : null,
                'sender_id' => filled($sms['sender_id']) ? (string) $sms['sender_id'] : null,
                'key_hint' => $smsKey !== '' ? '…'.substr($smsKey, -4) : null,
                'https' => str_starts_with((string) $sms['url'], 'https://'),
                'cost_per_part' => (float) $sms['cost_per_part'],
                'warn_parts' => (int) $sms['warn_parts'],
                'max_parts' => (int) $sms['max_parts'],
            ],
            'costs' => [
                'this_month' => AdminNotification::monthlyCosts(now('Asia/Dhaka')),
                'last_month' => AdminNotification::monthlyCosts(now('Asia/Dhaka')->startOfMonth()->subMonth()),
            ],
            'whatsapp' => [
                'mode' => $whatsapp['mode'],
                'provider' => $gateway->name(),
                'session' => NotificationSettings::sessionStatus(),
                'key_hint' => $key !== '' ? '…'.substr($key, -4) : null,
                'webhook_configured' => (string) $whatsapp['webhook_secret'] !== '',
                'seconds_between_sends' => $whatsapp['seconds_between_sends'],
                'daily_cap' => $whatsapp['daily_cap'],
            ],
            'email' => ['enabled' => (bool) config('bhabaghure.notifications.email.enabled'), 'mailer' => config('mail.default'), 'from' => config('mail.from.address')],
            'numbers' => ['main' => NotificationSettings::mainNumber(), 'notifications' => NotificationSettings::notificationsNumber()],
            'counts' => [
                'pending' => NotificationMessage::query()->where('status', NotificationStatus::Pending)->count(),
                'sent_today' => NotificationMessage::query()->whereNotNull('sent_at')->where('sent_at', '>=', now('Asia/Dhaka')->startOfDay()->utc())->count(),
                'failed_24h' => NotificationMessage::query()->where('status', NotificationStatus::Failed)->where('updated_at', '>=', now()->subDay())->count(),
            ],
        ]]);
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(NotificationStatus::class)],
            'channel' => ['nullable', Rule::enum(NotificationChannel::class)],
            'event' => ['nullable', Rule::enum(NotificationEvent::class)],
        ]);
        $page = NotificationMessage::query()
            ->when($filters['status'] ?? null, fn (Builder $q, $v) => $q->where('status', $v))
            ->when($filters['channel'] ?? null, fn (Builder $q, $v) => $q->where('channel', $v))
            ->when($filters['event'] ?? null, fn (Builder $q, $v) => $q->where('event', $v))
            ->latest('id')->paginate(40);

        return response()->json([
            'data' => collect($page->items())->map(AdminNotification::message(...)),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    /** A message a staff member writes to the customer of a booking, or to a customer record they can see. */
    public function send(Request $request, NotificationPlanner $planner): JsonResponse
    {
        $staff = $request->user('staff');
        $data = $request->validate([
            'booking_id' => ['required_without:customer_id', 'prohibits:customer_id', 'integer'],
            'customer_id' => ['required_without:booking_id', 'integer'],
            'text' => ['required', 'string', 'min:2', 'max:1000'],
            'attach_invoice' => ['boolean'],
            // The number comes from the record. A typed number is refused outright, whoever sends it.
            'to' => ['prohibited'], 'phone' => ['prohibited'], 'number' => ['prohibited'],
        ]);

        $subject = isset($data['booking_id']) ? $this->visibleBooking($staff, (int) $data['booking_id']) : $this->visibleCustomer($staff, (int) $data['customer_id']);
        // The shared pool is visible to every sales agent, but only the owner messages the customer: claim first.
        $works = $subject instanceof Booking
            ? Booking::seesAll($staff) || $subject->assigned_staff_id === $staff->id
            : Customer::seesAll($staff) || $subject->assigned_staff_id === $staff->id || $subject->bookings()->where('assigned_staff_id', $staff->id)->exists();
        if (! $works) {
            return response()->json(['message' => __('ownership.claim_first'), 'code' => 'claim_first'], Response::HTTP_CONFLICT);
        }
        $customer = $subject instanceof Booking ? $subject->customer : $subject;
        $invoice = null;
        if (($data['attach_invoice'] ?? false) && $subject instanceof Booking) {
            $invoice = Invoice::query()->where('booking_id', $subject->id)->where('status', Invoice::ISSUED)->latest('id')->first()
                ?? throw ValidationException::withMessages(['attach_invoice' => [__('notifications.no_invoice')]]);
        }
        if ($customer->whatsapp_opted_out_at !== null) {
            return response()->json(['message' => __('notifications.opted_out'), 'code' => 'opted_out'], Response::HTTP_CONFLICT);
        }
        if (NotificationSettings::notificationsNumber() === null) {
            return response()->json(['message' => __('notifications.number_not_published'), 'code' => 'notifications_number_not_published'], Response::HTTP_CONFLICT);
        }

        foreach ([['staff-whatsapp-minute:'.$staff->id, 'per_minute', 60], ['staff-whatsapp-day:'.$staff->id, 'per_day', 86400]] as [$key, $limit, $decay]) {
            if (RateLimiter::tooManyAttempts($key, (int) config("bhabaghure.notifications.staff_send.{$limit}"))) {
                return response()->json(['message' => __('notifications.too_many', ['seconds' => RateLimiter::availableIn($key)]), 'code' => 'rate_limited'], Response::HTTP_TOO_MANY_REQUESTS);
            }
        }
        RateLimiter::hit('staff-whatsapp-minute:'.$staff->id, 60);
        RateLimiter::hit('staff-whatsapp-day:'.$staff->id, 86400);

        $row = $planner->staffMessage($staff, $subject, $data['text'], $invoice);
        $this->audit->record('notification.staff_whatsapp', $staff, $subject, ['notification' => $row->id, 'attach_invoice' => $invoice !== null]);

        return response()->json(['data' => AdminNotification::message($row->fresh())], Response::HTTP_CREATED);
    }

    public function templates(): JsonResponse
    {
        $order = [NotificationChannel::WhatsApp->value => 0, NotificationChannel::Email->value => 1, NotificationChannel::Sms->value => 2];
        $templates = NotificationTemplate::query()->get()
            ->sortBy(fn (NotificationTemplate $t) => array_search($t->event, NotificationEvent::templated(), true) * 10 + $order[$t->channel->value])
            ->values();

        return response()->json(['data' => $templates->map(AdminNotification::template(...))]);
    }

    public function updateTemplate(Request $request, int $id): JsonResponse
    {
        $template = NotificationTemplate::query()->findOrFail($id);
        $isEmail = $template->channel === NotificationChannel::Email;
        $data = $request->validate([
            'body_bn' => ['required', 'string', 'max:'.($isEmail ? 5000 : 1000)],
            'body_en' => ['required', 'string', 'max:'.($isEmail ? 5000 : 1000)],
            'subject_bn' => [$isEmail ? 'required' : 'prohibited', 'nullable', 'string', 'max:190'],
            'subject_en' => [$isEmail ? 'required' : 'prohibited', 'nullable', 'string', 'max:190'],
            'is_enabled' => ['required', 'boolean'],
        ]);

        $errors = [];
        foreach (['body_bn', 'body_en', 'subject_bn', 'subject_en'] as $field) {
            $unknown = MessageRenderer::unknownVariables($template->event, (string) ($data[$field] ?? ''));
            if ($unknown !== []) {
                $errors[$field] = [__('notifications.unknown_variables', ['variables' => implode(', ', array_map(fn ($v) => '{{'.$v.'}}', $unknown))])];
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $template->fill($data)->forceFill(['updated_by_staff_id' => $request->user('staff')->id])->save();
        $this->audit->record('notification.template_updated', $request->user('staff'), $template, ['event' => $template->event->value, 'channel' => $template->channel->value]);

        return response()->json(['data' => AdminNotification::template($template)]);
    }

    /** Sends the template, filled from the latest booking, to the signed-in staff member only. */
    public function testTemplate(Request $request, int $id, MessageRenderer $renderer, NotificationVariables $variables): JsonResponse
    {
        $staff = $request->user('staff');
        $template = NotificationTemplate::query()->findOrFail($id);
        $locale = $request->validate(['locale' => ['required', Rule::in(['bn', 'en'])]])['locale'];
        $key = 'notification-test:'.$staff->id;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return response()->json(['message' => __('notifications.too_many', ['seconds' => RateLimiter::availableIn($key)]), 'code' => 'rate_limited'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        // WhatsApp and SMS tests go to the staff member's own verified mobile, email tests to their email.
        $address = $template->channel === NotificationChannel::Email ? $staff->email : $staff->verifiedWhatsAppNumber();
        if ($address === null) {
            return response()->json(['message' => __('notifications.verify_your_number'), 'code' => 'no_verified_number'], Response::HTTP_CONFLICT);
        }
        [$sample, $extra] = $this->sample($template->event, $locale);
        if (! $sample) {
            return response()->json(['message' => __('notifications.no_sample'), 'code' => 'no_sample'], Response::HTTP_CONFLICT);
        }
        RateLimiter::hit($key, 60);

        $values = $variables->for($template->event, $sample, $locale, $template->channel, $extra);
        $body = $renderer->fill($template->body($locale), $values);
        $row = NotificationMessage::query()->create([
            'event' => $template->event, 'channel' => $template->channel, 'to_address' => $address,
            'recipient_type' => $staff->getMorphClass(), 'recipient_id' => $staff->id, 'locale' => $locale,
            'title' => $template->channel === NotificationChannel::Email ? '[TEST] '.$renderer->fill((string) $template->subject($locale), $values) : null,
            'body' => $template->channel === NotificationChannel::WhatsApp ? $renderer->whatsApp($body) : $body,
            'status' => NotificationStatus::Pending, 'provider' => match ($template->channel) {
                NotificationChannel::Email => 'mail',
                NotificationChannel::Sms => 'sms',
                NotificationChannel::WhatsApp => 'whatsapp',
            },
            'scheduled_for' => now(), 'triggered_by_staff_id' => $staff->id,
            'dedupe_key' => 'test:'.$template->id.':'.$staff->id.':'.now()->format('YmdHisv'),
        ]);
        DeliverNotification::dispatch($row->id);

        return response()->json(['data' => AdminNotification::message($row->fresh())], Response::HTTP_CREATED);
    }

    /** The text as it would go out — sender line included — filled from the latest record. Takes unsaved edits. */
    public function previewTemplate(Request $request, int $id, MessageRenderer $renderer, NotificationVariables $variables): JsonResponse
    {
        $template = NotificationTemplate::query()->findOrFail($id);
        $isEmail = $template->channel === NotificationChannel::Email;
        $data = $request->validate([
            'locale' => ['required', Rule::in(['bn', 'en'])],
            'body' => ['present', 'nullable', 'string', 'max:5000'],
            'subject' => ['nullable', 'string', 'max:190'],
        ]);
        $body = (string) ($data['body'] ?? '');
        $subject = $isEmail ? (string) ($data['subject'] ?? '') : '';

        [$sample, $extra] = $this->sample($template->event, $data['locale']);
        $values = $sample ? $variables->for($template->event, $sample, $data['locale'], $template->channel, $extra) : [];
        $filled = $renderer->fill($body, $values);
        $isWhatsApp = $template->channel === NotificationChannel::WhatsApp;

        return response()->json(['data' => [
            'body' => $sample ? AdminNotification::maskTokens($isWhatsApp ? $renderer->whatsApp($filled) : $filled) : null,
            // SMS: counted on the filled text, exactly as the delivery job counts and costs it.
            'sms' => $template->channel === NotificationChannel::Sms ? self::smsEstimate($sample ? $filled : $body) : null,
            'subject' => $sample && $isEmail ? $renderer->fill($subject, $values) : null,
            'unknown_variables' => MessageRenderer::unknownVariables($template->event, $body.' '.$subject),
            'sample' => match (true) {
                $sample instanceof Booking => $sample->reference,
                $sample instanceof Inquiry => $sample->name,
                $sample instanceof PackageDeparture => $sample->departs_on?->toDateString(),
                default => null,
            },
        ]]);
    }

    /** @return array{encoding: string, units: int, parts: int, per_part: int, cost: float, warn: bool, too_long: bool} */
    private static function smsEstimate(string $text): array
    {
        $config = config('bhabaghure.notifications.sms');
        $length = SmsParts::count($text);

        return $length + [
            'cost' => round($length['parts'] * (float) $config['cost_per_part'], 2),
            'warn' => $length['parts'] > (int) $config['warn_parts'],
            'too_long' => $length['parts'] > (int) $config['max_parts'],
        ];
    }

    public function settings(): JsonResponse
    {
        return response()->json(['data' => $this->settingsPayload()]);
    }

    /** Alert recipient lists: staff with a verified WhatsApp number only. The booking's assigned agent is always added. */
    public function updateSettings(Request $request): JsonResponse
    {
        $events = array_map(fn (NotificationEvent $event) => $event->value, NotificationEvent::staffAlerts());
        // One rule set per list: a wildcard `distinct` would compare ids across lists, and one person may be on several.
        $data = $request->validate(['alert_recipients' => ['required', 'array:'.implode(',', $events)]] + collect($events)->flatMap(fn (string $event) => [
            "alert_recipients.{$event}" => ['array'],
            "alert_recipients.{$event}.*" => ['integer', 'distinct'],
        ])->all());
        $verified = Staff::query()->where('status', 'active')->whereNotNull('whatsapp_verified_at')->pluck('id')->all();

        $recipients = [];
        foreach ($events as $event) {
            $ids = array_values(array_unique(array_map('intval', $data['alert_recipients'][$event] ?? [])));
            $unverified = array_diff($ids, $verified);
            if ($unverified !== []) {
                throw ValidationException::withMessages(["alert_recipients.{$event}" => [__('notifications.recipients_must_be_verified')]]);
            }
            $recipients[$event] = $ids;
        }

        NotificationSettings::saveAlertRecipients($recipients, $request->user('staff'));
        $this->audit->record('notification.alert_recipients_updated', $request->user('staff'), changes: $recipients);

        return response()->json(['data' => $this->settingsPayload()]);
    }

    public function setCustomerOptOut(Request $request, int $customerId): JsonResponse
    {
        $staff = $request->user('staff');
        abort_unless($staff->can('customers.manage'), 403, __('auth.forbidden'));
        $customer = $this->visibleCustomer($staff, $customerId);
        $optedOut = $request->validate(['opted_out' => ['required', 'boolean']])['opted_out'];

        $customer->forceFill(['whatsapp_opted_out_at' => $optedOut ? ($customer->whatsapp_opted_out_at ?? now()) : null])->save();
        $this->audit->record($optedOut ? 'customer.whatsapp_opted_out' : 'customer.whatsapp_opted_in', $staff, $customer, ['via' => 'staff']);

        return response()->json(['data' => ['customer_id' => $customer->id, 'whatsapp_opted_out' => $optedOut]]);
    }

    /** @return array<string, mixed> */
    private function settingsPayload(): array
    {
        return [
            'alert_recipients' => NotificationSettings::alertRecipients(),
            'eligible_staff' => Staff::query()->where('status', 'active')->whereNotNull('whatsapp_verified_at')->with('roles')->orderBy('name')->get()
                ->map(fn (Staff $s) => [
                    'id' => $s->id, 'name' => $s->name, 'role' => $s->roles->first()?->name,
                    // Custom roles have no fixed label in the admin; their names come with them.
                    'role_name_en' => $s->roles->first()?->name_en, 'role_name_bn' => $s->roles->first()?->name_bn,
                    'whatsapp' => $s->whatsapp_number,
                ])->values(),
        ];
    }

    /**
     * The newest record a template can be filled from, and the values only a live event carries (a payment's amount,
     * the private booking link — shown with its token masked).
     *
     * @return array{0: Booking|Inquiry|PackageDeparture|null, 1: array<string, string|int|float>}
     */
    private function sample(NotificationEvent $event, string $locale): array
    {
        $sample = match ($event) {
            NotificationEvent::NewLeadAlert => Inquiry::query()->latest('id')->first(),
            NotificationEvent::LowSeatAlert => PackageDeparture::query()->latest('id')->first(),
            default => Booking::query()->latest('id')->first(),
        };
        $extra = $sample instanceof Booking ? [
            'amount' => $sample->paid_amount,
            'link' => rtrim((string) config('bhabaghure.web_url'), '/')."/booking/{$sample->reference}#t=sample",
        ] : [];

        return [$sample, $extra];
    }

    private function visibleBooking(Staff $staff, int $id): Booking
    {
        return Booking::query()->visibleTo($staff)->with('customer')->findOrFail($id);
    }

    private function visibleCustomer(Staff $staff, int $id): Customer
    {
        abort_unless($staff->can('customers.view'), 403, __('auth.forbidden'));

        return Customer::query()->visibleTo($staff)->findOrFail($id);
    }
}
