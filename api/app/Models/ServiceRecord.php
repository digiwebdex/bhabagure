<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What an invoice line sold (docs/invoice-services.md): an air ticket, a hotel booking or a tour package. Written with
 * the invoice's lines; an air ticket and a hotel booking show in Air ticketing and Hotel requests once the invoice is
 * issued, and their PNR, dates and status can change there or on the invoice from then on.
 */
class ServiceRecord extends Model
{
    public const AIR = 'air_ticket';

    public const HOTEL = 'hotel_booking';

    public const TOUR = 'tour_package';

    public const TYPES = [self::AIR, self::HOTEL, self::TOUR];

    public const ONE_WAY = 'one_way';

    public const ROUND_TRIP = 'round_trip';

    public const BOOKED = 'booked';

    public const CONFIRMED = 'confirmed';

    public const SCHEDULE_CHANGED = 'schedule_changed';

    /** Days before departure the Departure soon badge shows (the client, 2026-11-04), and turns red. */
    public const SOON_DAYS = 7;

    public const URGENT_DAYS = 3;

    protected $fillable = [
        'invoice_id', 'line_key', 'type', 'passenger_name', 'reference', 'trip_type', 'route_from', 'route_to', 'starts_on', 'ends_on',
        'hotel_name', 'room_type', 'package_name', 'tour_package_id', 'status', 'schedule_note', 'schedule_changed_at', 'updated_by_staff_id',
    ];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'schedule_changed_at' => 'datetime'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'updated_by_staff_id');
    }

    /** @return list<string> the statuses a record of this type can have */
    public static function statusesFor(string $type): array
    {
        return match ($type) {
            self::AIR => [self::BOOKED, self::CONFIRMED, self::SCHEDULE_CHANGED],
            self::HOTEL => [self::BOOKED, self::CONFIRMED],
            default => [],
        };
    }

    /** The permission prefix of the queue this type shows in; null for a tour package, which has none. */
    public function queuePermission(): ?string
    {
        return match ($this->type) {
            self::AIR => 'air_inquiries',
            self::HOTEL => 'hotel_inquiries',
            default => null,
        };
    }

    /** Sold: its invoice is issued. A draft's isn't sold yet, and a cancelled invoice's is off the main lists. */
    public function scopeSold(Builder $query): void
    {
        $query->whereHas('invoice', fn (Builder $invoice) => $invoice->where('status', Invoice::ISSUED));
    }

    public function scopeCancelled(Builder $query): void
    {
        $query->whereHas('invoice', fn (Builder $invoice) => $invoice->where('status', Invoice::VOID));
    }

    /** Whole days from today in Dhaka to the departure or check-in; negative once it has passed. */
    public function daysLeft(?string $today = null): ?int
    {
        if ($this->starts_on === null) {
            return null;
        }

        return (int) round((strtotime($this->starts_on->toDateString()) - strtotime($today ?? now('Asia/Dhaka')->toDateString())) / 86400);
    }
}
