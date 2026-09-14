<?php

namespace App\Models\Concerns;

use App\Enums\ContentStatus;
use Illuminate\Database\Eloquent\Builder;

/** For CMS lists whose `status` is draft | published. */
trait HasPublicationStatus
{
    public function scopePublished(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('status'), ContentStatus::Published->value);
    }

    public function isPublished(): bool
    {
        return $this->status === ContentStatus::Published;
    }
}
