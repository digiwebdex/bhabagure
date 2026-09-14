<?php

namespace App\Models;

use App\Models\Concerns\HasLocalizedFields;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BlogCategory extends Model
{
    use HasLocalizedFields;

    /** Token names from packages/tokens — never a hex value. */
    public const TONES = ['blue', 'purple', 'orange'];

    protected $fillable = ['slug', 'name_bn', 'name_en', 'tone', 'sort_order'];

    public function posts(): HasMany
    {
        return $this->hasMany(BlogPost::class);
    }
}
