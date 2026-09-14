<?php

namespace App\Services\Customers;

use App\Models\Customer;
use App\Models\CustomerLoginCode;
use App\Services\Notifications\AdminAlerts;
use App\Services\Notifications\NotificationSettings;
use App\Services\Notifications\Sms\SmsGateway;
use App\Services\Notifications\WhatsApp\WhatsAppGateway;
use App\Support\Numerals;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * One-time sign-in codes for the customer portal (docs/phase-6-customer-portal.md §0.1, §3.1).
 *
 *  - Six digits, valid 10 minutes, five tries; only the newest code for a number works. Stored as an HMAC.
 *  - Sent by SMS; when SMS can't deliver, by WhatsApp — unless the customer turned WhatsApp off or the notifications
 *    number isn't published (Phase 4 rules).
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

    private const PER_HOUR = 5;

    public function __construct(private readonly SmsGateway $sms, private readonly WhatsAppGateway $whatsApp) {}

    /**
     * @return array{outcome: 'sent'|'throttled'|'undeliverable', retry_after: int}
     */
    public function send(string $phone, string $locale, ?string $ip, string $purpose = self::SIGN_IN): array
    {
        // The per-number limits count every purpose: a phone-change request is still a message to that number.
        $recent = CustomerLoginCode::query()->where('phone', $phone)->where('created_at', '>=', now()->subHour())->orderBy('created_at')->get();
        $last = $recent->last();
        if ($last !== null && $last->created_at->gt(now()->subMinute())) {
            return ['outcome' => 'throttled', 'retry_after' => max(1, 60 - (int) $last->created_at->diffInSeconds(now(), true))];
        }
        if ($recent->count() >= self::PER_HOUR) {
            return ['outcome' => 'throttled', 'retry_after' => max(1, 3600 - (int) $recent->first()->created_at->diffInSeconds(now(), true))];
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $text = match (true) {
            $purpose === self::CHANGE_PHONE && $locale === 'en' => "Bhabaghure Holidays code to add this number to your account: {$code}. Valid for ".self::MINUTES.' minutes. Never share it with anyone.',
            $purpose === self::CHANGE_PHONE => "ভবঘুরে হলিডেজ অ্যাকাউন্টে এই নম্বর যোগ করার কোড: {$code}। ".Numerals::number(self::MINUTES, 'bn').' মিনিট বৈধ। কাউকে জানাবেন না।',
            $locale === 'en' => "Bhabaghure Holidays sign-in code: {$code}. Valid for ".self::MINUTES.' minutes. Never share it with anyone.',
            default => "ভবঘুরে হলিডেজ লগইন কোড: {$code}। ".Numerals::number(self::MINUTES, 'bn').' মিনিট বৈধ। কাউকে জানাবেন না।',
        };
        $channel = $this->deliver($phone, $text);

        DB::transaction(function () use ($phone, $purpose, $code, $channel, $ip) {
            // Only the newest code for this purpose works.
            CustomerLoginCode::query()->where('phone', $phone)->where('purpose', $purpose)->whereNull('consumed_at')->where('expires_at', '>', now())->update(['expires_at' => now()]);
            CustomerLoginCode::query()->create([
                'phone' => $phone, 'purpose' => $purpose, 'code_hash' => self::hash($phone, $code), 'channel' => $channel, 'ip' => $ip,
                'expires_at' => now()->addMinutes(self::MINUTES),
            ]);
        });

        if ($channel === 'none') {
            AdminAlerts::once('customer-login-codes', 'A customer asked for a portal sign-in code, but neither SMS nor WhatsApp could send it. Customers can\'t sign in until one of them is switched on (deployment.md §3–§4).');

            return ['outcome' => 'undeliverable', 'retry_after' => 60];
        }

        return ['outcome' => 'sent', 'retry_after' => 60];
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

    /** SMS first; WhatsApp only when SMS can't and the Phase 4 rules allow a WhatsApp message to this number. */
    private function deliver(string $phone, string $text): string
    {
        try {
            if ($this->sms->send($phone, $text)->isSent()) {
                return 'sms';
            }
        } catch (Throwable $e) {
            report($e);
        }

        $optedOut = Customer::query()->where('phone', $phone)->whereNotNull('whatsapp_opted_out_at')->exists();
        if ($optedOut || NotificationSettings::notificationsNumber() === null) {
            return 'none';
        }
        try {
            return $this->whatsApp->sendText($phone, $text)->isSent() ? 'whatsapp' : 'none';
        } catch (Throwable $e) {
            report($e);

            return 'none';
        }
    }

    private static function hash(string $phone, string $code): string
    {
        return hash_hmac('sha256', "{$phone}|{$code}", (string) config('app.key'));
    }
}
