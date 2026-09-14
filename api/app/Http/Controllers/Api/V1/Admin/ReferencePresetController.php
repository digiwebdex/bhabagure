<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\ReferencePreset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/** Saved references for manual cash entries (§4.6): click to fill, add and remove. Changing them needs transactions.create_manual. */
class ReferencePresetController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => ReferencePreset::query()->orderBy('sort_order')->orderBy('label')->get(['id', 'label', 'direction'])]);
    }

    public function store(Request $request): JsonResponse
    {
        $staff = $request->user('staff');
        abort_unless($staff->can('transactions.create_manual'), 403, __('auth.forbidden'));
        $data = $request->validate([
            'label' => ['required', 'string', 'min:2', 'max:120', Rule::unique('reference_presets', 'label')],
            'direction' => ['nullable', Rule::in(['in', 'out'])],
        ]);
        $preset = ReferencePreset::query()->create(['label' => trim($data['label']), 'direction' => $data['direction'] ?? null, 'created_by_staff_id' => $staff->id]);

        return response()->json(['data' => $preset->only(['id', 'label', 'direction'])], Response::HTTP_CREATED);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        abort_unless($request->user('staff')->can('transactions.create_manual'), 403, __('auth.forbidden'));
        ReferencePreset::query()->findOrFail($id)->delete();

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }
}
