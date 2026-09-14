<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A one-time sign-in code for the customer portal. Only its HMAC is stored. See App\Services\Customers\LoginCodes. */
class CustomerLoginCode extends Model
{
    protected $fillable = ['phone', 'purpose', 'code_hash', 'attempts', 'channel', 'ip', 'expires_at', 'consumed_at'];

    protected $hidden = ['code_hash'];

    protected function casts(): array
    {
        return ['attempts' => 'integer', 'expires_at' => 'datetime', 'consumed_at' => 'datetime'];
    }
}
