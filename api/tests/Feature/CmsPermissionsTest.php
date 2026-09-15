<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** packages.manage: Admin, Tour operator. pricing.manage: Admin, Tour operator. cms.manage: Admin. Super admin: everything. */
class CmsPermissionsTest extends TestCase
{
    use RefreshDatabase;

    public static function matrix(): array
    {
        //                         role              packages  pricing  posts  team  reviews  gallery  visas  settings  media
        return [
            'super admin' => ['super_admin',   [200, 200, 200, 200, 200, 200, 200, 200, 200]],
            'admin' => ['admin',               [200, 200, 200, 200, 200, 200, 200, 200, 200]],
            'tour operator' => ['tour_operator', [200, 200, 403, 403, 403, 403, 403, 403, 200]],
            'sales agent' => ['sales_agent',   [403, 403, 403, 403, 403, 403, 403, 403, 403]],
            'accountant' => ['accountant',     [403, 403, 403, 403, 403, 403, 403, 403, 403]],
        ];
    }

    #[Test]
    #[DataProvider('matrix')]
    public function each_role_reaches_exactly_the_cms_screens_it_should(string $role, array $expected): void
    {
        $staff = $this->staff($role);
        $paths = ['packages', 'pricing', 'posts', 'team', 'reviews', 'gallery', 'visas', 'settings', 'media'];

        $actual = array_map(fn (string $path) => $this->actingAsApi($staff)->getJson("/api/v1/admin/{$path}")->status(), $paths);

        $this->assertSame(array_combine($paths, $expected), array_combine($paths, $actual));
    }

    #[Test]
    public function anonymous_requests_are_rejected_with_json(): void
    {
        $this->getJson('/api/v1/admin/packages')->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
    }

    #[Test]
    public function super_admin_needs_no_permission_rows_so_a_matrix_edit_cannot_lock_the_proprietor_out(): void
    {
        $owner = $this->staff('super_admin');
        $this->assertCount(0, $owner->getAllPermissions());

        $this->actingAsApi($owner)->getJson('/api/v1/admin/posts')->assertOk();
    }
}
