<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One PDF a signed-in customer downloaded from the website (docs/phase-8-visa-quotes-pricing-downloads.md §4.E). Written once. */
class Download extends Model
{
    public const PACKAGE = 'package';

    public const VISA = 'visa';

    public const UPDATED_AT = null;

    protected $fillable = ['customer_id', 'kind', 'tour_package_id', 'visa_service_id', 'title', 'hotel_category', 'pax', 'locale', 'ip'];

    protected function casts(): array
    {
        return ['pax' => 'integer', 'created_at' => 'datetime'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(TourPackage::class, 'tour_package_id');
    }

    public function visa(): BelongsTo
    {
        return $this->belongsTo(VisaService::class, 'visa_service_id');
    }
}
