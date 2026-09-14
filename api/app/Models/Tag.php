<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Tag extends Model
{
    public const ACTIVITY = 'activity';

    public const TRIP_TYPE = 'trip_type';

    protected $fillable = ['type', 'slug', 'name_en', 'name_bn'];
}
