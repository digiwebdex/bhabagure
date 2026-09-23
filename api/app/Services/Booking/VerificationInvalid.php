<?php

namespace App\Services\Booking;

use RuntimeException;

/**
 * The code that proved the lead's mobile was used up or ran out between checking it and saving the booking — the same
 * code sent with two bookings at once. One code saves one booking (docs/booking-phone-verification.md).
 */
final class VerificationInvalid extends RuntimeException {}
