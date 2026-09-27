<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A trip photo a customer added to their review (docs/customer-reviews.md). */
class ReviewPhoto extends Model
{
    protected $fillable = ['review_id', 'media_id', 'sort_order', 'is_shown'];

    protected function casts(): array
    {
        return ['sort_order' => 'integer', 'is_shown' => 'boolean'];
    }

    public function review(): BelongsTo
    {
        return $this->belongsTo(Review::class);
    }

    public function image(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'media_id');
    }
}
