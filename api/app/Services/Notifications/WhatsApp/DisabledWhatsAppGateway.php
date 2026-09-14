<?php

namespace App\Services\Notifications\WhatsApp;

use App\Services\Notifications\SendResult;

/** WASENDER_MODE=off, or no key configured: WhatsApp messages are logged as skipped and email carries on alone. */
final class DisabledWhatsAppGateway implements WhatsAppGateway
{
    public function __construct(private readonly string $reason = 'whatsapp_off') {}

    public function name(): string
    {
        return 'off';
    }

    public function sendText(string $to, string $text): SendResult
    {
        return SendResult::skipped($this->reason);
    }

    public function sendDocument(string $to, string $documentUrl, string $fileName, string $caption): SendResult
    {
        return SendResult::skipped($this->reason);
    }

    public function sessionStatus(): string
    {
        return 'off';
    }

    public function messageInfo(string $providerMessageId): ?array
    {
        return null;
    }
}
