<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A saved label for the reference field of a manual cash entry ("Office rent", "Pokhara Grande advance"). */
class ReferencePreset extends Model
{
    protected $fillable = ['label', 'direction', 'sort_order', 'created_by_staff_id'];

    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }
}
