<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A public or office holiday: nobody is absent on it (docs/phase-7-hr-attendance-bonus-wallet.md §6). */
class Holiday extends Model
{
    protected $fillable = ['date', 'name_en', 'name_bn', 'created_by_staff_id'];

    protected function casts(): array
    {
        return ['date' => 'date'];
    }
}
