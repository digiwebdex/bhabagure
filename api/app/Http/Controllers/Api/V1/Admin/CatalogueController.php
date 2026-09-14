<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AdminContent;
use App\Jobs\RevalidateWebsite;
use App\Models\Destination;
use App\Models\Tag;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Destinations (the region chips and search select) and the tag list for the package editor. */
class CatalogueController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function destinations(): JsonResponse
    {
        $destinations = Destination::query()->withCount('packages')->orderBy('sort_order')->orderBy('id')->get();

        return response()->json(['data' => $destinations->map(fn (Destination $destination) => [
            ...AdminContent::destination($destination),
            'packages_count' => $destination->packages_count,
        ])]);
    }

    public function storeDestination(Request $request): JsonResponse
    {
        $destination = Destination::query()->create([
            ...$this->validatedDestination($request),
            'sort_order' => (int) Destination::query()->max('sort_order') + 1,
        ]);

        return $this->destinationSaved($request, $destination, 'created', 201);
    }

    public function updateDestination(Request $request, int $id): JsonResponse
    {
        $destination = Destination::query()->findOrFail($id);
        $destination->update($this->validatedDestination($request, $destination));

        return $this->destinationSaved($request, $destination, 'updated');
    }

    public function tags(): JsonResponse
    {
        $tags = Tag::query()->orderBy('type')->orderBy('name_en')->get(['id', 'type', 'slug', 'name_en', 'name_bn']);

        return response()->json(['data' => $tags]);
    }

    private function validatedDestination(Request $request, ?Destination $destination = null): array
    {
        return $request->validate([
            'slug' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('destinations', 'slug')->ignore($destination?->id)],
            'name_bn' => ['required', 'string', 'max:80'],
            'name_en' => ['required', 'string', 'max:80'],
            'country_code' => ['nullable', 'string', 'size:2', 'alpha'],
            'region' => ['required', Rule::in(['international', 'domestic'])],
            'visa_on_arrival' => ['sometimes', 'boolean'],
        ]);
    }

    private function destinationSaved(Request $request, Destination $destination, string $action, int $status = 200): JsonResponse
    {
        $this->audit->record("cms.destination.{$action}", $request->user('staff'), $destination);
        RevalidateWebsite::dispatch(['packages']);

        return response()->json(['data' => AdminContent::destination($destination)], $status);
    }
}
