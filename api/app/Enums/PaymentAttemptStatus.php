<?php

namespace App\Enums;

/** docs/phase-3-booking.md §3, payment attempts. Only `settled` credits money, and it is final. */
enum PaymentAttemptStatus: string
{
    case Initiated = 'initiated';
    case Redirected = 'redirected';
    case Settled = 'settled';
    case NeedsReview = 'needs_review';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    /** Still waiting for the customer or the gateway. */
    public function isOpen(): bool
    {
        return $this === self::Initiated || $this === self::Redirected;
    }

    /** A closed-but-unpaid attempt can still settle if the gateway later confirms the money was taken. */
    public function canSettle(): bool
    {
        return $this !== self::Settled && $this !== self::NeedsReview;
    }
}
