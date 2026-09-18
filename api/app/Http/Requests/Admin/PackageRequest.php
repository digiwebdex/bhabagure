<?php

namespace App\Http\Requests\Admin;

use App\Support\Pricing\PriceGrid;
use App\Support\Pricing\PricingService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The whole package, as the editor saves it: fields plus itinerary, inclusions and tags in one request.
 * Status is not accepted here; publishing is its own action with its own checks.
 */
class PackageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Route middleware checks packages.manage.
    }

    protected function prepareForValidation(): void
    {
        foreach (['title_bn', 'summary_bn', 'summary_en', 'seo_title_bn', 'seo_title_en', 'seo_description_bn', 'seo_description_en', 'difficulty'] as $field) {
            if ($this->has($field) && trim((string) $this->input($field)) === '') {
                $this->merge([$field => null]);
            }
        }

        // Price options: blank text is no text, and an empty list is no list.
        if ($this->has('price_options')) {
            $options = collect((array) $this->input('price_options'))
                ->map(fn ($option) => collect((array) $option)->map(fn ($value) => is_string($value) && trim($value) === '' ? null : $value)->all())
                ->values()
                ->all();
            $this->merge(['price_options' => $options === [] ? null : $options]);
        }

        // A hotel-category price grid replaces the one price and the group discounts (Phase 8 §4.D). The price older
        // screens show becomes the grid's reference price, and a grid package has no sale price (decided 2026-09-16).
        if ($this->has('price_grid')) {
            $grid = PriceGrid::normalize($this->input('price_grid'));
            $this->merge(['price_grid' => $grid]);
            if ($grid !== null && PricingService::gridCategories($grid) !== []) {
                $this->merge(['regular_price' => PriceGrid::referencePrice($grid), 'sale_price' => null]);
            }
        }
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            // Each option is either an extra with an amount or an estimate in words — one of the two, not both.
            foreach ((array) $this->input('price_options') as $index => $option) {
                $hasAmount = ($option['extra_per_person'] ?? null) !== null;
                $hasEstimate = ($option['estimate_en'] ?? null) !== null || ($option['estimate_bn'] ?? null) !== null;
                if ($hasAmount === $hasEstimate) {
                    $validator->errors()->add("price_options.{$index}", __('cms.price_option_amount_or_estimate'));
                }
            }

            $grid = $this->input('price_grid');
            if ($grid === null) {
                return;
            }
            foreach ($grid as $category => $row) {
                if (! isset($row['1'])) {
                    $validator->errors()->add("price_grid.{$category}", __('cms.grid_needs_one_traveller'));
                }
                foreach ($row as $price) {
                    if ($price < 1 || $price > 99999999) {
                        $validator->errors()->add("price_grid.{$category}", __('cms.grid_price_range'));
                        break;
                    }
                }
            }
        });
    }

    public function rules(): array
    {
        $id = $this->route('id');

        return [
            'code' => ['required', 'string', 'max:30', Rule::unique('tour_packages', 'code')->ignore($id)],
            'slug' => ['required', 'string', 'max:190', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('tour_packages', 'slug')->ignore($id)],
            'destination_id' => ['required', 'integer', 'exists:destinations,id'],
            'title_en' => ['required', 'string', 'max:255'],
            'title_bn' => ['nullable', 'string', 'max:255'],
            'summary_en' => ['nullable', 'string', 'max:500'],
            'summary_bn' => ['nullable', 'string', 'max:500'],
            'duration_days' => ['required', 'integer', 'between:1,60'],
            'duration_nights' => ['nullable', 'integer', 'between:0,60', 'lte:duration_days'],
            'regular_price' => ['required', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
            'sale_price' => ['nullable', 'numeric', 'min:0', 'decimal:0,2', 'lt:regular_price'],
            'price_grid' => ['nullable', 'array'],
            // What the package costs on top of its own price: an extra with a fixed amount per person, or a cost the
            // agency can only estimate (docs/package-price-options.md).
            'price_options' => ['nullable', 'array', 'max:6'],
            'price_options.*.label_en' => ['required', 'string', 'max:120'],
            'price_options.*.label_bn' => ['nullable', 'string', 'max:120'],
            'price_options.*.extra_per_person' => ['nullable', 'numeric', 'min:1', 'max:9999999', 'decimal:0,2'],
            'price_options.*.estimate_en' => ['nullable', 'string', 'max:120'],
            'price_options.*.estimate_bn' => ['nullable', 'string', 'max:120'],
            'includes_airfare' => ['nullable', 'boolean'],
            'group_mode' => ['required', Rule::in(['group', 'any'])],
            'min_pax' => ['nullable', 'integer', 'between:1,99'],
            'departure_mode' => ['required', Rule::in(['regular', 'any_date', 'on_request'])],
            'difficulty' => ['nullable', 'string', 'max:20'],
            'is_featured' => ['sometimes', 'boolean'],
            'seo_title_bn' => ['nullable', 'string', 'max:255'],
            'seo_title_en' => ['nullable', 'string', 'max:255'],
            'seo_description_bn' => ['nullable', 'string', 'max:500'],
            'seo_description_en' => ['nullable', 'string', 'max:500'],

            'itinerary' => ['present', 'array', 'max:60'],
            'itinerary.*.day_number' => ['required', 'integer', 'between:1,60', 'distinct'],
            'itinerary.*.title_en' => ['nullable', 'string', 'max:255'],
            'itinerary.*.title_bn' => ['nullable', 'string', 'max:255'],
            'itinerary.*.body_en' => ['required', 'string', 'max:5000'],
            'itinerary.*.body_bn' => ['nullable', 'string', 'max:5000'],

            'includes' => ['present', 'array', 'max:50'],
            'includes.*.text_en' => ['required', 'string', 'max:500'],
            'includes.*.text_bn' => ['nullable', 'string', 'max:500'],
            'excludes' => ['present', 'array', 'max:50'],
            'excludes.*.text_en' => ['required', 'string', 'max:500'],
            'excludes.*.text_bn' => ['nullable', 'string', 'max:500'],

            'activities' => ['sometimes', 'array', 'max:20'],
            'activities.*' => ['string', 'max:80', 'distinct'],
            'trip_types' => ['sometimes', 'array', 'max:20'],
            'trip_types.*' => ['string', 'max:80', 'distinct'],
        ];
    }
}
