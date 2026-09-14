<?php

namespace App\Models;

use App\Models\Concerns\HasLocalizedFields;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Destination extends Model
{
    use HasLocalizedFields;

    protected $fillable = ['slug', 'name_bn', 'name_en', 'country_code', 'region', 'visa_on_arrival', 'sort_order'];

    protected function casts(): array
    {
        // Travellers get the visa on arrival: visa and insurance default to "not required" on its bookings.
        return ['visa_on_arrival' => 'boolean'];
    }

    public function packages(): HasMany
    {
        return $this->hasMany(TourPackage::class);
    }
}
