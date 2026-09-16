<?php

namespace App\Services\Notifications;

use App\Enums\InquiryType;
use App\Enums\NotificationChannel;
use App\Enums\NotificationEvent;
use App\Enums\NotificationStatus;
use App\Enums\StaffRole;
use App\Jobs\DeliverNotification;
use App\Models\AttendanceDevice;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Inquiry;
use App\Models\Invoice;
use App\Models\NotificationMessage;
use App\Models\NotificationTemplate;
use App\Models\NpsResponse;
use App\Models\PackageDeparture;
use App\Models\Quotation;
use App\Models\Staff;
use App\Models\StaffDocument;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Models\Transaction;
use App\Services\Booking\DepartureSeats;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Decides who gets which message, on which channel, and when (docs/phase-4-whatsapp.md §2–§3). Each message is one
 * `notifications` row with a unique dedupe key, so a repeated event or a scheduler re-run can never send twice.
 * Messages due now are handed to the delivery job after the database commit; later ones wait for
 * `notifications:dispatch`. Every money message also goes by email, the secondary channel that keeps working if
 * the WhatsApp number is banned or disconnected.
 */
final class NotificationPlanner
{
    public const LOW_SEATS = 3;

    public function __construct(
        private readonly MessageRenderer $renderer,
        private readonly NotificationVariables $variables,
    ) {}

    /** A website booking was saved. $privateLink is only known now: the booking's access token is shown once. */
    public function bookingCreated(Booking $booking, ?string $privateLink = null): void
    {
        $this->toCustomer(NotificationEvent::BookingCreated, $booking, extra: ['link' => $privateLink ?? '']);
        $this->toStaff(NotificationEvent::NewBookingAlert, $booking, $booking->assignedStaff);
        $this->checkSeats($booking);
    }

    /** Confirmation with the invoice attached, and the trip messages scheduled. */
    public function bookingConfirmed(Booking $booking): void
    {
        $invoice = Invoice::query()->where('booking_id', $booking->id)->where('status', Invoice::ISSUED)->latest('id')->first();
        $this->toCustomer(NotificationEvent::BookingConfirmed, $booking, attachment: $invoice ? "invoice:{$invoice->id}" : null);
        $this->scheduleTrip($booking);
        $this->checkSeats($booking);
    }

    /** A payment that didn't itself confirm the booking (the confirmation message covers that one). */
    public function paymentReceived(Booking $booking, Transaction $payment): void
    {
        $this->toCustomer(NotificationEvent::PaymentReceived, $booking,
            extra: ['amount' => $payment->amount], dedupeSuffix: "payment:{$payment->id}");
    }

    /** A quotation was sent: WhatsApp with the PDF and email with it attached, to the customer's own number and address. */
    public function quotationSent(Quotation $quotation): void
    {
        $this->toQuotationCustomer(NotificationEvent::QuoteSent, $quotation, "quotation:{$quotation->id}");
    }

    /** 24 hours before a sent quotation stops being honoured: WhatsApp and email, no attachment (docs/phase-6 §3.2). */
    public function quotationExpiring(Quotation $quotation): void
    {
        $this->toQuotationCustomer(NotificationEvent::QuoteExpiring, $quotation, null);
    }

    /** The customer accepted a quotation in the portal: its owner, and whoever receives the alert, converts it. */
    public function quotationAccepted(Quotation $quotation): void
    {
        $this->toStaff(NotificationEvent::QuoteAcceptedAlert, $quotation, $quotation->assignedStaff);
    }

    /** A new portal ticket: the trip's owner (or the customer's) and whoever receives the alert. */
    public function supportTicketOpened(SupportTicket $ticket, SupportMessage $message): void
    {
        $ticket->loadMissing(['booking.assignedStaff', 'customer.assignedStaff']);
        $this->toStaff(NotificationEvent::SupportTicketAlert, $ticket, $ticket->booking?->assignedStaff ?? $ticket->customer->assignedStaff,
            ['message' => $message->body]);
    }

    /** A staff reply, by WhatsApp and email to the customer's own number and address. */
    public function supportReplied(SupportTicket $ticket, SupportMessage $reply): void
    {
        $ticket->loadMissing('customer');
        $event = NotificationEvent::SupportReply;
        $base = "{$event->value}:support_message:{$reply->id}";
        $addresses = [
            NotificationChannel::WhatsApp->value => $ticket->customer->phone,
            NotificationChannel::Email->value => $ticket->customer->email,
        ];

        foreach ($event->channels() as $channel) {
            $this->plan($event, $channel, $ticket, $ticket->customer, $addresses[$channel->value] ?? null, $ticket->customer->locale ?? 'bn',
                ['reply' => $reply->body], null, "{$base}:{$channel->value}", null, $base);
        }
    }

    /** An NPS answer of 0–6: the trip's owner (or the customer's) and whoever receives the alert follow up. */
    public function npsFollowUp(NpsResponse $response): void
    {
        $response->loadMissing(['booking.assignedStaff', 'customer.assignedStaff']);
        $this->toStaff(NotificationEvent::NpsFollowUpAlert, $response->booking, $response->booking->assignedStaff ?? $response->customer->assignedStaff,
            ['score' => (string) $response->score, 'comment' => $response->comment ?: '—']);
    }

    private function toQuotationCustomer(NotificationEvent $event, Quotation $quotation, ?string $attachment): void
    {
        $quotation->loadMissing('customer');
        $base = "{$event->value}:quotation:{$quotation->id}";
        $addresses = [
            NotificationChannel::WhatsApp->value => $quotation->customer->phone,
            NotificationChannel::Email->value => $quotation->customer->email,
        ];

        foreach ($event->channels() as $channel) {
            $this->plan($event, $channel, $quotation, $quotation->customer, $addresses[$channel->value] ?? null, $quotation->locale,
                [], $attachment, "{$base}:{$channel->value}", null, $base);
        }
    }

    public function leadReceived(Inquiry $inquiry): void
    {
        if ($inquiry->type === InquiryType::HotelQuote) {
            // Its own alert list; with nobody on it, the super admins and admins, so a request is never announced to no one.
            $this->toStaff(NotificationEvent::HotelQuoteAlert, $inquiry,
                fallback: fn () => Staff::query()->where('status', 'active')
                    ->whereHas('roles', fn ($roles) => $roles->whereIn('name', [StaffRole::SuperAdmin->value, StaffRole::Admin->value]))->get());

            return;
        }
        $this->toStaff(NotificationEvent::NewLeadAlert, $inquiry);
    }

    /**
     * A staff reply to an air-ticket or hotel quotation request, by WhatsApp and email to the number and address the
     * customer gave on the form.
     *
     * @return list<NotificationMessage>
     */
    public function inquiryReplied(Inquiry $inquiry, string $reply, Staff $staff): array
    {
        $inquiry->loadMissing('customer');
        $event = NotificationEvent::InquiryReply;
        $base = "{$event->value}:inquiry:{$inquiry->id}:".now()->format('YmdHisv').':'.bin2hex(random_bytes(3));
        $addresses = [
            NotificationChannel::WhatsApp->value => $inquiry->phone,
            NotificationChannel::Email->value => $inquiry->email ?? $inquiry->customer?->email,
        ];
        $recipient = $inquiry->customer ?? throw new LogicException('A quotation request always has a customer record.');

        $rows = [];
        foreach ($event->channels() as $channel) {
            $row = $this->plan($event, $channel, $inquiry, $recipient, $addresses[$channel->value] ?? null, $inquiry->locale ?? 'bn',
                ['reply' => $reply], null, "{$base}:{$channel->value}", null, $base);
            $row->forceFill(['triggered_by_staff_id' => $staff->id])->save();
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * A staff document, once when it comes within 30 days of expiry (`soon`) and once on the day it expires, or the first
     * run after (`due`). It goes to the alert's list or, with nobody on it, to everyone active who manages staff
     * documents: this alert has no record owner to fall back on.
     */
    public function staffDocumentExpiring(StaffDocument $document, string $occasion): void
    {
        $this->toStaff(NotificationEvent::StaffDocumentExpiringAlert, $document, occasion: ":{$occasion}",
            fallback: fn () => Staff::permission('staff_documents.manage')->where('status', 'active')->get());
    }

    /**
     * The attendance device has been silent for an hour of duty time: once per outage (the outage is named by the last good
     * pull), to the alert's list or, with nobody on it, everyone active who manages attendance.
     *
     * @param  string  $reason  pc_silent · device_unreachable
     */
    public function attendanceDeviceOffline(AttendanceDevice $device, string $reason): void
    {
        $this->toStaff(NotificationEvent::AttendanceDeviceOfflineAlert, $device, extra: ['reason' => $reason],
            occasion: ':'.($device->last_pull_ok_at?->timestamp ?? 'never'),
            fallback: fn () => Staff::permission('attendance.manage')->where('status', 'active')->get());
    }

    /** Trip messages still waiting are cancelled with the booking. */
    public function bookingCancelled(Booking $booking): void
    {
        NotificationMessage::query()
            ->where('related_type', $booking->getMorphClass())->where('related_id', $booking->id)
            ->where('status', NotificationStatus::Pending)
            ->whereIn('event', array_map(fn (NotificationEvent $e) => $e->value, array_filter(NotificationEvent::cases(), fn (NotificationEvent $e) => $e->needsConfirmedBooking())))
            ->update(['status' => NotificationStatus::Cancelled, 'skipped_reason' => 'booking_cancelled', 'updated_at' => now()]);
    }

    /** Sales alert once a departure is down to three free seats (once per departure). */
    public function checkSeats(Booking $booking): void
    {
        $departure = $booking->departure_id ? PackageDeparture::query()->find($booking->departure_id) : null;
        if ($departure && $departure->seats_total !== null && DepartureSeats::available($departure) <= self::LOW_SEATS) {
            $this->toStaff(NotificationEvent::LowSeatAlert, $departure);
        }
    }

    /** A message a staff member writes from a booking or customer record. The number comes from the record only. */
    public function staffMessage(Staff $staff, Booking|Customer $subject, string $text, ?Invoice $invoice = null): NotificationMessage
    {
        [$customer, $phone, $locale] = $subject instanceof Booking
            ? [$subject->customer, $this->bookingPhone($subject), $subject->locale]
            : [$subject, $subject->phone, $subject->locale ?? 'bn'];

        return $this->create([
            'event' => NotificationEvent::StaffMessage, 'channel' => NotificationChannel::WhatsApp, 'to_address' => (string) $phone,
            'recipient_type' => $customer->getMorphClass(), 'recipient_id' => $customer->id,
            'related_type' => $subject->getMorphClass(), 'related_id' => $subject->id, 'locale' => $locale,
            'body' => $this->renderer->whatsApp($text), 'attachment_path' => $invoice ? "invoice:{$invoice->id}" : null,
            'triggered_by_staff_id' => $staff->id,
            'dedupe_key' => 'staff_message:'.$staff->id.':'.now()->format('YmdHisv').':'.bin2hex(random_bytes(4)),
        ]);
    }

    /**
     * What is still owed on an invoice, in the staff member's own words (docs/phase-9-accounts.md §5). One row per
     * channel asked for, so the Notifications log shows what went out, to where, and whether it arrived.
     *
     * @param  list<NotificationChannel>  $channels
     * @return list<NotificationMessage>
     */
    public function paymentReminder(Invoice $invoice, string $text, ?string $subject, Staff $staff, array $channels, ?string $email = null): array
    {
        $invoice->loadMissing('customer');
        $customer = $invoice->customer ?? throw new LogicException('An invoice reminder goes to the customer it is billed to.');
        $locale = $customer->locale ?? 'bn';
        $base = 'payment_reminder:'.$invoice->id.':'.now()->format('YmdHisv').':'.bin2hex(random_bytes(3));

        $rows = [];
        foreach ($channels as $channel) {
            $to = $channel === NotificationChannel::Email ? ($email ?: $invoice->billed_email ?? $customer->email) : ($invoice->billed_phone ?? $customer->phone);
            if (! $to) {
                continue;
            }
            $rows[] = $this->create([
                'event' => NotificationEvent::PaymentReminder, 'channel' => $channel, 'to_address' => (string) $to,
                'recipient_type' => $customer->getMorphClass(), 'recipient_id' => $customer->id,
                'related_type' => $invoice->getMorphClass(), 'related_id' => $invoice->id, 'locale' => $locale,
                'title' => $channel === NotificationChannel::Email ? $subject : null,
                'body' => $text, 'triggered_by_staff_id' => $staff->id,
                'dedupe_key' => "{$base}:{$channel->value}", 'group_key' => $base,
            ]);
        }

        return $rows;
    }

    public function verificationCode(Staff $staff, string $number, string $code): NotificationMessage
    {
        $locale = $staff->locale ?? 'bn';
        $text = $locale === 'en'
            ? "Your Bhabaghure admin verification code is {$code}. It expires in 10 minutes."
            : "ভবঘুরে অ্যাডমিন যাচাই কোড: {$code}। কোডটি ১০ মিনিটের মধ্যে ব্যবহার করুন।";

        return $this->create([
            'event' => NotificationEvent::WhatsAppVerification, 'channel' => NotificationChannel::WhatsApp, 'to_address' => $number,
            'recipient_type' => $staff->getMorphClass(), 'recipient_id' => $staff->id, 'locale' => $locale,
            'body' => $this->renderer->whatsApp($text), 'triggered_by_staff_id' => $staff->id,
            'dedupe_key' => 'whatsapp_verification:'.$staff->id.':'.now()->format('YmdHisv'),
        ]);
    }

    public function optOutConfirmation(Customer $customer, string $number, bool $optedOut): void
    {
        $locale = $customer->locale ?? 'bn';
        $text = $optedOut
            ? ($locale === 'en' ? 'You will no longer receive automated WhatsApp messages from us. Booking emails continue. Reply START to turn them back on.' : 'আমাদের স্বয়ংক্রিয় WhatsApp বার্তা আর পাঠানো হবে না। বুকিংয়ের ইমেইল চালু থাকবে। আবার চালু করতে START লিখে পাঠান।')
            : ($locale === 'en' ? 'Automated WhatsApp messages are on again.' : 'স্বয়ংক্রিয় WhatsApp বার্তা আবার চালু হয়েছে।');

        $this->create([
            'event' => NotificationEvent::OptOutConfirmation, 'channel' => NotificationChannel::WhatsApp, 'to_address' => $number,
            'recipient_type' => $customer->getMorphClass(), 'recipient_id' => $customer->id, 'locale' => $locale,
            'body' => $this->renderer->whatsApp($text),
            'dedupe_key' => 'opt_out_confirmation:'.$customer->id.':'.now()->format('YmdHisv'),
        ]);
    }

    /**
     * Pre-trip messages for a confirmed booking, at Dhaka times (config bhabaghure.notifications.schedule). A reminder
     * already overdue when the booking is confirmed goes out now if the trip hasn't started; otherwise it's not sent.
     */
    private function scheduleTrip(Booking $booking): void
    {
        if (! $booking->travel_start) {
            return;
        }
        $schedule = config('bhabaghure.notifications.schedule');
        $start = $booking->travel_start->toDateString();
        $end = ($booking->travel_end ?? $booking->travel_start)->toDateString();
        $at = fn (string $date, string $time) => Carbon::parse("{$date} {$time}", 'Asia/Dhaka')->utc();
        $tripStarts = $at($start, $schedule['departure_today']['at']);

        $plan = [
            [NotificationEvent::DocumentsPending, $at($start, $schedule['documents_pending']['at'])->subDays($schedule['documents_pending']['days_before']), true],
            [NotificationEvent::PreTripReminder, $at($start, $schedule['pre_trip_reminder']['departure_at'])->subHours($schedule['pre_trip_reminder']['hours_before']), true],
            [NotificationEvent::DepartureToday, $tripStarts, false],
            [NotificationEvent::TripCompleted, $at($end, $schedule['trip_completed']['at'])->addDays($schedule['trip_completed']['days_after']), false],
        ];

        foreach ($plan as [$event, $when, $sendIfOverdue]) {
            if ($when->isPast()) {
                if (! $sendIfOverdue || now()->gte($tripStarts)) {
                    continue;
                }
                $when = now();
            }
            if ($event === NotificationEvent::DocumentsPending && ! $booking->travellers()->whereNull('passport_number')->exists()) {
                continue;
            }
            $this->toCustomer($event, $booking, scheduledFor: $when);
        }
    }

    /**
     * A short SMS standing in for a money-critical WhatsApp message that couldn't reach the customer: it failed, the
     * number has no WhatsApp, or WhatsApp is unavailable (docs/phase-4-whatsapp.md §10). At most one per message — the
     * dedupe key is the WhatsApp message's plus ":sms" — and it joins that message's group, so the booking page shows
     * WhatsApp, email and SMS side by side.
     */
    public function smsFallback(NotificationMessage $whatsApp): ?NotificationMessage
    {
        $booking = $whatsApp->loadMissing('related')->related;
        if ($whatsApp->channel !== NotificationChannel::WhatsApp || ! $whatsApp->event->fallsBackToSms()
            || $whatsApp->recipient_type !== (new Customer)->getMorphClass() || ! $booking instanceof Booking || blank($whatsApp->to_address)) {
            return null;
        }

        // The one value only the event knew: which payment this was.
        $extra = [];
        if (preg_match('/:payment:(\d+)$/', $whatsApp->dedupe_key, $m) === 1 && ($payment = Transaction::query()->find((int) $m[1]))) {
            $extra['amount'] = $payment->amount;
        }

        return $this->plan($whatsApp->event, NotificationChannel::Sms, $booking, $booking->customer, $whatsApp->to_address, $whatsApp->locale,
            $extra, null, "{$whatsApp->dedupe_key}:sms", null, $whatsApp->group_key, $whatsApp->id);
    }

    /**
     * @param  array<string, string|int|float>  $extra
     */
    private function toCustomer(NotificationEvent $event, Booking $booking, array $extra = [], ?string $attachment = null, ?string $dedupeSuffix = null, ?Carbon $scheduledFor = null): void
    {
        $booking->loadMissing(['customer', 'travellers']);
        $lead = $booking->travellers->firstWhere('is_lead', true);
        $addresses = [
            NotificationChannel::WhatsApp->value => $this->bookingPhone($booking),
            NotificationChannel::Email->value => $lead?->email ?: $booking->customer->email,
            NotificationChannel::Sms->value => $this->bookingPhone($booking),
        ];
        $group = "{$event->value}:booking:{$booking->id}".($dedupeSuffix ? ":{$dedupeSuffix}" : '');

        foreach ($event->channels() as $channel) {
            $this->plan($event, $channel, $booking, $booking->customer, $addresses[$channel->value] ?? null, $booking->locale ?? 'bn',
                $extra, $channel === NotificationChannel::Sms ? null : $attachment,
                "{$event->value}:booking:{$booking->id}:{$channel->value}".($dedupeSuffix ? ":{$dedupeSuffix}" : ''), $scheduledFor, $group);
        }
    }

    /**
     * @param  string  $occasion  appended to the dedupe key when one record raises the same alert more than once
     * @param  (Closure(): Collection<int, Staff>)|null  $fallback  recipients when nobody is on the alert's list
     */
    private function toStaff(NotificationEvent $event, Model $related, ?Staff $alsoAssigned = null, array $extra = [], string $occasion = '', ?Closure $fallback = null): void
    {
        $staff = NotificationSettings::recipientsFor($event);
        if ($staff->isEmpty() && $fallback !== null) {
            $staff = $fallback();
        }
        if ($alsoAssigned && $alsoAssigned->canSignIn() && ! $staff->contains('id', $alsoAssigned->id)) {
            $staff->push($alsoAssigned);
        }

        foreach ($staff as $member) {
            // Sales alerts never go by SMS.
            $base = "{$event->value}:{$related->getMorphClass()}:{$related->getKey()}{$occasion}";
            if ($member->verifiedWhatsAppNumber()) {
                $this->plan($event, NotificationChannel::WhatsApp, $related, $member, $member->verifiedWhatsAppNumber(), $member->locale ?? 'bn',
                    $extra, null, "{$base}:whatsapp:staff:{$member->id}", null, "{$base}:staff:{$member->id}");
            }
            $this->plan($event, NotificationChannel::Email, $related, $member, $member->email, $member->locale ?? 'bn',
                $extra, null, "{$base}:email:staff:{$member->id}", null, "{$base}:staff:{$member->id}");
        }
    }

    /** @param array<string, string|int|float> $extra */
    private function plan(NotificationEvent $event, NotificationChannel $channel, Model $related, Model $recipient, ?string $address, string $locale, array $extra, ?string $attachment, string $dedupeKey, ?Carbon $scheduledFor, ?string $groupKey = null, ?int $fallbackOf = null): NotificationMessage
    {
        $due = $scheduledFor === null || ! $scheduledFor->isFuture();
        if ($channel === NotificationChannel::Sms && in_array(config('bhabaghure.notifications.sms.locale'), ['bn', 'en'], true)) {
            $locale = (string) config('bhabaghure.notifications.sms.locale');
        }
        $row = [
            'event' => $event, 'channel' => $channel, 'to_address' => (string) $address,
            'recipient_type' => $recipient->getMorphClass(), 'recipient_id' => $recipient->getKey(),
            'related_type' => $related->getMorphClass(), 'related_id' => $related->getKey(), 'locale' => $locale,
            'attachment_path' => $attachment, 'scheduled_for' => $scheduledFor ?? now(), 'dedupe_key' => $dedupeKey, 'body' => '',
            'group_key' => $groupKey ?? $dedupeKey, 'fallback_of_id' => $fallbackOf,
        ];

        if (blank($address)) {
            return $this->create($row + ['status' => NotificationStatus::Skipped, 'skipped_reason' => match ($channel) {
                NotificationChannel::Email => 'no_email',
                NotificationChannel::Sms => 'no_phone',
                NotificationChannel::WhatsApp => 'no_whatsapp_number',
            }]);
        }

        // Messages due now are written as sent — with values only known at this moment, such as the private
        // booking link. Scheduled ones are written when they go out, from the booking as it is then.
        if ($due) {
            $template = NotificationTemplate::query()->where('event', $event->value)->where('channel', $channel->value)->first();
            if ($template) {
                $body = $this->renderer->fill($template->body($locale), $this->variables->for($event, $related, $locale, $channel, $extra));
                $row['body'] = $channel === NotificationChannel::WhatsApp ? $this->renderer->whatsApp($body) : $body;
                $row['title'] = $channel === NotificationChannel::Email ? $this->renderer->fill((string) $template->subject($locale), $this->variables->for($event, $related, $locale, $channel, $extra)) : null;
            }
        }

        return $this->create($row);
    }

    /** @param array<string, mixed> $attributes */
    private function create(array $attributes): NotificationMessage
    {
        $attributes += [
            'status' => NotificationStatus::Pending, 'scheduled_for' => now(), 'group_key' => $attributes['dedupe_key'],
            'provider' => match ($attributes['channel']) {
                NotificationChannel::Email => 'mail',
                NotificationChannel::Sms => 'sms',
                NotificationChannel::WhatsApp => 'whatsapp',
            },
        ];

        try {
            $row = NotificationMessage::query()->create($attributes);
        } catch (UniqueConstraintViolationException) {
            // Already planned by an earlier run of the same event.
            return NotificationMessage::query()->where('dedupe_key', $attributes['dedupe_key'])->firstOrFail();
        }

        if ($row->status === NotificationStatus::Pending && ! $row->scheduled_for->isFuture()) {
            DB::afterCommit(fn () => DeliverNotification::dispatch($row->id));
        }

        return $row;
    }

    private function bookingPhone(Booking $booking): ?string
    {
        $booking->loadMissing(['customer', 'travellers']);

        return $booking->travellers->firstWhere('is_lead', true)?->phone ?: $booking->customer->phone;
    }
}
