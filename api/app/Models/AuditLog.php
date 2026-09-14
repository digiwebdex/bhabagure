<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AuditLog extends Model
{
    use AppendOnly;

    public const UPDATED_AT = null;

    protected $fillable = ['actor_type', 'actor_id', 'action', 'auditable_type', 'auditable_id', 'changes', 'ip', 'user_agent'];

    protected function casts(): array
    {
        return ['changes' => 'array'];
    }

    public function actor(): MorphTo
    {
        return $this->morphTo();
    }
}
