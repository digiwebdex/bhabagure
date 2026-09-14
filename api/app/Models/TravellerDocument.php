<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A traveller's document slot (docs/phase-6-customer-portal.md §3.3). Uploads (passport scan, photo) are encrypted files
 * staff verify or reject; visa and insurance are statuses staff set. Only App\Services\Documents\TravellerDocuments
 * writes these rows.
 */
class TravellerDocument extends Model
{
    public const PASSPORT_SCAN = 'passport_scan';

    public const PHOTO = 'photo';

    public const VISA = 'visa';

    public const INSURANCE = 'insurance';

    public const UPLOADS = [self::PASSPORT_SCAN, self::PHOTO];

    public const ISSUED = [self::VISA, self::INSURANCE];

    public const KINDS = [self::PASSPORT_SCAN, self::PHOTO, self::VISA, self::INSURANCE];

    public const UPLOADED = 'uploaded';

    public const VERIFIED = 'verified';

    public const REJECTED = 'rejected';

    public const PENDING = 'pending';

    public const ISSUED_STATUS = 'issued';

    public const NOT_REQUIRED = 'not_required';

    protected $fillable = [
        'booking_traveller_id', 'kind', 'status', 'disk', 'path', 'mime', 'bytes', 'note', 'source', 'uploaded_at', 'reviewed_by_staff_id', 'reviewed_at',
    ];

    protected $hidden = ['disk', 'path'];

    protected function casts(): array
    {
        return ['bytes' => 'integer', 'uploaded_at' => 'datetime', 'reviewed_at' => 'datetime'];
    }

    public function traveller(): BelongsTo
    {
        return $this->belongsTo(BookingTraveller::class, 'booking_traveller_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'reviewed_by_staff_id');
    }
}
