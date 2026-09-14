<?php

namespace App\Enums;

/** A message's life: pending → sending → sent → delivered → read, or failed / skipped / cancelled. */
enum NotificationStatus: string
{
    case Pending = 'pending';
    case Sending = 'sending';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Read = 'read';
    case Failed = 'failed';
    case Skipped = 'skipped';
    case Cancelled = 'cancelled';

    /** Delivery webhooks only move a message forward: a late "sent" never undoes "read". */
    public function rank(): int
    {
        return match ($this) {
            self::Pending => 0,
            self::Sending => 1,
            self::Sent => 2,
            self::Delivered => 3,
            self::Read => 4,
            self::Failed, self::Skipped, self::Cancelled => 5,
        };
    }

    public function isFinal(): bool
    {
        return ! in_array($this, [self::Pending, self::Sending], true);
    }
}
