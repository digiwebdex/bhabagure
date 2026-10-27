<?php

namespace App\Models;

use App\Enums\ContentStatus;
use App\Models\Concerns\HasLocalizedFields;
use App\Models\Concerns\HasPublicationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A group tour's landing page (docs/tour-landing-pages.md): its address and how the tour is presented. The tour's own
 * content — title, prices, dates, the days' text, inclusions — is the package's and is read from it.
 */
class TourLandingPage extends Model
{
    use HasLocalizedFields, HasPublicationStatus;

    protected $fillable = [
        'slug', 'tour_package_id', 'title_en', 'title_bn', 'intro_en', 'intro_bn', 'hero_media_id', 'countdown_departure_id',
        'promote_on_home', 'sort_order',
    ];

    protected function casts(): array
    {
        return ['status' => ContentStatus::class, 'promote_on_home' => 'boolean', 'sort_order' => 'integer'];
    }

    public function tourPackage(): BelongsTo
    {
        return $this->belongsTo(TourPackage::class);
    }

    public function hero(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'hero_media_id');
    }

    public function countdownDeparture(): BelongsTo
    {
        return $this->belongsTo(PackageDeparture::class, 'countdown_departure_id');
    }

    public function dayPhotos(): HasMany
    {
        return $this->hasMany(TourLandingDayPhoto::class)->orderBy('day_number')->orderBy('sort_order');
    }
}
