<?php

namespace App\Models;

use App\Enums\ContentStatus;
use App\Models\Concerns\HasLocalizedFields;
use App\Models\Concerns\HasPublicationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An airline the agency books, shown by its logo above the footer (docs/partners-and-payments.md). */
class AirlinePartner extends Model
{
    use HasLocalizedFields, HasPublicationStatus;

    protected $fillable = ['name_bn', 'name_en', 'media_id', 'website_url', 'status', 'sort_order'];

    protected function casts(): array
    {
        return ['status' => ContentStatus::class, 'sort_order' => 'integer'];
    }

    public function logo(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'media_id');
    }
}
