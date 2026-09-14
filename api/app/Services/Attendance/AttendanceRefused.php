<?php

namespace App\Services\Attendance;

use RuntimeException;

/** An attendance action the rules or the record's state don't allow. The reason is a lang key under attendance.* and the API's `code`. */
final class AttendanceRefused extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
