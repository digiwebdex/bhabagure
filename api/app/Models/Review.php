<?php

namespace App\Models;

use App\Enums\ContentStatus;
use App\Models\Concerns\HasLocalizedFields;
use App\Models\Concerns\HasPublicationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Review extends Model
{
    use HasLocalizedFields, HasPublicationStatus;

    protected $fillable = ['quote_bn', 'quote_en', 'reviewer_name', 'trip_label_bn', 'trip_label_en', 'rating', 'travelled_on', 'tour_package_id', 'status', 'sort_order'];

    protected function casts(): array
    {
        return ['status' => ContentStatus::class, 'rating' => 'integer', 'travelled_on' => 'date', 'sort_order' => 'integer'];
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(TourPackage::class, 'tour_package_id');
    }
}
