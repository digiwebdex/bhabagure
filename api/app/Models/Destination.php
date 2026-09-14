<?php

namespace App\Models;

use App\Models\Concerns\HasLocalizedFields;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Destination extends Model
{
    use HasLocalizedFields;

    protected $fillable = ['slug', 'name_bn', 'name_en', 'country_code', 'region', 'sort_order'];

    public function packages(): HasMany
    {
        return $this->hasMany(TourPackage::class);
    }
}
