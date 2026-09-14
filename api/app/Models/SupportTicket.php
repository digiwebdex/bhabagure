<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A support request from the portal (docs/phase-6-customer-portal.md §3.5). Only App\Services\Support\SupportDesk
 * changes tickets and adds messages.
 */
class SupportTicket extends Model
{
    public const OPEN = 'open';

    public const ANSWERED = 'answered';

    public const CLOSED = 'closed';

    /** The reply the portal promises: an open ticket older than this is overdue. */
    public const REPLY_WITHIN_HOURS = 24;

    protected $fillable = ['number', 'customer_id', 'booking_id', 'subject', 'status', 'last_customer_message_at', 'last_staff_reply_at', 'closed_at', 'closed_by_staff_id'];

    protected function casts(): array
    {
        return ['last_customer_message_at' => 'datetime', 'last_staff_reply_at' => 'datetime', 'closed_at' => 'datetime'];
    }

    /** Waiting for staff for longer than the reply the portal promises. */
    public function scopeOverdue(Builder $query): void
    {
        $query->where($this->qualifyColumn('status'), self::OPEN)
            ->where($this->qualifyColumn('last_customer_message_at'), '<', now()->subHours(self::REPLY_WITHIN_HOURS));
    }

    public function isOverdue(): bool
    {
        return $this->status === self::OPEN && $this->last_customer_message_at->lt(now()->subHours(self::REPLY_WITHIN_HOURS));
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SupportMessage::class)->orderBy('id');
    }
}
