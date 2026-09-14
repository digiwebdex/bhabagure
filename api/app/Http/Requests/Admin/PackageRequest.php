<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
