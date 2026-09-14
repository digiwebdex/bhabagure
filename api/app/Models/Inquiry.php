<?php

namespace App\Models;

use App\Enums\InquiryType;
use Illuminate\Database\Eloquent\Model;

class Inquiry extends Model
{
    protected $fillable = ['type', 'name', 'phone', 'email', 'tour_package_id', 'pax', 'details', 'locale', 'customer_id', 'status', 'ip'];

    protected function casts(): array
    {
        return ['type' => InquiryType::class, 'details' => 'array', 'pax' => 'integer'];
    }
}
