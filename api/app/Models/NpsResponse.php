<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** How likely a customer is to recommend us after a completed trip, 0–10 (docs/phase-6-customer-portal.md §0.4). One per booking. */
class NpsResponse extends Model
{
    use AppendOnly;

    public const UPDATED_AT = null;

    /** 9–10: asked for a public review. */
    public const PROMOTER_FROM = 9;

    /** 0–6: the trip's owner follows up. */
    public const DETRACTOR_UP_TO = 6;

    protected $fillable = ['booking_id', 'customer_id', 'score', 'comment'];

    protected function casts(): array
    {
        return ['score' => 'integer'];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
