<?php

namespace App\Models;

use App\Models\Concerns\HasLocalizedFields;
use Illuminate\Database\Eloquent\Model;

class PackageItineraryDay extends Model
{
    use HasLocalizedFields;

    protected $fillable = ['day_number', 'title_en', 'title_bn', 'body_en', 'body_bn'];

    protected function casts(): array
    {
        return ['day_number' => 'integer'];
    }
}
