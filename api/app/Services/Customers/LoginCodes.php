<?php

namespace App\Services\Customers;

use App\Mail\LoginCodeMail;
use App\Models\Customer;
use App\Models\CustomerLoginCode;
use App\Services\Notifications\AdminAlerts;
use App\Services\Notifications\MessageRenderer;
use App\Services\Notifications\NotificationSettings;
use App\Services\Notifications\Sms\SmsGateway;
use App\Services\Notifications\WhatsApp\WhatsAppGateway;
use App\Support\Numerals;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * One-time sign-in codes for the customer portal (docs/phase-6-customer-portal.md §0.1, §3.1).
 *
 *  - Six digits, valid 10 minutes, five tries; only the newest code for a number works. Stored as an HMAC.
 *  - Sent by every channel at once (client, 2026-09-25; docs/booking-phone-verification.md §6): SMS; WhatsApp unless the
 *    customer turned it off or the notifications number isn't published (Phase 4 rules); and email when there is an
 *    address and the mailer really sends. A phone-change code never goes by email: it must prove the new number.
 *  - Never written to the message log: a code is a credential, and staff read that log.
 *  - Limits per number: one a minute, five an hour. The route adds a per-address limit.
 */
final class LoginCodes
{
    public const MINUTES = 10;

    public const MAX_ATTEMPTS = 5;

    /** A code proves a number for one purpose only: a sign-in code can't confirm a phone change, nor the reverse. */
    public const SIGN_IN = 'sign_in';

    public const CHANGE_PHONE = 'change_phone';

    /** A website booking: the lead traveller's mobile proves itself before the booking is saved (docs/booking-phone-verification.md). */
    public const BOOKING = 'booking';

    private const PER_HOUR = 5;

    public function __construct(private readonly SmsGateway $sms, private readonly WhatsAppGateway $whatsApp) {}

    /**
     * @param  ?string  $email  where an email copy goes too (the lead's address for a booking, the account's for a sign-in)
     * @return array{outcome: 'sent'|'throttled'|'undeliverable', retry_after: int, channels: list<string>}
     */
    public function send(string $phone, string $locale, ?string $ip, string $purpose = self::SIGN_IN, ?string $email = null): array
    {
        // The per-number limits count every purpose: a phone-change request is still a message to that number.
        $recent = CustomerLoginCode::query()->where('phone', $phone)->where('created_at', '>=', now()->subHour())->orderBy('created_at')->get();
        $last = $recent->last();
        if ($last !== null && $last->created_at->gt(now()->subMinute())) {
            return ['outcome' => 'throttled', 'retry_after' => max(1, 60 - (int) $last->created_at->diffInSeconds(now(), true)), 'channels' => []];
        }
        if ($recent->count() >= self::PER_HOUR) {
            return ['outcome' => 'throttled', 'retry_after' => max(1, 3600 - (int) $recent->first()->created_at->diffInSeconds(now(), true)), 'channels' => []];
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $text = match (true) {
            $purpose === self::CHANGE_PHONE && $locale === 'en' => "Bhabaghure Holidays code to add this number to your account: {$code}. Valid for ".self::MINUTES.' minutes. Never share it with anyone.',
            $purpose === self::CHANGE_PHONE => "ভবঘুরে হলিডেজ অ্যাকাউন্টে এই নম্বর যোগ করার কোড: {$code}। ".Numerals::number(self::MINUTES, 'bn').' মিনিট বৈধ। কাউকে জানাবেন না।',
            $purpose === self::BOOKING && $locale === 'en' => "Bhabaghure Holidays booking code: {$code}. Valid for ".self::MINUTES.' minutes. Never share it with anyone.',
            $purpose === self::BOOKING => "ভবঘুরে হলিডেজ বুকিং কোড: {$code}। ".Numerals::number(self::MINUTES, 'bn').' মিনিট বৈধ। কাউকে জানাবেন না।',
            $locale === 'en' => "Bhabaghure Holidays sign-in code: {$code}. Valid for ".self::MINUTES.' minutes. Never share it with anyone.',
            default => "ভবঘুরে হলিডেজ লগইন কোড: {$code}। ".Numerals::number(self::MINUTES, 'bn').' মিনিট বৈধ। কাউকে জানাবেন না।',
        };
        $channels = $this->deliver($phone, $text, $purpose === self::CHANGE_PHONE ? null : $email, fn () => new LoginCodeMail($code, $purpose, $locale));
        $channel = $channels === [] ? 'none' : implode(',', $channels);

        DB::transaction(function () use ($phone, $purpose, $code, $channel, $ip) {
            // Only the newest code for this purpose works.
            CustomerLoginCode::query()->where('phone', $phone)->where('purpose', $purpose)->whereNull('consumed_at')->where('expires_at', '>', now())->update(['expires_at' => now()]);
            CustomerLoginCode::query()->create([
                'phone' => $phone, 'purpose' => $purpose, 'code_hash' => self::hash($phone, $code), 'channel' => $channel, 'ip' => $ip,
                'expires_at' => now()->addMinutes(self::MINUTES),
            ]);
        });

        if ($channels === []) {
            $purpose === self::BOOKING
                ? AdminAlerts::once('booking-codes', 'A customer tried to book on the website, but neither SMS, WhatsApp nor email could send the booking code. While the booking code check is on (Site settings → Website booking), website bookings can\'t be completed: switch it off, or get SMS or email working (deployment.md §3–§4).')
                : AdminAlerts::once('customer-login-codes', 'A customer asked for a portal sign-in code, but neither SMS, WhatsApp nor email could send it. Customers can\'t sign in until one of them works (deployment.md §3–§4).');

            return ['outcome' => 'undeliverable', 'retry_after' => 60, 'channels' => []];
        }

        return ['outcome' => 'sent', 'retry_after' => 60, 'channels' => $channels];
    }

    /** Whether the code went only to the phone (SMS or WhatsApp), so typing it back proves the number itself. */
    public static function provedPhone(CustomerLoginCode $row): bool
    {
        return $row->channel !== 'none' && ! in_array('email', explode(',', $row->channel), true);
    }

    /**
     * The live code this number was sent, if $code matches it. A wrong code uses up a try; the match is not consumed
     * here — the caller consumes it once the sign-in succeeds (a new customer may still have to give a name).
     */
    public function match(string $phone, string $code, string $purpose = self::SIGN_IN): ?CustomerLoginCode
    {
        return DB::transaction(function () use ($phone, $code, $purpose) {
            $row = CustomerLoginCode::query()->where('phone', $phone)->where('purpose', $purpose)->whereNull('consumed_at')->where('expires_at', '>', now())
                ->latest('id')->lockForUpdate()->first();
            if ($row === null || $row->attempts >= self::MAX_ATTEMPTS) {
                return null;
            }
            if (! hash_equals($row->code_hash, self::hash($phone, $code))) {
                $row->increment('attempts');

                return null;
            }

            return $row;
        });
    }

    public function consume(CustomerLoginCode $row): void
    {
        $row->forceFill(['consumed_at' => now()])->save();
    }

    /**
     * When a code last reached a customer and when one last couldn't be sent, whatever it was for — shown beside the
     * booking check's switch in Site settings, so nobody switches it on while no code can arrive.
     *
     * @return array{lastSentAt: ?string, lastFailedAt: ?string}
     */
    public static function deliveryStatus(): array
    {
        $last = fn (bool $sent) => CustomerLoginCode::query()->where('channel', $sent ? '!=' : '=', 'none')->max('created_at');
        $iso = fn (?string $at) => $at === null ? null : Carbon::parse($at, 'UTC')->toIso8601String();

        return ['lastSentAt' => $iso($last(true)), 'lastFailedAt' => $iso($last(false))];
    }

    /**
     * Every channel at once; one that fails doesn't stop the others. WhatsApp only where the Phase 4 rules allow a message
     * to this number; email only with an address and a mailer that really sends (not "log" or "array").
     *
     * @param  callable(): Mailable  $mail
     * @return list<string> the channels that took the code
     */
    private function deliver(string $phone, string $text, ?string $email, callable $mail): array
    {
        $channels = [];
        try {
            if ($this->sms->send($phone, $text)->isSent()) {
                $channels[] = 'sms';
            }
        } catch (Throwable $e) {
            report($e);
        }

        $optedOut = Customer::query()->where('phone', $phone)->whereNotNull('whatsapp_opted_out_at')->exists();
        if (! $optedOut && NotificationSettings::notificationsNumber() !== null) {
            try {
                // Every WhatsApp from the notifications number opens with the sender line (MessageRenderer); SMS carries
                // the operator's sender ID instead.
                if ($this->whatsApp->sendText($phone, MessageRenderer::senderLine()."\n".$text)->isSent()) {
                    $channels[] = 'whatsapp';
                }
            } catch (Throwable $e) {
                report($e);
            }
        }

        if (filled($email) && ! in_array(config('mail.default'), ['log', 'array'], true)) {
            try {
                Mail::to($email)->send($mail());
                $channels[] = 'email';
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $channels;
    }

    private static function hash(string $phone, string $code): string
    {
        return hash_hmac('sha256', "{$phone}|{$code}", (string) config('app.key'));
    }
}
