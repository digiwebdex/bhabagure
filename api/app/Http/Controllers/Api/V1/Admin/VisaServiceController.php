<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Admin\Concerns\ManagesPublishedList;
use App\Http\Controllers\Controller;
use App\Http\Resources\AdminContent;
use App\Models\VisaService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Visa services (docs/phase-8-visa-quotes-pricing-downloads.md §4.C): the countries and visa types the agency processes,
 * with price, processing time, stay, requirements and notes in both languages. Plain text only; the website escapes it.
 */
class VisaServiceController extends Controller
{
    use ManagesPublishedList;

    protected function modelClass(): string
    {
        return VisaService::class;
    }

    protected function cacheTag(): string
    {
        return 'visas';
    }

    protected function auditName(): string
    {
        return 'cms.visa_service';
    }

    protected function present(Model $model): array
    {
        return AdminContent::visaService($model);
    }

    public function index(): JsonResponse
    {
        return response()->json(['data' => VisaService::query()->orderBy('sort_order')->orderBy('id')->get()->map(AdminContent::visaService(...))]);
    }

    public function store(Request $request): JsonResponse
    {
        $visa = VisaService::query()->create([
            ...$this->validated($request),
            'status' => 'draft',
            'sort_order' => (int) VisaService::query()->max('sort_order') + 1,
        ]);

        return $this->saved($request, $visa, 'created', 201);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(['data' => $this->present(VisaService::query()->findOrFail($id))]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $visa = VisaService::query()->findOrFail($id);
        $visa->update($this->validated($request, $visa));

        return $this->saved($request, $visa, 'updated');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $visa = VisaService::query()->findOrFail($id);
        $visa->delete();

        return $this->saved($request, $visa, 'deleted');
    }

    /** What a visitor needs before it goes on the website: the country, the visa type, how long it takes, and what to bring. */
    protected function publishProblems(Model $model): array
    {
        return array_values(array_filter([
            blank($model->processing_bn) && blank($model->processing_en) ? __('cms.publish_requirements.processing') : null,
            // Headings alone list nothing to bring: at least one requirement under them, in each language.
            VisaService::groups(VisaService::lines($model->requirements_bn)) === [] || VisaService::groups(VisaService::lines($model->requirements_en)) === [] ? __('cms.publish_requirements.requirements') : null,
        ]));
    }

    private function validated(Request $request, ?VisaService $visa = null): array
    {
        // The slug follows the country and visa type unless one is given; it is the page address, /visa/<slug>.
        $request->merge([
            'slug' => Str::slug((string) ($request->input('slug') ?: $request->input('country_en').' '.$request->input('visa_type_en'))),
            'country_code' => filled($request->input('country_code')) ? strtoupper(trim((string) $request->input('country_code'))) : null,
        ]);

        $data = $request->validate([
            'slug' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('visa_services', 'slug')->ignore($visa?->id)],
            'country_code' => ['nullable', 'string', 'size:2', 'regex:/^[A-Z]{2}$/'],
            'country_bn' => ['required', 'string', 'max:80'],
            'country_en' => ['required', 'string', 'max:80'],
            'visa_type_bn' => ['required', 'string', 'max:80'],
            'visa_type_en' => ['required', 'string', 'max:80'],
            'price' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'processing_bn' => ['nullable', 'string', 'max:120'],
            'processing_en' => ['nullable', 'string', 'max:120'],
            'stay_bn' => ['nullable', 'string', 'max:160'],
            'stay_en' => ['nullable', 'string', 'max:160'],
            'requirements_bn' => ['nullable', 'string', 'max:5000'],
            'requirements_en' => ['nullable', 'string', 'max:5000'],
            'notes_bn' => ['nullable', 'string', 'max:2000'],
            'notes_en' => ['nullable', 'string', 'max:2000'],
        ]);

        return array_map(fn ($value) => is_string($value) ? (trim($value) === '' ? null : trim($value)) : $value, $data);
    }
}
