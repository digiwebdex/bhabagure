<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One message on a support ticket. Append-only: what was said stays said. */
class SupportMessage extends Model
{
    use AppendOnly;

    public const UPDATED_AT = null;

    protected $fillable = ['support_ticket_id', 'author', 'staff_id', 'body'];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'support_ticket_id');
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }
}
