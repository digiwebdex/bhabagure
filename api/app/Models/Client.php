<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A billed organisation: corporate account or B2B agency. */
class Client extends Model
{
    use SoftDeletes;

    protected $fillable = ['type', 'name', 'contact_name', 'contact_phone', 'contact_email', 'city', 'payment_terms', 'credit_limit', 'agent_tier', 'travel_policy', 'status'];

    protected function casts(): array
    {
        return ['credit_limit' => 'decimal:2'];
    }
}
