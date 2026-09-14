<?php

namespace App\Services\Notifications\Sms;

use App\Services\Notifications\SendResult;

/**
 * SMS_MODE=off, no API key, or no approved sender ID: SMS rows are recorded as not sent, with the reason. Sending from a
 * blank or unapproved sender ID is never attempted — operators drop those messages silently.
 */
final class DisabledSmsGateway implements SmsGateway
{
    public function __construct(private readonly string $reason = 'sms_off') {}

    public function name(): string
    {
        return 'off';
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function send(string $to, string $text): SendResult
    {
        return SendResult::skipped($this->reason);
    }
}
