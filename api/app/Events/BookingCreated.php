<?php

namespace App\Events;

use App\Models\Booking;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** A booking was saved. $privateLink carries the one-time access token; it exists only in this request. */
class BookingCreated implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly Booking $booking, public readonly ?string $privateLink = null) {}
}
