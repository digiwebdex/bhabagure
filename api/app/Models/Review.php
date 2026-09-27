<?php

namespace App\Models;

use App\Enums\ContentStatus;
use App\Models\Concerns\HasLocalizedFields;
use App\Models\Concerns\HasPublicationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A traveller's review for "What travellers say": written by staff, or sent by a customer from the website with trip
 * photos and checked by staff before it shows (docs/customer-reviews.md).
 */
class Review extends Model
{
    use HasLocalizedFields, HasPublicationStatus;

    public const STAFF = 'staff';

    public const CUSTOMER = 'customer';

    protected $fillable = ['quote_bn', 'quote_en', 'reviewer_name', 'trip_label_bn', 'trip_label_en', 'rating', 'travelled_on', 'tour_package_id', 'status', 'sort_order', 'source', 'phone', 'booking_id'];

    protected $hidden = ['phone'];

    protected function casts(): array
    {
        return [
            'status' => ContentStatus::class, 'rating' => 'integer', 'travelled_on' => 'date', 'sort_order' => 'integer',
            'reviewed_at' => 'datetime', 'rejected_at' => 'datetime',
        ];
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(TourPackage::class, 'tour_package_id');
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function photos(): HasMany
    {
        return $this->hasMany(ReviewPhoto::class)->orderBy('sort_order')->orderBy('id');
    }

    /** A customer's review nobody has approved or rejected yet. */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('source', self::CUSTOMER)->whereNull('reviewed_at');
    }

    /** What the Reviews list edits and orders: staff reviews and approved customer ones — not waiting, not rejected. */
    public function scopeListed(Builder $query): Builder
    {
        return $query->whereNull('rejected_at')->where(fn (Builder $q) => $q->where('source', self::STAFF)->orWhereNotNull('reviewed_at'));
    }

    public function isPending(): bool
    {
        return $this->source === self::CUSTOMER && $this->reviewed_at === null;
    }
}
