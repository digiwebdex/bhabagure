<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The device card's sync log: each report, batch of punches and command, as it happened. Append-only. */
class AttendanceSyncEvent extends Model
{
    use AppendOnly;

    public const UPDATED_AT = null;

    public const REPORT = 'report';

    public const PUNCHES = 'punches';

    public const COMMAND = 'command';

    public const TOKEN = 'token';

    protected $fillable = ['attendance_device_id', 'kind', 'status', 'received', 'stored', 'duplicates', 'rejected', 'detail', 'ip'];

    protected function casts(): array
    {
        return [
            'detail' => 'array',
            'received' => 'integer',
            'stored' => 'integer',
            'duplicates' => 'integer',
            'rejected' => 'integer',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(AttendanceDevice::class, 'attendance_device_id');
    }
}
