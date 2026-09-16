<?php

namespace App\Models;

use App\Exceptions\InvoiceFrozen;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceItem extends Model
{
    protected $fillable = ['kind', 'title_en', 'title_bn', 'detail', 'note', 'quantity', 'unit_price', 'discount_amount', 'vat_rate', 'vat_amount', 'line_total', 'sort_order'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:2', 'unit_price' => 'decimal:2', 'discount_amount' => 'decimal:2', 'vat_rate' => 'decimal:2', 'vat_amount' => 'decimal:2', 'line_total' => 'decimal:2'];
    }

    protected static function booted(): void
    {
        // Lines of an issued invoice can't be added, changed or removed.
        foreach (['creating', 'updating', 'deleting'] as $event) {
            static::$event(function (self $item) {
                $status = Invoice::query()->whereKey($item->invoice_id)->value('status');
                if ($status !== null && $status !== Invoice::DRAFT) {
                    throw InvoiceFrozen::lines($item->invoice_id);
                }
            });
        }
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
