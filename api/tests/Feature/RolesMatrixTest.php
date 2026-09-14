<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * docs/phase-7-hr-attendance-bonus-wallet.md §4.1: the Roles screen is the super admin's. Custom roles carry exactly the
 * permissions ticked; system roles keep their names; the super admin role and role management can't be handed out;
 * and a revoke survives `permissions:sync --add-only` on the next deploy.
 */
class RolesMatrixTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function only_the_super_admin_edits_roles_and_a_custom_role_grants_exactly_what_is_ticked(): void
    {
        $owner = $this->staff('super_admin');
        $admin = $this->staff('admin');
        $this->actingAsApi($admin)->getJson('/api/v1/admin/roles')->assertForbidden();

        $roles = $this->actingAsApi($owner)->getJson('/api/v1/admin/roles')->assertOk();
        $superAdmin = collect($roles->json('data.roles'))->firstWhere('name', 'super_admin');
        $this->assertTrue($superAdmin['all_permissions']);
        $this->assertTrue(collect($roles->json('data.permissions'))->firstWhere('name', 'system.roles_manage')['reserved']);

        $this->actingAsApi($owner)->postJson('/api/v1/admin/roles', ['name_en' => 'Office assistant', 'name_bn' => 'অফিস সহকারী', 'permissions' => ['customers.view']])
            ->assertCreated();
        $role = Role::findByName('custom_office_assistant', 'staff');
        $this->actingAsApi($owner)->postJson('/api/v1/admin/roles', ['name_en' => 'office ASSISTANT', 'name_bn' => 'অন্য', 'permissions' => []])
            ->assertUnprocessable()->assertJsonPath('code', 'role_name_taken');

        // Given to someone from the staff screen, the role opens customers and nothing else.
        $assistant = $this->staff('sales_agent');
        $this->actingAsApi($admin)->putJson("/api/v1/admin/staff/{$assistant->id}/role", ['role' => 'custom_office_assistant'])->assertOk()
            ->assertJsonPath('data.role.name_en', 'Office assistant')->assertJsonPath('data.role.is_system', false);
        $this->actingAsApi($assistant)->getJson('/api/v1/admin/customers')->assertOk();
        $this->actingAsApi($assistant)->getJson('/api/v1/admin/bookings')->assertForbidden();

        $this->actingAsApi($owner)->putJson("/api/v1/admin/roles/{$role->id}/permissions", ['permission' => 'bookings.view_own', 'granted' => true])->assertOk();
        $this->actingAsApi($assistant)->getJson('/api/v1/admin/bookings')->assertOk();

        // What can't be done.
        $this->actingAsApi($owner)->putJson("/api/v1/admin/roles/{$role->id}/permissions", ['permission' => 'system.roles_manage', 'granted' => true])
            ->assertUnprocessable()->assertJsonPath('code', 'reserved_permission');
        $this->actingAsApi($owner)->putJson("/api/v1/admin/roles/{$role->id}/permissions", ['permission' => 'nope.nothing', 'granted' => true])
            ->assertUnprocessable()->assertJsonPath('code', 'unknown_permission');
        $superAdminRole = Role::findByName('super_admin', 'staff');
        $this->actingAsApi($owner)->putJson("/api/v1/admin/roles/{$superAdminRole->id}/permissions", ['permission' => 'cms.manage', 'granted' => true])
            ->assertConflict()->assertJsonPath('code', 'super_admin_role');
        $adminRole = Role::findByName('admin', 'staff');
        $this->actingAsApi($owner)->putJson("/api/v1/admin/roles/{$adminRole->id}", ['name_en' => 'Boss', 'name_bn' => 'বস'])->assertConflict()->assertJsonPath('code', 'system_role');
        $this->actingAsApi($owner)->deleteJson("/api/v1/admin/roles/{$adminRole->id}")->assertConflict()->assertJsonPath('code', 'system_role');
        $this->actingAsApi($owner)->deleteJson("/api/v1/admin/roles/{$role->id}")->assertConflict()->assertJsonPath('code', 'role_in_use');

        // Once nobody holds it, the custom role can go.
        $this->actingAsApi($admin)->putJson("/api/v1/admin/staff/{$assistant->id}/role", ['role' => 'sales_agent'])->assertOk();
        $this->actingAsApi($owner)->putJson("/api/v1/admin/roles/{$role->id}", ['name_en' => 'Front desk', 'name_bn' => 'ফ্রন্ট ডেস্ক'])->assertOk();
        $this->actingAsApi($owner)->deleteJson("/api/v1/admin/roles/{$role->id}")->assertOk();
        $this->assertNull(Role::query()->find($role->id));

        foreach (['role.created', 'role.permission_granted', 'role.renamed', 'role.deleted'] as $action) {
            $this->assertTrue(AuditLog::query()->where('action', $action)->where('actor_id', $owner->id)->exists(), $action);
        }
    }

    #[Test]
    public function a_permission_revoked_on_the_roles_screen_stays_revoked_after_permissions_sync(): void
    {
        $owner = $this->staff('super_admin');
        $admin = $this->staff('admin');
        $adminRole = Role::findByName('admin', 'staff');

        $this->actingAsApi($owner)->putJson("/api/v1/admin/roles/{$adminRole->id}/permissions", ['permission' => 'cms.manage', 'granted' => false])->assertOk();
        $this->assertTrue(AuditLog::query()->where('action', 'role.permission_revoked')->where('auditable_id', $adminRole->id)->where('changes->permission', 'cms.manage')->exists());
        $this->actingAsApi($admin)->getJson('/api/v1/admin/posts')->assertForbidden();

        $this->artisan('permissions:sync', ['--add-only' => true])->assertSuccessful();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertFalse($adminRole->fresh()->hasPermissionTo('cms.manage', 'staff'));
        $this->actingAsApi($admin)->getJson('/api/v1/admin/posts')->assertForbidden();
    }
}
