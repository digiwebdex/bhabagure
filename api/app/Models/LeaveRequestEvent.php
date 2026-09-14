<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One step of a leave request: filed, approved, rejected, cancelled or revoked, with who and why. Append-only. */
class LeaveRequestEvent extends Model
{
    use AppendOnly;

    public const UPDATED_AT = null;

    protected $fillable = ['leave_request_id', 'action', 'actor_staff_id', 'note'];

    public function request(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class, 'leave_request_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'actor_staff_id');
    }
}
