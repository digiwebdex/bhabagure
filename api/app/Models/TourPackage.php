<?php

namespace App\Models;

use App\Enums\PackageStatus;
use App\Models\Concerns\HasLocalizedFields;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class TourPackage extends Model
{
    use HasLocalizedFields, SoftDeletes;

    protected $fillable = [
        'code', 'wp_trip_id', 'slug', 'destination_id', 'title_en', 'title_bn', 'summary_en', 'summary_bn',
        'duration_days', 'duration_nights', 'regular_price', 'sale_price', 'price_grid', 'price_options', 'includes_airfare', 'group_mode',
        'min_pax', 'departure_mode', 'difficulty', 'source_image_url', 'seo_title_bn', 'seo_title_en',
        'seo_description_bn', 'seo_description_en', 'status', 'published_at', 'is_featured', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'status' => PackageStatus::class,
            'regular_price' => 'decimal:2',
            'sale_price' => 'decimal:2',
            'price_grid' => 'array',
            // [{label_bn, label_en, extra_per_person|null, estimate_bn|null, estimate_en|null}] — docs/package-price-options.md
            'price_options' => 'array',
            'includes_airfare' => 'boolean',
            'is_featured' => 'boolean',
            'published_at' => 'datetime',
            'duration_days' => 'integer',
            'duration_nights' => 'integer',
            'min_pax' => 'integer',
            'wp_trip_id' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', PackageStatus::Published->value);
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(Destination::class);
    }

    public function itineraryDays(): HasMany
    {
        return $this->hasMany(PackageItineraryDay::class)->orderBy('day_number');
    }

    public function inclusions(): HasMany
    {
        return $this->hasMany(PackageInclusion::class)->orderBy('sort_order');
    }

    public function images(): HasMany
    {
        return $this->hasMany(PackageImage::class)->orderByDesc('is_cover')->orderBy('sort_order');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'package_tag')->withPivot('sort_order')->orderByPivot('sort_order');
    }

    /**
     * Replaces the package's tags, keeping the given order.
     *
     * @param  array<string, list<string>>  $namesByType  e.g. [Tag::ACTIVITY => ['Boating', 'Hiking']]
     */
    public function syncTagNames(array $namesByType): void
    {
        $pivot = [];
        foreach ($namesByType as $type => $names) {
            foreach ($names as $name) {
                $tag = Tag::query()->firstOrCreate(['type' => $type, 'slug' => Str::slug($name)], ['name_en' => trim($name)]);
                $pivot[$tag->id] = ['sort_order' => count($pivot) + 1];
            }
        }

        $this->tags()->sync($pivot);
    }

    public function departures(): HasMany
    {
        return $this->hasMany(PackageDeparture::class);
    }
}
