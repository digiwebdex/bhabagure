<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One entry in a customer's contact log (docs/phase-5-admin-core.md §4.4). Append-only: corrections are new entries. */
class CustomerContact extends Model
{
    use AppendOnly;

    public const UPDATED_AT = null;

    public const CHANNELS = ['call', 'whatsapp', 'facebook', 'visit', 'email', 'sms'];

    public const OUTCOMES = ['reached', 'no_answer', 'interested', 'not_interested', 'call_back', 'quote_requested'];

    /** System entries staff don't choose: an NPS answer of 0–6 from the portal (docs/phase-6-customer-portal.md §0.4). */
    public const PORTAL = 'portal';

    public const NPS_DETRACTOR = 'nps_detractor';

    protected $fillable = ['customer_id', 'staff_id', 'channel', 'outcome', 'note', 'next_follow_up_at', 'occurred_at'];

    protected function casts(): array
    {
        return ['next_follow_up_at' => 'datetime', 'occurred_at' => 'datetime'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }
}
