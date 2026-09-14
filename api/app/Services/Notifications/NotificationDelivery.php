<?php

namespace App\Services\Notifications;

use App\Enums\BookingStatus;
use App\Enums\NotificationChannel;
use App\Enums\NotificationEvent;
use App\Enums\NotificationStatus;
use App\Jobs\DeliverNotification;
use App\Jobs\LinkWhatsAppMessageId;
use App\Mail\NotificationMail;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\NotificationMessage;
use App\Models\NotificationTemplate;
use App\Models\Staff;
use App\Services\Invoices\InvoicePdf;
use App\Services\Notifications\Sms\SmsGateway;
use App\Services\Notifications\WhatsApp\WhatsAppGateway;
use App\Support\Sms\SmsParts;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sends a planned message (docs/phase-4-whatsapp.md §3). Checks again that it is still wanted — the booking may have
 * been cancelled, the passport added, the customer opted out — then paces WhatsApp sends for account safety and
 * reschedules on rate limits or a disconnected session. Failures are recorded on the row; nothing is silently lost.
 */
final class NotificationDelivery
{
    private const LAST_SEND_KEY = 'bhabaghure:whatsapp:next-send-at';

    private const LOCK_KEY = 'bhabaghure:whatsapp:send';

    /** WhatsApp can't reach anyone right now: a money-critical message goes by SMS instead of waiting (§10). */
    private const WHATSAPP_UNAVAILABLE = ['whatsapp_off', 'whatsapp_not_configured', 'notifications_number_not_published', 'session_disconnected', 'invalid_api_key', 'subscription_required'];

    public function __construct(
        private readonly WhatsAppGateway $whatsApp,
        private readonly SmsGateway $sms,
        private readonly NotificationPlanner $planner,
        private readonly MessageRenderer $renderer,
        private readonly NotificationVariables $variables,
        private readonly InvoicePdf $invoicePdf,
    ) {}

    public function deliver(int $id): ?NotificationMessage
    {
        // Claim the row: two workers (or the dispatcher and an immediate job) can't both send it.
        $claimed = NotificationMessage::query()->whereKey($id)->where('status', NotificationStatus::Pending)->where('scheduled_for', '<=', now())
            ->update(['status' => NotificationStatus::Sending, 'updated_at' => now()]);
        if ($claimed === 0) {
            return null;
        }
        $row = NotificationMessage::query()->with('related')->findOrFail($id);

        if ($reason = $this->noLongerWanted($row)) {
            $finished = $this->finish($row, $reason === 'booking_cancelled' ? NotificationStatus::Cancelled : NotificationStatus::Skipped, ['skipped_reason' => $reason]);
            if (in_array($reason, self::WHATSAPP_UNAVAILABLE, true)) {
                $this->fallBackToSms($finished);
            }

            return $finished;
        }
        if ($row->body === '' && ! $this->render($row)) {
            return $this->finish($row, NotificationStatus::Skipped, ['skipped_reason' => 'template_missing']);
        }

        return match ($row->channel) {
            NotificationChannel::WhatsApp => $this->sendWhatsApp($row),
            NotificationChannel::Email => $this->sendEmail($row),
            NotificationChannel::Sms => $this->sendSms($row),
        };
    }

    private function sendWhatsApp(NotificationMessage $row): NotificationMessage
    {
        $config = config('bhabaghure.notifications.whatsapp');
        $lock = Cache::lock(self::LOCK_KEY, 30);
        if (! $lock->get()) {
            return $this->reschedule($row, 5, 'waiting_for_previous_send');
        }

        try {
            $wait = (int) Cache::get(self::LAST_SEND_KEY, 0) - now()->getTimestamp();
            if ($wait > 0) {
                return $this->reschedule($row, $wait, 'paced');
            }
            $sentToday = NotificationMessage::query()->where('channel', NotificationChannel::WhatsApp)->whereNotNull('sent_at')
                ->where('sent_at', '>=', now('Asia/Dhaka')->startOfDay()->utc())->count();
            if ($sentToday >= (int) $config['daily_cap']) {
                return $this->reschedule($row, (int) now('Asia/Dhaka')->addDay()->setTime(9, 0)->diffInSeconds(now(), true), 'daily_cap_reached');
            }

            $row->increment('attempts');
            $invoice = $row->attachedInvoiceId() ? Invoice::query()->find($row->attachedInvoiceId()) : null;
            $result = $invoice
                ? $this->whatsApp->sendDocument($row->to_address, url("/api/v1/public/invoices/{$invoice->share_token}/pdf"), "{$invoice->invoice_number}.pdf", $row->body)
                : $this->whatsApp->sendText($row->to_address, $row->body);

            if ($result->isSent()) {
                // Pace the next send: the configured gap plus a random pause, as WaSender's anti-ban guidance advises.
                Cache::put(self::LAST_SEND_KEY, now()->getTimestamp() + (int) $config['seconds_between_sends'] + random_int(0, max(0, (int) $config['jitter_seconds'])), 3600);
            }
        } finally {
            $lock->release();
        }

        return $this->afterWhatsApp($row, $result);
    }

    private function afterWhatsApp(NotificationMessage $row, SendResult $result): NotificationMessage
    {
        return match ($result->outcome) {
            'sent' => tap($this->finish($row, NotificationStatus::Sent, [
                'sent_at' => now(), 'provider' => $this->whatsApp->name(), 'provider_message_id' => $result->messageId, 'last_error' => null, 'cost' => 0,
            ]), function (NotificationMessage $sent) {
                if ($sent->provider_message_id === null) {
                    return;
                }
                $this->whatsApp->name() === 'wasender'
                    ? LinkWhatsAppMessageId::dispatch($sent->id)->delay(now()->addSeconds(20))
                    : $sent->forceFill(['provider_whatsapp_id' => $this->whatsApp->messageInfo($sent->provider_message_id)['whatsapp_id'] ?? null])->save();
            }),
            'skipped' => tap($this->finish($row, NotificationStatus::Skipped, ['skipped_reason' => $result->error]), function (NotificationMessage $skipped) {
                if (in_array($skipped->skipped_reason, self::WHATSAPP_UNAVAILABLE, true)) {
                    $this->fallBackToSms($skipped);
                }
            }),
            'retry' => $this->retry($row, (int) $result->retryAfterSeconds, (string) $result->error),
            // Won't work however often it's tried — not on WhatsApp, invalid number: SMS now.
            default => tap($this->finish($row, NotificationStatus::Failed, ['failed_at' => now(), 'last_error' => $result->error]), $this->fallBackToSms(...)),
        };
    }

    /**
     * SMS: counted before sending (GSM-7 160/153, UCS-2 70/67 per part); a message longer than the configured maximum
     * isn't sent. The part count and estimated cost are stored, so the message log shows what SMS costs each month.
     */
    private function sendSms(NotificationMessage $row): NotificationMessage
    {
        $config = config('bhabaghure.notifications.sms');
        $length = SmsParts::count($row->body);
        $measured = ['sms_parts' => $length['parts'], 'sms_encoding' => $length['encoding']];
        if ($length['parts'] > (int) $config['max_parts']) {
            return $this->finish($row, NotificationStatus::Failed, $measured + ['failed_at' => now(), 'last_error' => 'sms_too_long']);
        }

        $row->increment('attempts');
        $result = $this->sms->send($row->to_address, $row->body);

        return match ($result->outcome) {
            'sent' => $this->finish($row, NotificationStatus::Sent, $measured + [
                'sent_at' => now(), 'provider' => $this->sms->name(), 'provider_message_id' => $result->messageId, 'last_error' => null,
                'cost' => round($length['parts'] * (float) $config['cost_per_part'], 2),
            ]),
            'skipped' => $this->finish($row, NotificationStatus::Skipped, $measured + ['skipped_reason' => $result->error]),
            'retry' => $this->retry($row, (int) $result->retryAfterSeconds, (string) $result->error),
            default => tap($this->finish($row, NotificationStatus::Failed, $measured + ['failed_at' => now(), 'last_error' => $result->error]), function () use ($result) {
                match ($result->error) {
                    'sms_timeout_unknown' => AdminAlerts::once('sms-timeout', 'bulksmsbd.net did not answer in time after an SMS was sent: it may or may not have gone out, so it was not repeated. Check the message log.'),
                    'sms_masking_needs_bangla' => AdminAlerts::once('sms-masking', 'bulksmsbd.net says messages from this masking sender ID must be in Bangla: an English SMS was refused. Use Bangla SMS templates or a non-masking sender ID.'),
                    'sms_missing_fields' => AdminAlerts::once('sms-fields', 'bulksmsbd.net said an SMS was missing required fields: this is a defect — tell the developer.'),
                    default => null,
                };
            }),
        };
    }

    private function fallBackToSms(NotificationMessage $whatsApp): void
    {
        try {
            $this->planner->smsFallback($whatsApp);
        } catch (Throwable $e) {
            Log::error('SMS fallback could not be planned', ['notification' => $whatsApp->id, 'error' => $e->getMessage()]);
            report($e);
        }
    }

    private function sendEmail(NotificationMessage $row): NotificationMessage
    {
        $row->increment('attempts');
        $invoice = $row->attachedInvoiceId() ? Invoice::query()->find($row->attachedInvoiceId()) : null;
        $pdf = null;
        if ($invoice) {
            try {
                $pdf = $this->invoicePdf->pdf($invoice, true, $row->locale, maskPassports: true);
            } catch (Throwable $e) {
                // The email still goes: its body carries the invoice link.
                Log::warning('Invoice PDF could not be attached to a notification email', ['notification' => $row->id, 'error' => $e->getMessage()]);
            }
        }

        try {
            Mail::to($row->to_address)->send(new NotificationMail($row, $invoice, $pdf));
        } catch (Throwable $e) {
            return $this->retry($row, 300, 'mail_error: '.mb_substr($e->getMessage(), 0, 200));
        }

        return $this->finish($row, NotificationStatus::Sent, ['sent_at' => now(), 'provider' => (string) config('mail.default'), 'last_error' => null, 'cost' => 0]);
    }

    /** Null when the message should still go; otherwise why it shouldn't. */
    private function noLongerWanted(NotificationMessage $row): ?string
    {
        $related = $row->related;
        if ($related instanceof Booking && $row->event->needsConfirmedBooking() && $related->status === BookingStatus::Cancelled) {
            return 'booking_cancelled';
        }
        if ($row->event === NotificationEvent::DocumentsPending && $related instanceof Booking && ! $related->travellers()->whereNull('passport_number')->exists()) {
            return 'no_longer_needed';
        }
        if (in_array($row->event, NotificationEvent::templated(), true)) {
            $enabled = NotificationTemplate::query()->where('event', $row->event->value)->where('channel', $row->channel->value)->value('is_enabled');
            if ($enabled !== null && ! (bool) $enabled) {
                return 'template_disabled';
            }
        }
        if ($row->channel === NotificationChannel::Email) {
            return config('bhabaghure.notifications.email.enabled') ? null : 'email_off';
        }
        // SMS comes from the operator-approved sender ID, not the notifications number, and a WhatsApp STOP is about
        // WhatsApp. Whether SMS is set up at all is the gateway's answer (sms_off, no sender ID…).
        if ($row->channel === NotificationChannel::Sms) {
            return null;
        }

        $recipient = $row->recipient_type === (new Customer)->getMorphClass() ? Customer::query()->find($row->recipient_id) : null;
        if ($recipient?->whatsapp_opted_out_at !== null && $row->event !== NotificationEvent::OptOutConfirmation) {
            return 'opted_out';
        }
        // A customer must be able to check the number before trusting it: nothing goes out from an unpublished number.
        $toCustomer = $row->recipient_type !== (new Staff)->getMorphClass();
        if ($toCustomer && NotificationSettings::notificationsNumber() === null) {
            return 'notifications_number_not_published';
        }

        return null;
    }

    private function render(NotificationMessage $row): bool
    {
        $template = NotificationTemplate::query()->where('event', $row->event->value)->where('channel', $row->channel->value)->first();
        if (! $template || ! $row->related) {
            return false;
        }
        $values = $this->variables->for($row->event, $row->related, $row->locale);
        $body = $this->renderer->fill($template->body($row->locale), $values);
        $row->forceFill([
            'body' => $row->channel === NotificationChannel::WhatsApp ? $this->renderer->whatsApp($body) : $body,
            'title' => $row->channel === NotificationChannel::Email ? $this->renderer->fill((string) $template->subject($row->locale), $values) : null,
        ])->save();

        return true;
    }

    private function retry(NotificationMessage $row, int $seconds, string $error): NotificationMessage
    {
        if ($row->attempts >= (int) config('bhabaghure.notifications.whatsapp.max_attempts')) {
            return tap($this->finish($row, NotificationStatus::Failed, ['failed_at' => now(), 'last_error' => $error]), $this->fallBackToSms(...));
        }
        match ($error) {
            'session_disconnected' => AdminAlerts::whatsAppSession('disconnected'),
            'invalid_api_key' => AdminAlerts::once('whatsapp-key', 'WaSender rejected the API key: automated WhatsApp messages are waiting. Check WASENDER_API_KEY in the API’s .env. Emails are still going out.'),
            'subscription_required' => AdminAlerts::once('whatsapp-subscription', 'WaSender says the subscription needs attention: automated WhatsApp messages are waiting. Emails are still going out.'),
            'sms_auth_failed' => AdminAlerts::once('sms-key', 'bulksmsbd.net rejected the API key or account: SMS messages are waiting. Check BULKSMSBD_API_KEY in the API’s .env and the account status.'),
            'sms_sender_id_rejected' => AdminAlerts::once('sms-sender', 'bulksmsbd.net rejected the sender ID: SMS messages are waiting. Check that BULKSMSBD_SENDER_ID is the operator-approved sender ID.'),
            'sms_balance_low' => AdminAlerts::once('sms-balance', 'The bulksmsbd.net balance has run out or the package validity has expired: SMS messages are waiting. Top up or renew the account.'),
            'sms_account_setup' => AdminAlerts::once('sms-account', 'bulksmsbd.net reports an account or price setup problem for the sender ID: SMS messages are waiting. Contact bulksmsbd support (the code is in the log).'),
            'sms_ip_not_whitelisted' => AdminAlerts::once('sms-ip', 'bulksmsbd.net refused the server’s IP address: add the VPS’s outbound IP to the IP whitelist in the bulksmsbd panel, or turn whitelisting off. SMS messages are waiting.'),
            'sms_tls_failed' => AdminAlerts::once('sms-tls', 'bulksmsbd.net could not be reached over HTTPS (certificate or TLS error): SMS messages are waiting. The API key is never sent over plain HTTP automatically — see docs/deployment.md §4a.'),
            default => null,
        };
        // WhatsApp needs someone to fix it (QR re-scan, key, subscription): the SMS goes now, WhatsApp keeps retrying.
        if ($row->channel === NotificationChannel::WhatsApp && in_array($error, self::WHATSAPP_UNAVAILABLE, true)) {
            $this->fallBackToSms($row);
        }

        return $this->reschedule($row, $seconds, $error);
    }

    /** Back to pending, due again later. The dispatcher picks it up; on a real queue the job is delayed too. */
    private function reschedule(NotificationMessage $row, int $seconds, string $why): NotificationMessage
    {
        $seconds = max(1, $seconds);
        $row->forceFill(['status' => NotificationStatus::Pending, 'scheduled_for' => now()->addSeconds($seconds), 'last_error' => $why])->save();
        if (config('queue.default') !== 'sync') {
            DeliverNotification::dispatch($row->id)->delay(now()->addSeconds($seconds));
        }

        return $row;
    }

    /** @param array<string, mixed> $attributes */
    private function finish(NotificationMessage $row, NotificationStatus $status, array $attributes): NotificationMessage
    {
        // A verification code is needed only until it goes out; the staff row keeps just its hash.
        if ($row->event === NotificationEvent::WhatsAppVerification) {
            $attributes['body'] = self::maskCodes($row->body);
        }
        $row->forceFill(['status' => $status] + $attributes)->save();

        return $row;
    }

    public static function maskCodes(string $body): string
    {
        return (string) preg_replace('/\b\d{6}\b/', '••••••', $body);
    }
}
