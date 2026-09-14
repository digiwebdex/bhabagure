<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Admin\Concerns\ManagesPublishedList;
use App\Http\Controllers\Controller;
use App\Http\Resources\AdminContent;
use App\Models\TeamMember;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The About section's team cards. */
class TeamMemberController extends Controller
{
    use ManagesPublishedList;

    protected function modelClass(): string
    {
        return TeamMember::class;
    }

    protected function cacheTag(): string
    {
        return 'team';
    }

    protected function auditName(): string
    {
        return 'cms.team_member';
    }

    protected function present(Model $model): array
    {
        return AdminContent::teamMember($model->loadMissing('photo'));
    }

    public function index(): JsonResponse
    {
        $team = TeamMember::query()->with('photo')->orderBy('sort_order')->orderBy('id')->get();

        return response()->json(['data' => $team->map(AdminContent::teamMember(...))]);
    }

    public function store(Request $request): JsonResponse
    {
        $member = TeamMember::query()->create([
            ...$this->validated($request),
            'status' => 'draft',
            'sort_order' => (int) TeamMember::query()->max('sort_order') + 1,
        ]);

        return $this->saved($request, $member, 'created', 201);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(['data' => $this->present(TeamMember::query()->findOrFail($id))]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $member = TeamMember::query()->findOrFail($id);
        $member->update($this->validated($request, $member));

        return $this->saved($request, $member, 'updated');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $member = TeamMember::query()->findOrFail($id);
        $member->delete();

        return $this->saved($request, $member, 'deleted');
    }

    private function validated(Request $request, ?TeamMember $member = null): array
    {
        return $request->validate([
            'name_bn' => ['required', 'string', 'max:120'],
            'name_en' => ['required', 'string', 'max:120'],
            'role_bn' => ['required', 'string', 'max:120'],
            'role_en' => ['required', 'string', 'max:120'],
            'employee_code' => ['nullable', 'string', 'max:20', Rule::unique('team_members', 'employee_code')->ignore($member?->id)],
            'photo_media_id' => ['nullable', 'integer', 'exists:media,id'],
            'staff_id' => ['nullable', 'integer', 'exists:staff,id'],
        ]);
    }
}
