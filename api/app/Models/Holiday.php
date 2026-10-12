<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A public or office holiday: nobody is absent on it (docs/phase-7-hr-attendance-bonus-wallet.md §6). One row per day;
 * the days of one holiday entered as a date range share a `group_id` (docs/holiday-calendar.md).
 */
class Holiday extends Model
{
    protected $fillable = ['group_id', 'date', 'name_en', 'name_bn', 'created_by_staff_id'];

    protected function casts(): array
    {
        return ['date' => 'date'];
    }
}
