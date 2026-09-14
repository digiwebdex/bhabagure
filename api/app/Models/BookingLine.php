<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A priced line of the booking's quote (package, single-room supplement, add-on), as computed by PricingService. */
class BookingLine extends Model
{
    protected $fillable = ['kind', 'code', 'title_bn', 'title_en', 'quantity', 'unit_price', 'amount', 'sort_order'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'unit_price' => 'decimal:2', 'amount' => 'decimal:2', 'sort_order' => 'integer'];
    }
}
