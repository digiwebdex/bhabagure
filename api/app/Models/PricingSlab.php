<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PricingSlab extends Model
{
    protected $fillable = ['min_pax', 'discount_percent'];

    protected function casts(): array
    {
        return ['min_pax' => 'integer', 'discount_percent' => 'float'];
    }
}
