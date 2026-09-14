<?php

namespace App\Models;

use App\Models\Concerns\HasLocalizedFields;
use Illuminate\Database\Eloquent\Model;

class Addon extends Model
{
    use HasLocalizedFields;

    protected $fillable = ['code', 'name_bn', 'name_en', 'price', 'unit', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return ['price' => 'decimal:2', 'is_active' => 'boolean', 'sort_order' => 'integer'];
    }
}
