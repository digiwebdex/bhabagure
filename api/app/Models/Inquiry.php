<?php

namespace App\Models;

use App\Enums\InquiryType;
use App\Enums\NotificationChannel;
use App\Enums\NotificationEvent;
use App\Models\Concerns\OwnedByStaff;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Website enquiries. Air-ticket enquiries are worked from the Air ticketing queue (docs/phase-5-admin-core.md §4.7), hotel
 * quotation requests from Hotel requests (docs/phase-8-visa-quotes-pricing-downloads.md §4.B): open until someone marks
 * them quoted; flagged when open for more than 24 hours.
 *
 * Visibility is per queue: the routes of each queue require its own `.view` permission and always filter by kind
 * (`ofType`), so seesAll/seesOwn only have to say whether this staff member works any queue.
 */
class Inquiry extends Model
{
    use OwnedByStaff;

    public const OPEN = 'new';

    public const QUOTED = 'quoted';

    /** Hours an enquiry may wait before the queue flags it and the badge counts it. */
    public const STALE_AFTER_HOURS = 24;

    protected $fillable = ['type', 'name', 'phone', 'email', 'tour_package_id', 'pax', 'details', 'locale', 'customer_id', 'status', 'assigned_staff_id', 'ip'];

    protected function casts(): array
    {
        return ['type' => InquiryType::class, 'details' => 'array', 'pax' => 'integer', 'quoted_at' => 'datetime'];
    }

    public static function seesAll(Staff $staff): bool
    {
        return self::seesOwn($staff) && $staff->can('bookings.view_all');
    }

    public static function seesOwn(Staff $staff): bool
    {
        return collect(InquiryType::queued())->contains(fn (InquiryType $type) => $staff->can("{$type->queuePermission()}.view"));
    }

    /** The pool: unowned enquiries nobody has quoted yet. */
    public function scopeClaimable(Builder $query): void
    {
        $query->whereNull($this->qualifyColumn('assigned_staff_id'))->where($this->qualifyColumn('status'), self::OPEN);
    }

    /**
     * The queue's filters, shared by GET /admin/air-inquiries and its sidebar badge so the two can never disagree.
     *
     * @param  array{state?: ?string, stale?: mixed, owner?: ?string, search?: ?string}  $filters
     */
    public function scopeFiltered(Builder $query, array $filters, Staff $staff): void
    {
        $query
            ->when(($filters['state'] ?? 'open') === 'open', fn (Builder $q) => $q->open())
            ->when(($filters['state'] ?? null) === 'quoted', fn (Builder $q) => $q->where('status', self::QUOTED))
            ->when(filter_var($filters['stale'] ?? false, FILTER_VALIDATE_BOOLEAN), fn (Builder $q) => $q->stale())
            ->when(($filters['owner'] ?? null) === 'mine', fn (Builder $q) => $q->where('assigned_staff_id', $staff->id))
            ->when(($filters['owner'] ?? null) === 'pool', fn (Builder $q) => $q->claimable())
            ->when($filters['search'] ?? null, fn (Builder $q, string $search) => $q->where(fn (Builder $inner) => $inner
                ->where('name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%")));
    }

    public function scopeAirQuotes(Builder $query): void
    {
        $query->ofType(InquiryType::AirQuote);
    }

    public function scopeOfType(Builder $query, InquiryType $type): void
    {
        $query->where($this->qualifyColumn('type'), $type);
    }

    public function scopeOpen(Builder $query): void
    {
        $query->where($this->qualifyColumn('status'), self::OPEN);
    }

    /** Open and older than 24 hours — the rows the queue flags and the sidebar badge counts. */
    public function scopeStale(Builder $query): void
    {
        $query->open()->where($this->qualifyColumn('created_at'), '<', now()->subHours(self::STALE_AFTER_HOURS));
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** The package a contact enquiry was sent from, if any. */
    public function package(): BelongsTo
    {
        return $this->belongsTo(TourPackage::class, 'tour_package_id');
    }

    public function quotedBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'quoted_by_staff_id');
    }

    /** Staff replies to the customer: one WhatsApp row per reply (its email twin shares the group). */
    public function replies(): MorphMany
    {
        return $this->morphMany(NotificationMessage::class, 'related')
            ->where('event', NotificationEvent::InquiryReply->value)->where('channel', NotificationChannel::WhatsApp->value);
    }

    /** Every message about this request: the staff alerts and the replies. */
    public function notifications(): MorphMany
    {
        return $this->morphMany(NotificationMessage::class, 'related');
    }
}
