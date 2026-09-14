<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A staff member's HR record (docs/phase-7-hr-attendance-bonus-wallet.md §4.1), one per staff member. The NID and the
 * salary account are encrypted like passport numbers; `staff.manage` sees them, and each person sees their own.
 */
class StaffProfile extends Model
{
    public const PAYOUT_METHODS = ['bank', 'bkash', 'nagad', 'rocket', 'cash'];

    protected $fillable = [
        'staff_id', 'designation', 'joined_on', 'left_on', 'date_of_birth', 'nid_number', 'address',
        'emergency_contact_name', 'emergency_contact_phone', 'payout_method', 'payout_account', 'updated_by_staff_id',
    ];

    protected $hidden = ['nid_number_hash'];

    protected function casts(): array
    {
        return [
            'joined_on' => 'date',
            'left_on' => 'date',
            'date_of_birth' => 'date',
            // AES-256 with APP_KEY. Losing APP_KEY makes these unrecoverable — back it up outside the server and git.
            'nid_number' => 'encrypted',
            'payout_account' => 'encrypted',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $profile) {
            if ($profile->isDirty('nid_number')) {
                $profile->nid_number_hash = $profile->nid_number === null ? null : StaffDocument::numberHash($profile->nid_number);
            }
        });
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }
}
