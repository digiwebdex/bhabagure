<?php

namespace App\Services\Notifications;

/** The outcome of one send attempt on any channel's gateway, in terms the delivery job can act on. */
final class SendResult
{
    private function __construct(
        public readonly string $outcome,
        public readonly ?string $messageId = null,
        public readonly ?int $retryAfterSeconds = null,
        public readonly ?string $error = null,
    ) {}

    public static function sent(?string $messageId): self
    {
        return new self('sent', messageId: $messageId);
    }

    /** Try again later: rate limited, session disconnected, provider down. */
    public static function retry(string $error, int $afterSeconds): self
    {
        return new self('retry', retryAfterSeconds: $afterSeconds, error: $error);
    }

    /** Won't work however often it's tried: invalid number, rejected payload, wrong key. */
    public static function failed(string $error): self
    {
        return new self('failed', error: $error);
    }

    public static function skipped(string $reason): self
    {
        return new self('skipped', error: $reason);
    }

    public function isSent(): bool
    {
        return $this->outcome === 'sent';
    }
}
