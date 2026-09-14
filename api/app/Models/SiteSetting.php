<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Key → JSON value: contact details, hours, social links, service charge, supplement, stats. */
class SiteSetting extends Model
{
    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['key', 'value', 'updated_by_staff_id'];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return static::query()->find($key)?->value ?? $default;
    }
}
