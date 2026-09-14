<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Services\AuditLogger;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Brings permissions that ship with new code to a live database (docs/phase-5-admin-core.md §7). On a live database
 * the seeder leaves existing roles alone, because their permissions belong to the Roles screen; this grants each system
 * role the matrix permissions it lacks — never removing one, and never re-granting one an admin revoked from that role
 * (recorded as `role.permission_revoked` in the audit log). Every grant is audited. deploy.sh runs it after seeding.
 */
class SyncPermissionsCommand extends Command
{
    private const GUARD = 'staff';

    protected $signature = 'permissions:sync {--add-only : Grant missing matrix permissions; never remove any (the only mode)}';

    protected $description = 'Grant new matrix permissions to the system roles without taking any away';

    public function handle(AuditLogger $audit): int
    {
        if (! $this->option('add-only')) {
            $this->error('Only --add-only exists: permissions are never removed from the command line.');

            return self::INVALID;
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $granted = [];

        DB::transaction(function () use ($audit, &$granted) {
            foreach (RolesAndPermissionsSeeder::PERMISSIONS as $name => [$module, $bn, $en]) {
                Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => self::GUARD], ['module' => $module, 'name_bn' => $bn, 'name_en' => $en]);
            }

            foreach (RolesAndPermissionsSeeder::ROLES as $roleName => [, , $matrix]) {
                $role = Role::query()->where('name', $roleName)->where('guard_name', self::GUARD)->first();
                if (! $role) {
                    continue; // the seeder creates system roles; a missing one isn't this command's to invent
                }
                $has = $role->permissions()->pluck('name')->all();
                foreach (array_diff($matrix, $has) as $permission) {
                    if ($this->revokedByAdmin($role, $permission)) {
                        continue;
                    }
                    $role->givePermissionTo($permission);
                    $audit->record('role.permission_granted', null, $role, ['permission' => $permission, 'by' => 'permissions:sync']);
                    $granted[] = [$roleName, $permission];
                }
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        if ($granted === []) {
            $this->info('Every system role already has its matrix permissions.');
        } else {
            $this->table(['Role', 'Granted'], $granted);
        }

        return self::SUCCESS;
    }

    private function revokedByAdmin(Role $role, string $permission): bool
    {
        return AuditLog::query()->where('action', 'role.permission_revoked')
            ->where('auditable_type', $role->getMorphClass())->where('auditable_id', $role->id)
            ->where('changes->permission', $permission)->exists();
    }
}
