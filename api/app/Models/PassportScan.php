<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PassportScan extends Model
{
    protected $fillable = ['token_hash', 'disk', 'path', 'mime', 'bytes', 'ocr_status', 'ocr_provider', 'booking_traveller_id', 'expires_at', 'ip'];

    protected $hidden = ['token_hash', 'path'];

    protected function casts(): array
    {
        return ['bytes' => 'integer', 'expires_at' => 'datetime'];
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
