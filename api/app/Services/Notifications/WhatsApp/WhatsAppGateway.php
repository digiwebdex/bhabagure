<?php

namespace App\Services\Notifications\WhatsApp;

use App\Services\Notifications\SendResult;

/**
 * The WhatsApp provider behind one interface (docs/phase-4-whatsapp.md §3). WaSenderAPI today; because it is an
 * unofficial API that can lose its number overnight, nothing outside this namespace depends on it, and every money
 * message also goes by email.
 */
interface WhatsAppGateway
{
    public function name(): string;

    /** $to is a stored Bangladeshi mobile number, 8801XXXXXXXXX. */
    public function sendText(string $to, string $text): SendResult;

    /** A document (the invoice PDF) from a public URL, with the text as its caption. */
    public function sendDocument(string $to, string $documentUrl, string $fileName, string $caption): SendResult;

    /** connected · connecting · disconnected · need_scan · need_passkey · logged_out · expired · unknown · off */
    public function sessionStatus(): string;

    /**
     * What the provider knows about a sent message: WhatsApp's own message id (to match delivery webhooks) and its
     * status code (0 error, 1 pending, 2 sent, 3 delivered, 4 read, 5 played). Null when the provider can't tell.
     *
     * @return array{whatsapp_id: ?string, status: ?int}|null
     */
    public function messageInfo(string $providerMessageId): ?array;
}
