<?php

namespace App\Services\Notifications\WhatsApp;

use App\Services\Notifications\SendResult;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Local stand-in (WASENDER_MODE=fake; refused in production). Nothing leaves the machine: each message is written to
 * storage/logs/whatsapp-fake.log, so development and end-to-end tests can read what would have been sent.
 */
final class FakeWhatsAppGateway implements WhatsAppGateway
{
    /** @var list<array{to: string, text: string, document: ?string}> sends in this process, for tests */
    public static array $sent = [];

    public static string $status = 'connected';

    public function name(): string
    {
        return 'fake';
    }

    public function sendText(string $to, string $text): SendResult
    {
        return $this->record($to, $text, null);
    }

    public function sendDocument(string $to, string $documentUrl, string $fileName, string $caption): SendResult
    {
        return $this->record($to, $caption, "{$fileName} <{$documentUrl}>");
    }

    public function sessionStatus(): string
    {
        return self::$status;
    }

    public function messageInfo(string $providerMessageId): ?array
    {
        return ['whatsapp_id' => 'FAKEWA'.$providerMessageId, 'status' => 2];
    }

    private function record(string $to, string $text, ?string $document): SendResult
    {
        if (self::$status !== 'connected') {
            return SendResult::retry('session_disconnected', 300);
        }

        self::$sent[] = ['to' => $to, 'text' => $text, 'document' => $document];
        Log::build(['driver' => 'single', 'path' => storage_path('logs/whatsapp-fake.log')])
            ->info('WhatsApp (fake) to '.WaSenderGateway::e164($to).($document ? " with {$document}" : '')."\n".$text);

        return SendResult::sent((string) random_int(100000, 999999).Str::upper(Str::random(4)));
    }
}
