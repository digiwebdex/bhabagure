<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A new email address waiting for the code sent to it. Only the code's HMAC is stored. See App\Services\Customers\ProfileChanges. */
class CustomerEmailChange extends Model
{
    protected $fillable = ['customer_id', 'email', 'code_hash', 'attempts', 'expires_at', 'consumed_at'];

    protected $hidden = ['code_hash'];

    protected function casts(): array
    {
        return ['attempts' => 'integer', 'expires_at' => 'datetime', 'consumed_at' => 'datetime'];
    }
}
