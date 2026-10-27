<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A photo beside one day of a landing page's itinerary, by day number (docs/tour-landing-pages.md). */
class TourLandingDayPhoto extends Model
{
    protected $fillable = ['day_number', 'media_id', 'sort_order'];

    protected function casts(): array
    {
        return ['day_number' => 'integer', 'sort_order' => 'integer'];
    }

    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }
}
