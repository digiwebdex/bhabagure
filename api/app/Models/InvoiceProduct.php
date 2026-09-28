<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One of the office's own products for invoices, beside the website packages (docs/invoice-items.md). */
class InvoiceProduct extends Model
{
    protected $fillable = ['name', 'description', 'unit_price', 'is_active', 'created_by_staff_id'];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }
}
