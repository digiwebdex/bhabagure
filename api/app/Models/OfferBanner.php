<?php

namespace App\Models;

use App\Enums\ContentStatus;
use App\Models\Concerns\HasLocalizedFields;
use App\Models\Concerns\HasPublicationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An offer banner in the slideshow under the hero video (docs/offer-banners.md). */
class OfferBanner extends Model
{
    use HasLocalizedFields, HasPublicationStatus;

    protected $fillable = ['title_bn', 'title_en', 'media_id', 'link_url', 'status', 'sort_order'];

    protected function casts(): array
    {
        return ['status' => ContentStatus::class, 'sort_order' => 'integer'];
    }

    public function image(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'media_id');
    }
}
