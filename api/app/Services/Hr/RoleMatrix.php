<?php

namespace App\Services\Hr;

use App\Enums\StaffRole;
use App\Models\Staff;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The Roles screen (docs/phase-7-hr-attendance-bonus-wallet.md §4.1): custom roles and the permission matrix, for the
 * super admin only. The super admin role holds no permissions (Gate::before passes it) and isn't editable; system roles
 * keep their names. A revoke is recorded as `role.permission_revoked`, which `permissions:sync --add-only` respects, so
 * a deploy never hands a revoked permission back.
 */
final class RoleMatrix
{
    private const GUARD = 'staff';

    /** Never granted from the matrix: a role that manages roles could grant itself anything. The super admin needs neither. */
    public const RESERVED = ['system.roles_manage'];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  list<string>  $permissions
     *
     * @throws HrRefused
     */
    public function create(string $nameEn, string $nameBn, array $permissions, Staff $by): Role
    {
        $this->ensureNameFree($nameEn, null);

        $role = DB::transaction(function () use ($nameEn, $nameBn, $permissions, $by) {
            $role = Role::query()->create(['name' => self::slugFor($nameEn), 'guard_name' => self::GUARD, 'name_en' => $nameEn, 'name_bn' => $nameBn, 'is_system' => false]);
            $this->audit->record('role.created', $by, $role, ['name_en' => $nameEn]);
            foreach (array_values(array_unique($permissions)) as $permission) {
                $this->grant($role, $permission, $by);
            }

            return $role;
        });
        $this->forgetCache();

        return $role;
    }

    /** @throws HrRefused */
    public function rename(Role $role, string $nameEn, string $nameBn, Staff $by): Role
    {
        if ($role->is_system) {
            throw new HrRefused('system_role');
        }
        $this->ensureNameFree($nameEn, $role);
        $role->forceFill(['name_en' => $nameEn, 'name_bn' => $nameBn])->save();
        $this->audit->record('role.renamed', $by, $role, ['name_en' => $nameEn]);

        return $role;
    }

    /** @throws HrRefused */
    public function setPermission(Role $role, string $permission, bool $granted, Staff $by): void
    {
        if ($role->name === StaffRole::SuperAdmin->value) {
            throw new HrRefused('super_admin_role');
        }

        DB::transaction(fn () => $granted ? $this->grant($role, $permission, $by) : $this->revoke($role, $permission, $by));
        $this->forgetCache();
    }

    /** Only a custom role nobody holds. @throws HrRefused */
    public function delete(Role $role, Staff $by): void
    {
        if ($role->is_system) {
            throw new HrRefused('system_role');
        }
        if (Staff::withTrashed()->role($role->name, self::GUARD)->exists()) {
            throw new HrRefused('role_in_use');
        }

        DB::transaction(function () use ($role, $by) {
            $this->audit->record('role.deleted', $by, $role, ['name' => $role->name, 'name_en' => $role->name_en]);
            $role->delete();
        });
        $this->forgetCache();
    }

    /** @throws HrRefused */
    private function grant(Role $role, string $permission, Staff $by): void
    {
        if (in_array($permission, self::RESERVED, true)) {
            throw new HrRefused('reserved_permission');
        }
        $this->ensureKnown($permission);
        if ($role->hasPermissionTo($permission, self::GUARD)) {
            return;
        }
        $role->givePermissionTo($permission);
        $this->audit->record('role.permission_granted', $by, $role, ['permission' => $permission, 'by' => 'roles_screen']);
    }

    /** @throws HrRefused */
    private function revoke(Role $role, string $permission, Staff $by): void
    {
        $this->ensureKnown($permission);
        if (! $role->hasPermissionTo($permission, self::GUARD)) {
            return;
        }
        $role->revokePermissionTo($permission);
        $this->audit->record('role.permission_revoked', $by, $role, ['permission' => $permission]);
    }

    /** @throws HrRefused */
    private function ensureKnown(string $permission): void
    {
        if (! Permission::query()->where('name', $permission)->where('guard_name', self::GUARD)->exists()) {
            throw new HrRefused('unknown_permission');
        }
    }

    /** @throws HrRefused */
    private function ensureNameFree(string $nameEn, ?Role $except): void
    {
        $taken = Role::query()->where('guard_name', self::GUARD)->whereRaw('LOWER(name_en) = ?', [Str::lower(trim($nameEn))])
            ->when($except, fn ($query) => $query->whereKeyNot($except->id))->exists();
        if ($taken) {
            throw new HrRefused('role_name_taken');
        }
    }

    private static function slugFor(string $nameEn): string
    {
        $base = Str::limit('custom_'.(Str::slug($nameEn, '_') ?: 'role'), 90, '');
        $slug = $base;
        for ($n = 2; Role::query()->where('name', $slug)->where('guard_name', self::GUARD)->exists(); $n++) {
            $slug = "{$base}_{$n}";
        }

        return $slug;
    }

    private function forgetCache(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
