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

    protected $fillable = ['name', 'phone', 'email', 'password', 'stage', 'source', 'address', 'client_id', 'assigned_staff_id', 'locale', 'notes'];

    protected $hidden = ['password', 'active_phone', 'active_email'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'phone_verified_at' => 'datetime',
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'whatsapp_opted_out_at' => 'datetime',
        ];
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
