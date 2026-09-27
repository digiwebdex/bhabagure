<?php

namespace App\Jobs;

use App\Models\ConversationMessage;
use App\Services\Inbox\InboxFiles;
use App\Services\Inbox\MessengerClient;
use App\Services\Inbox\WhatsAppInbox;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

/**
 * Keeps a customer's photo, document or voice note from WhatsApp or Messenger (docs/admin-inbox.md): both providers'
 * links expire (WaSender's in an hour), so the file is fetched at once and stored encrypted.
 */
class FetchInboxMedia implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    /** @var list<int> */
    public array $backoff = [30, 120, 600];

    public function __construct(public readonly int $messageId)
    {
        $this->onQueue('notifications');
    }

    public function handle(): void
    {
        $message = ConversationMessage::query()->find($this->messageId);
        $source = $message?->attachment_source;
        if (! $message || ! is_array($source) || $message->attachment_path !== null) {
            return;
        }
        $maxBytes = (int) config('bhabaghure.notifications.inbox.max_attachment_kb') * 1024;

        $url = match ($source['provider'] ?? null) {
            'wasender' => WhatsAppInbox::gateway()?->decryptMedia((array) $source['message']),
            'messenger' => is_string($source['url'] ?? null) ? $source['url'] : null,
            default => null,
        };
        if ($url === null) {
            $this->release($this->backoff[min($this->attempts() - 1, count($this->backoff) - 1)]);

            return;
        }
        $file = MessengerClient::download($url, $maxBytes, (string) config('bhabaghure.notifications.whatsapp.api_key') ?: null);
        if ($file === null) {
            $this->release($this->backoff[min($this->attempts() - 1, count($this->backoff) - 1)]);

            return;
        }

        $path = InboxFiles::store($file['bytes']);
        $mime = $message->attachment_mime ?: $file['mime'];
        $message->forceFill([
            'attachment_path' => $path,
            'attachment_mime' => Str::limit($mime, 100, ''),
            'attachment_bytes' => strlen($file['bytes']),
            'attachment_name' => $message->attachment_name ?: $message->attachment_kind.'.'.(self::extension($mime)),
            'attachment_source' => null,
        ])->save();
    }

    public function failed(Throwable $e): void
    {
        ConversationMessage::query()->whereKey($this->messageId)->whereNull('attachment_path')->update(['error' => 'media_unavailable']);
    }

    private static function extension(string $mime): string
    {
        return match (strtok($mime, ';')) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'audio/ogg' => 'ogg',
            'audio/mpeg' => 'mp3',
            'audio/mp4' => 'm4a',
            'video/mp4' => 'mp4',
            'application/pdf' => 'pdf',
            default => 'bin',
        };
    }
}
