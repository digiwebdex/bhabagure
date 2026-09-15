<?php

namespace App\Events;

use App\Models\Booking;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** A booking was claimed from the pool or reassigned (App\Services\Admin\Ownership). Its commission follows the owner. */
class BookingOwnerChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly Booking $booking) {}
}
