<?php

namespace App\Events;

use App\Models\Inquiry;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** A website contact or air-ticket quote form was submitted. */
class InquiryReceived implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly Inquiry $inquiry) {}
}
