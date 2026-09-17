<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Admin\Concerns\ManagesPublishedList;
use App\Http\Controllers\Controller;
use App\Http\Resources\AdminContent;
use App\Models\AirlinePartner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The airlines the agency books, each with the logo it is known by (docs/partners-and-payments.md). */
class AirlinePartnerController extends Controller
{
    use ManagesPublishedList;

    protected function modelClass(): string
    {
        return AirlinePartner::class;
    }

    protected function cacheTag(): string
    {
        return 'partners';
    }

    protected function auditName(): string
    {
        return 'cms.airline_partner';
    }

    protected function present(Model $model): array
    {
        return AdminContent::airlinePartner($model->loadMissing('logo'));
    }

    public function index(): JsonResponse
    {
        return response()->json(['data' => AirlinePartner::query()->with('logo')->orderBy('sort_order')->orderBy('id')->get()->map(AdminContent::airlinePartner(...))]);
    }

    public function store(Request $request): JsonResponse
    {
        $partner = AirlinePartner::query()->create([
            ...$this->validated($request),
            'status' => 'draft',
            'sort_order' => (int) AirlinePartner::query()->max('sort_order') + 1,
        ]);

        return $this->saved($request, $partner, 'created', 201);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(['data' => $this->present(AirlinePartner::query()->findOrFail($id))]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $partner = AirlinePartner::query()->findOrFail($id);
        $partner->update($this->validated($request));

        return $this->saved($request, $partner, 'updated');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $partner = AirlinePartner::query()->findOrFail($id);
        $partner->delete();

        return $this->saved($request, $partner, 'deleted');
    }

    /** A partner is its logo: without one there is nothing to show in the band. */
    protected function publishProblems(Model $model): array
    {
        return $model->media_id === null ? [__('cms.publish_requirements.logo')] : [];
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name_bn' => ['required', 'string', 'max:120'],
            'name_en' => ['required', 'string', 'max:120'],
            'media_id' => ['nullable', 'integer', 'exists:media,id'],
            'website_url' => ['nullable', 'url:https', 'max:255'],
        ]);
    }
}
