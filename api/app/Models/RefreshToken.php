<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RefreshToken extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'auth_refresh_tokens';

    protected $fillable = ['guard', 'subject_id', 'token_hash', 'family', 'expires_at', 'revoked_at', 'rotated_at', 'replaced_by_id', 'created_ip', 'user_agent'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'revoked_at' => 'datetime', 'rotated_at' => 'datetime'];
    }
}
