<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A page viewed, or something done, in an analytics session (docs/visitor-analytics.md). */
class AnalyticsEvent extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    /** Sent by the website itself. */
    public const PAGE_VIEW = 'page_view';

    public const PACKAGE_VIEW = 'package_view';

    public const BOOKING_STARTED = 'booking_started';

    /**
     * Recorded by the API when the action has succeeded — never by the website saying so — once per booking or
     * enquiry (docs/visitor-analytics.md §Conversions). Not portal sign-ins: the portal is its own site, untracked,
     * so a sign-in can't be tied to a website visit.
     */
    public const CONVERSIONS = ['booking_completed', 'enquiry', 'air_quote', 'hotel_quote', 'newsletter', 'review', 'payment_request'];

    /** What the website may send: page views and steps, never a conversion. */
    public const FROM_WEBSITE = [self::PAGE_VIEW, self::PACKAGE_VIEW, self::BOOKING_STARTED];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(AnalyticsSession::class, 'session_id');
    }
}
