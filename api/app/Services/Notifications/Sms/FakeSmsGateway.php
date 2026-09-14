<?php

namespace App\Services\Notifications\Sms;

use App\Services\Notifications\SendResult;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Local stand-in (SMS_MODE=fake; refused in production). Nothing leaves the machine: each message is written to
 * storage/logs/sms-fake.log so development and end-to-end tests can read what would have been sent.
 */
final class FakeSmsGateway implements SmsGateway
{
    /** @var list<array{to: string, text: string}> sends in this process, for tests */
    public static array $sent = [];

    /** Set to make the next sends fail, e.g. SendResult::failed('1001'). */
    public static ?SendResult $next = null;

    public function name(): string
    {
        return 'fake-sms';
    }

    public function send(string $to, string $text): SendResult
    {
        if (self::$next !== null) {
            return self::$next;
        }

        self::$sent[] = ['to' => $to, 'text' => $text];
        Log::build(['driver' => 'single', 'path' => storage_path('logs/sms-fake.log')])->info("SMS (fake) to {$to}\n{$text}");

        return SendResult::sent('FAKESMS'.Str::upper(Str::random(8)));
    }
}
