<?php

namespace App\Models;

use App\Enums\StaffRole;
use App\Enums\StaffStatus;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject;
use Spatie\Permission\Traits\HasRoles;

class Staff extends Authenticatable implements JWTSubject
{
    use HasRoles, SoftDeletes;

    protected $table = 'staff';

    /** spatie/laravel-permission: this model's roles and permissions live on the staff guard. */
    protected string $guard_name = 'staff';

    protected $fillable = ['employee_code', 'name', 'email', 'phone', 'password', 'status', 'must_change_password', 'locale'];

    protected $hidden = ['password', 'whatsapp_code_hash'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'status' => StaffStatus::class,
            'must_change_password' => 'boolean',
            'last_login_at' => 'datetime',
            'whatsapp_verified_at' => 'datetime',
            'whatsapp_code_expires_at' => 'datetime',
            'whatsapp_code_attempts' => 'integer',
        ];
    }

    /** The number staff alerts go to — only once the staff member has confirmed it with a code sent there. */
    public function verifiedWhatsAppNumber(): ?string
    {
        return $this->whatsapp_verified_at !== null ? $this->whatsapp_number : null;
    }

    public function canSignIn(): bool
    {
        return $this->status !== StaffStatus::Suspended;
    }

    /** The HR record (docs/phase-7-hr-attendance-bonus-wallet.md §4.1), created with the staff member. */
    public function profile(): HasOne
    {
        return $this->hasOne(StaffProfile::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(StaffDocument::class);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(StaffInvitation::class);
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole(StaffRole::SuperAdmin->value);
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
