<?php

namespace Database\Seeders;

use App\Enums\StaffRole;
use App\Enums\StaffStatus;
use App\Models\Staff;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * One staff account per role for local work. Passwords are random and printed once; nothing is committed.
 * Refuses to run outside APP_ENV=local.
 */
class DevStaffSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('DevStaffSeeder only runs with APP_ENV=local.');
        }

        $this->call(RolesAndPermissionsSeeder::class);

        $rows = [];
        foreach (StaffRole::cases() as $index => $role) {
            $email = str_replace('_', '.', $role->value).'@bhabaghure.local';
            $password = Str::password(16, symbols: false);

            $staff = Staff::query()->updateOrCreate(['email' => $email], [
                'employee_code' => sprintf('DEV-%03d', $index + 1),
                'name' => Str::headline($role->value).' (dev)',
                'password' => $password,
                'status' => StaffStatus::Active,
                'must_change_password' => false,
            ]);
            $staff->syncRoles([$role->value]);
            $rows[] = [$role->value, $email, $password];
        }

        $this->command?->table(['Role', 'Email', 'Password (shown once)'], $rows);
    }
}
