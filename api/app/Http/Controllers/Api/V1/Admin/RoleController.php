<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\StaffRole;
use App\Enums\StaffStatus;
use App\Http\Controllers\Api\V1\Admin\Concerns\AnswersHrRefusals;
use App\Http\Controllers\Controller;
use App\Models\Staff;
use App\Services\Hr\HrRefused;
use App\Services\Hr\RoleMatrix;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * System → Roles & audit (docs/phase-7-hr-attendance-bonus-wallet.md §4.1): roles with their users, custom roles, and the
 * permission matrix. The route needs `system.roles_manage`, which no role can be given, so in practice it is the super
 * admin's screen. The rules are RoleMatrix's.
 */
class RoleController extends Controller
{
    use AnswersHrRefusals;

    public function index(): JsonResponse
    {
        return response()->json(['data' => [
            'roles' => $this->roles(),
            'permissions' => Permission::query()->where('guard_name', 'staff')->orderBy('id')->get()
                ->map(fn (Permission $permission) => [
                    'name' => $permission->name,
                    'module' => $permission->module,
                    'name_en' => $permission->name_en,
                    'name_bn' => $permission->name_bn,
                    'reserved' => in_array($permission->name, RoleMatrix::RESERVED, true),
                ])->all(),
        ]]);
    }

    public function store(Request $request, RoleMatrix $matrix): JsonResponse
    {
        $data = $request->validate([
            'name_en' => ['required', 'string', 'max:120'],
            'name_bn' => ['required', 'string', 'max:120'],
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', 'max:100'],
        ]);

        try {
            $matrix->create(trim($data['name_en']), trim($data['name_bn']), $data['permissions'], $request->user('staff'));
        } catch (HrRefused $e) {
            return $this->hrRefused($e);
        }

        return response()->json(['data' => ['roles' => $this->roles()]], 201);
    }

    public function update(Request $request, int $id, RoleMatrix $matrix): JsonResponse
    {
        $role = $this->find($id);
        $data = $request->validate([
            'name_en' => ['required', 'string', 'max:120'],
            'name_bn' => ['required', 'string', 'max:120'],
        ]);

        try {
            $matrix->rename($role, trim($data['name_en']), trim($data['name_bn']), $request->user('staff'));
        } catch (HrRefused $e) {
            return $this->hrRefused($e);
        }

        return response()->json(['data' => ['roles' => $this->roles()]]);
    }

    public function setPermission(Request $request, int $id, RoleMatrix $matrix): JsonResponse
    {
        $role = $this->find($id);
        $data = $request->validate([
            'permission' => ['required', 'string', 'max:100'],
            'granted' => ['required', 'boolean'],
        ]);

        try {
            $matrix->setPermission($role, $data['permission'], (bool) $data['granted'], $request->user('staff'));
        } catch (HrRefused $e) {
            return $this->hrRefused($e);
        }

        return response()->json(['data' => ['roles' => $this->roles()]]);
    }

    public function destroy(Request $request, int $id, RoleMatrix $matrix): JsonResponse
    {
        try {
            $matrix->delete($this->find($id), $request->user('staff'));
        } catch (HrRefused $e) {
            return $this->hrRefused($e);
        }

        return response()->json(['data' => ['roles' => $this->roles()]]);
    }

    /** @return list<array<string, mixed>> */
    private function roles(): array
    {
        // People who can use the role now: not suspended, not deleted.
        $users = DB::table('model_has_roles')
            ->join('staff', 'staff.id', '=', 'model_has_roles.model_id')
            ->where('model_has_roles.model_type', (new Staff)->getMorphClass())
            ->whereNull('staff.deleted_at')->where('staff.status', '!=', StaffStatus::Suspended->value)
            ->selectRaw('model_has_roles.role_id, count(*) as holders')->groupBy('model_has_roles.role_id')
            ->pluck('holders', 'role_id');

        return Role::query()->where('guard_name', 'staff')->with('permissions')->orderByDesc('is_system')->orderBy('id')->get()
            ->map(fn (Role $role) => [
                'id' => $role->id,
                'name' => $role->name,
                'name_en' => (string) $role->name_en,
                'name_bn' => (string) $role->name_bn,
                'is_system' => (bool) $role->is_system,
                // Super admin passes every check and isn't edited in the matrix.
                'all_permissions' => $role->name === StaffRole::SuperAdmin->value,
                'users' => (int) ($users[$role->id] ?? 0),
                'permissions' => $role->permissions->pluck('name')->sort()->values()->all(),
            ])->all();
    }

    private function find(int $id): Role
    {
        return Role::query()->where('guard_name', 'staff')->findOrFail($id);
    }
}
