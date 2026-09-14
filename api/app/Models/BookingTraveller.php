<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BookingTraveller extends Model
{
    protected $fillable = [
        'booking_id', 'customer_id', 'is_lead', 'full_name', 'date_of_birth', 'nationality', 'passport_number',
        'passport_expiry', 'phone', 'email', 'emergency_contact_name', 'emergency_contact_phone', 'sort_order',
    ];

    protected $hidden = ['passport_number', 'passport_number_hash', 'passport_scan_path'];

    protected function casts(): array
    {
        return [
            'is_lead' => 'boolean',
            'date_of_birth' => 'date',
            'passport_expiry' => 'date',
            // AES-256 with APP_KEY. Losing APP_KEY makes these unrecoverable — back it up outside the server and git.
            'passport_number' => 'encrypted',
            'ocr_filled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $traveller) {
            if ($traveller->isDirty('passport_number')) {
                $number = $traveller->passport_number;
                $traveller->passport_number_hash = $number === null ? null : self::passportHash($number);
            }
        });
    }

    /** HMAC for lookup and duplicate checks without decrypting every row. */
    public static function passportHash(string $number): string
    {
        return hash_hmac('sha256', strtoupper(preg_replace('/\s+/', '', $number)), (string) config('app.key'));
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(TravellerDocument::class);
    }
}
