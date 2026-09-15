<?php

namespace App\Events;

use App\Models\Staff;
use App\Models\Transaction;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A cash book entry was reversed. Dispatched inside the reversal's transaction, not after it, so whatever the entry
 * settled (a month's salary, say) goes back to unsettled in the same commit as the reversal — or not at all.
 */
class CashEntryReversed
{
    use Dispatchable;

    public function __construct(
        public readonly Transaction $entry,
        public readonly Transaction $reversal,
        public readonly Staff $by,
        public readonly string $reason,
    ) {}
}
