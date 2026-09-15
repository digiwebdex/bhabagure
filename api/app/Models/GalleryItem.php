<?php

namespace App\Models;

use App\Enums\ContentStatus;
use App\Models\Concerns\HasLocalizedFields;
use App\Models\Concerns\HasPublicationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GalleryItem extends Model
{
    use HasLocalizedFields, HasPublicationStatus;

    public const KINDS = ['reel', 'photo'];

    /** A Facebook video link the embedded player accepts. */
    public const REEL_URL = '#^https://(www\.|m\.)?facebook\.com/(reel/\d+|watch/?\?v=\d+|[^/?\#]+/videos/([^/?\#]+/)?\d+)/?([?\#].*)?$#';

    protected $fillable = ['kind', 'media_id', 'url', 'caption_bn', 'caption_en', 'view_count', 'status', 'sort_order'];

    protected function casts(): array
    {
        return ['status' => ContentStatus::class, 'view_count' => 'integer', 'sort_order' => 'integer'];
    }

    public function thumbnail(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'media_id');
    }
}
