<?php

namespace App\Models;

use App\Models\Concerns\OwnedByStaff;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject;

class Customer extends Authenticatable implements JWTSubject
{
    use OwnedByStaff, SoftDeletes;

    /** The lead board's columns, plus Lost (docs/phase-5-admin-core.md §4.4). Derived — never stored. */
    public const LEAD_STATES = ['new', 'contacted', 'quoted', 'converted', 'lost'];

    protected $fillable = ['name', 'phone', 'email', 'password', 'stage', 'source', 'interest', 'address', 'client_id', 'assigned_staff_id', 'locale', 'notes'];

    protected $hidden = ['password', 'active_phone', 'active_email'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'phone_verified_at' => 'datetime',
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'whatsapp_opted_out_at' => 'datetime',
            'lost_at' => 'datetime',
        ];
    }

    /**
     * Where a lead is, from what happened to it: Lost if marked lost; Converted with a booking that isn't cancelled;
     * Quoted with a quotation sent; Contacted with a contact logged; otherwise New.
     */
    public function scopeLeadState(Builder $query, string $state): void
    {
        $booked = fn (Builder $bookings) => $bookings->where('status', '!=', 'cancelled');
        $open = fn (Builder $q) => $q->whereNull($this->qualifyColumn('lost_at'));

        match ($state) {
            'lost' => $query->whereNotNull($this->qualifyColumn('lost_at')),
            'converted' => $query->where($open)->whereHas('bookings', $booked),
            // Quotations arrive with the Quotations screen; until then nothing is Quoted.
            'quoted' => $query->whereRaw('1 = 0'),
            'contacted' => $query->where($open)->whereDoesntHave('bookings', $booked)->whereHas('contacts'),
            'new' => $query->where($open)->whereDoesntHave('bookings', $booked)->whereDoesntHave('contacts'),
        };
    }

    /** The same rule for one loaded row (needs has_booking and has_contact from withExists). */
    public function leadState(): string
    {
        return match (true) {
            $this->lost_at !== null => 'lost',
            (bool) $this->has_booking => 'converted',
            (bool) ($this->has_quote ?? false) => 'quoted',
            (bool) $this->has_contact => 'contacted',
            default => 'new',
        };
    }

    /** withExists columns the leadState() rule reads. */
    public function scopeWithLeadFacts(Builder $query): void
    {
        $query->withExists(['bookings as has_booking' => fn (Builder $b) => $b->where('status', '!=', 'cancelled'), 'contacts as has_contact']);
    }

    /** Traveller rows that are this customer (the lead traveller on their bookings): where passports are on file. */
    public function travellerRecords(): HasMany
    {
        return $this->hasMany(BookingTraveller::class);
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(CustomerContact::class)->orderByDesc('occurred_at')->orderByDesc('id');
    }

    /** Staff who see every booking see every customer; the matrix gives sales agents "own customers" (phase-1-schema §5). */
    public static function seesAll(Staff $staff): bool
    {
        return $staff->can('customers.view') && $staff->can('bookings.view_all');
    }

    public static function seesOwn(Staff $staff): bool
    {
        return $staff->can('customers.view');
    }

    /** The pool: unowned leads. A customer who has booked belongs to whoever owns the booking. */
    public function scopeClaimable(Builder $query): void
    {
        $query->whereNull($this->qualifyColumn('assigned_staff_id'))->where($this->qualifyColumn('stage'), 'lead');
    }

    /** A repeat customer booked through another agent: that agent sees the customer too. */
    public function alsoVisibleToOwner(Builder $query, Staff $staff): void
    {
        $query->orWhereHas('bookings', fn (Builder $bookings) => $bookings->where('assigned_staff_id', $staff->id));
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function getJWTIdentifier(): mixed
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims(): array
    {
        return [];
    }
}
