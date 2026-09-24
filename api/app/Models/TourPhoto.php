<?php

namespace App\Models;

use App\Enums\ContentStatus;
use App\Models\Concerns\HasLocalizedFields;
use App\Models\Concerns\HasPublicationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A photo in the group tour gallery on the home page (docs/group-tour-gallery.md). */
class TourPhoto extends Model
{
    use HasLocalizedFields, HasPublicationStatus;

    protected $fillable = ['caption_bn', 'caption_en', 'media_id', 'trip_month', 'tour_package_id', 'status', 'sort_order'];

    protected function casts(): array
    {
        return ['status' => ContentStatus::class, 'trip_month' => 'date', 'sort_order' => 'integer'];
    }

    public function image(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'media_id');
    }

    public function tourPackage(): BelongsTo
    {
        return $this->belongsTo(TourPackage::class);
    }

    /** "2026-09", or null when the month isn't known. */
    public function tripMonthValue(): ?string
    {
        return $this->trip_month?->format('Y-m');
    }
}
