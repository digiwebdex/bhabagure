<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A frozen priced line of a quotation; the same shape as BookingLine so conversion copies it one to one. */
class QuotationLine extends Model
{
    protected $fillable = ['quotation_id', 'kind', 'code', 'title_bn', 'title_en', 'quantity', 'unit_price', 'amount', 'sort_order'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'unit_price' => 'decimal:2', 'amount' => 'decimal:2', 'sort_order' => 'integer'];
    }
}
