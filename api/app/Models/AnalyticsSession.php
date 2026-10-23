<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One browser tab's visit to the public website (docs/visitor-analytics.md). No IP address, no cookie, no person. */
class AnalyticsSession extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'last_seen_at' => 'datetime', 'page_views' => 'integer', 'engaged_seconds' => 'integer'];
    }

    public function events(): HasMany
    {
        return $this->hasMany(AnalyticsEvent::class, 'session_id');
    }
}
