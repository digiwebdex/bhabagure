<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One published version of a legal page (docs/legal-pages.md); never edited after it is written. */
class LegalPageVersion extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['slug', 'version', 'title_bn', 'title_en', 'body_bn', 'body_en', 'note', 'created_by_staff_id'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'created_at' => 'datetime'];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'created_by_staff_id')->withTrashed();
    }
}
