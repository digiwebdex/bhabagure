<?php

namespace App\Enums;

/** docs/phase-3-booking.md §3. Transitions live in App\Services\Booking\BookingStateMachine. */
enum BookingStatus: string
{
    case Inquiry = 'inquiry';
    case Confirmed = 'confirmed';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    /** @return list<self> */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Inquiry => [self::Confirmed, self::Cancelled],
            self::Confirmed => [self::Completed, self::Cancelled],
            self::Completed, self::Cancelled => [],
        };
    }

    public function isFinal(): bool
    {
        return $this->allowedNext() === [];
    }
}
