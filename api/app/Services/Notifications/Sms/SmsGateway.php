<?php

namespace App\Services\Notifications\Sms;

use App\Services\Notifications\SendResult;

/** Sends SMS. Implemented by BulkSmsBdGateway (live), FakeSmsGateway (local and tests) and DisabledSmsGateway. */
interface SmsGateway
{
    public function name(): string;

    /** @param string $to 8801XXXXXXXXX */
    public function send(string $to, string $text): SendResult;
}
