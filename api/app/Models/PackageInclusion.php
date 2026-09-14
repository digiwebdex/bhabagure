<?php

namespace App\Models;

use App\Models\Concerns\HasLocalizedFields;
use Illuminate\Database\Eloquent\Model;

class PackageInclusion extends Model
{
    use HasLocalizedFields;

    public const INCLUDE = 'include';

    public const EXCLUDE = 'exclude';

    protected $fillable = ['kind', 'text_en', 'text_bn', 'sort_order'];
}
