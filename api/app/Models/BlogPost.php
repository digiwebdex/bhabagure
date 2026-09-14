<?php

namespace App\Models;

use App\Enums\ContentStatus;
use App\Models\Concerns\HasLocalizedFields;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class BlogPost extends Model
{
    use HasLocalizedFields, SoftDeletes;

    protected $fillable = [
        'slug', 'blog_category_id', 'title_bn', 'title_en', 'excerpt_bn', 'excerpt_en', 'body_bn', 'body_en',
        'author_bn', 'author_en', 'cover_media_id', 'reading_minutes', 'reading_minutes_override',
        'seo_title_bn', 'seo_title_en', 'seo_description_bn', 'seo_description_en', 'status', 'published_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ContentStatus::class,
            'published_at' => 'datetime',
            'reading_minutes' => 'integer',
            'reading_minutes_override' => 'boolean',
        ];
    }

    /** Published, and its publication time has arrived. */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', ContentStatus::Published->value)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    public function isScheduled(): bool
    {
        return $this->status === ContentStatus::Published && $this->published_at !== null && $this->published_at->isFuture();
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(BlogCategory::class, 'blog_category_id');
    }

    public function cover(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'cover_media_id');
    }
}
