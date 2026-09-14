<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\RefreshToken;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SuperAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_first_super_admin_signs_in_with_the_password_shown_once_and_must_change_it(): void
    {
        $this->assertSame(0, Artisan::call('staff:super-admin', ['email' => 'Owner@Bhabaghure.com.bd', '--name' => 'Owner']));
        $password = self::temporaryPassword(Artisan::output());

        $staff = Staff::query()->where('email', 'owner@bhabaghure.com.bd')->firstOrFail();
        $this->assertTrue($staff->isSuperAdmin());
        $this->assertTrue($staff->must_change_password);
        $this->assertSame('ADM-001', $staff->employee_code);
        $this->assertNotSame($password, $staff->getRawOriginal('password'));
        $this->assertTrue(AuditLog::query()->where('action', 'staff.super_admin.created_from_console')->where('auditable_id', $staff->id)->exists());

        $this->postJson('/api/v1/staff/auth/login', ['email' => 'owner@bhabaghure.com.bd', 'password' => $password])
            ->assertOk()
            ->assertJsonPath('staff.role', 'super_admin')
            ->assertJsonPath('staff.must_change_password', true);
    }

    #[Test]
    public function an_existing_account_is_only_changed_with_reset_which_signs_it_out_everywhere(): void
    {
        $staff = $this->staff('admin', ['email' => 'lead@bhabaghure.com.bd']);
        $this->postJson('/api/v1/staff/auth/login', ['email' => $staff->email, 'password' => 'correct-horse-battery'])->assertOk();
        $this->assertSame(1, RefreshToken::query()->where('subject_id', $staff->id)->whereNull('revoked_at')->count());

        $this->assertSame(1, Artisan::call('staff:super-admin', ['email' => $staff->email]));
        $this->postJson('/api/v1/staff/auth/login', ['email' => $staff->email, 'password' => 'correct-horse-battery'])->assertOk();

        $this->assertSame(0, Artisan::call('staff:super-admin', ['email' => $staff->email, '--reset' => true]));
        $password = self::temporaryPassword(Artisan::output());

        $this->assertSame(0, RefreshToken::query()->where('subject_id', $staff->id)->whereNull('revoked_at')->count());
        $this->assertTrue($staff->fresh()->isSuperAdmin());
        $this->postJson('/api/v1/staff/auth/login', ['email' => $staff->email, 'password' => 'correct-horse-battery'])->assertUnauthorized();
        $this->postJson('/api/v1/staff/auth/login', ['email' => $staff->email, 'password' => $password])->assertOk();

        $this->assertSame(1, Artisan::call('staff:super-admin', ['email' => 'nobody@bhabaghure.com.bd', '--reset' => true]));
        $this->assertFalse(Staff::query()->where('email', 'nobody@bhabaghure.com.bd')->exists());
    }

    private static function temporaryPassword(string $output): string
    {
        preg_match('/\|\s*\S+@\S+\s*\|\s*([A-Za-z0-9]{16})\s*\|/', $output, $match);

        return $match[1] ?? self::fail("No temporary password in the output:\n{$output}");
    }
}
