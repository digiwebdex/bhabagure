<?php

namespace App\Console\Commands;

use App\Enums\StaffRole;
use App\Enums\StaffStatus;
use App\Models\Staff;
use App\Services\Auth\RefreshTokens;
use App\Services\AuditLogger;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * The first sign-in on a fresh server, and the way back in if the only super admin is locked out. Run over SSH:
 * the temporary password is printed once, never stored in plain text, and must be replaced at the first sign-in.
 */
class CreateSuperAdminCommand extends Command
{
    protected $signature = 'staff:super-admin {email : Sign-in email} {--name= : Display name (new accounts)} {--reset : Give an existing account a new temporary password and sign it out everywhere}';

    protected $description = 'Create a super admin, or reset one, with a temporary password shown once';

    public function handle(RefreshTokens $refreshTokens, AuditLogger $audit): int
    {
        $email = Str::lower(trim((string) $this->argument('email')));
        if (Validator::make(['email' => $email], ['email' => ['required', 'email:rfc', 'max:190']])->fails()) {
            $this->error('That is not a valid email address.');

            return self::INVALID;
        }

        $existing = Staff::withTrashed()->where('email', $email)->first();
        if ($existing !== null && ! $this->option('reset')) {
            $this->error("{$email} already has an account. Pass --reset to give it a new temporary password.");

            return self::FAILURE;
        }
        if ($existing === null && $this->option('reset')) {
            $this->error("No account uses {$email}; nothing to reset.");

            return self::FAILURE;
        }
        if ($existing?->trashed()) {
            $this->error("{$email} belongs to a deleted account. Restore it from the admin before resetting it.");

            return self::FAILURE;
        }

        $this->callSilently('db:seed', ['--class' => RolesAndPermissionsSeeder::class, '--force' => true]);

        $password = Str::password(16, symbols: false);

        $staff = DB::transaction(function () use ($existing, $email, $password, $refreshTokens) {
            if ($existing !== null) {
                $existing->forceFill(['password' => $password, 'must_change_password' => true, 'status' => StaffStatus::Active])->save();
                $refreshTokens->revokeAllFor('staff', $existing->id);
                $existing->assignRole(StaffRole::SuperAdmin->value);

                return $existing;
            }

            $staff = Staff::query()->create([
                'employee_code' => self::nextEmployeeCode(),
                'name' => trim((string) $this->option('name')) ?: Str::headline(Str::before($email, '@')),
                'email' => $email,
                'password' => $password,
                'status' => StaffStatus::Active,
                'must_change_password' => true,
            ]);
            $staff->syncRoles([StaffRole::SuperAdmin->value]);

            return $staff;
        });

        $audit->record($existing !== null ? 'staff.super_admin.reset_from_console' : 'staff.super_admin.created_from_console', null, $staff);

        $this->info($existing !== null ? 'Password reset; every session of this account is signed out.' : 'Super admin created.');
        $this->table(['Email', 'Temporary password (shown once)'], [[$email, $password]]);
        $this->line('It must be changed at the first sign-in.');

        return self::SUCCESS;
    }

    private static function nextEmployeeCode(): string
    {
        $next = Staff::withTrashed()->where('employee_code', 'like', 'ADM-%')->count() + 1;
        while (Staff::withTrashed()->where('employee_code', $code = sprintf('ADM-%03d', $next))->exists()) {
            $next++;
        }

        return $code;
    }
}
