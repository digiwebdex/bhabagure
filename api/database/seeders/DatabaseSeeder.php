<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Roles, permissions and website content. Every seeder here is safe to re-run on a live database.
     *
     * Local-only extras, run explicitly:
     *   php artisan db:seed --class=DevStaffSeeder     one account per role, random passwords printed once
     *   php artisan db:seed --class=DemoContentSeeder  illustrative departures, reviews and gallery tiles
     */
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            ContentSeeder::class,
            NotificationTemplateSeeder::class,
        ]);
    }
}
