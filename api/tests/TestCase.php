<?php

namespace Tests;

use App\Enums\StaffStatus;
use App\Models\Customer;
use App\Models\Staff;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use PHPOpenSourceSaver\JWTAuth\JWT;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // RefreshDatabase drops every table. Refuse to run against anything but a *_testing database,
        // so a stray .env can never wipe the dev database (or, worse, the shared server's).
        $database = (string) config('database.connections.'.config('database.default').'.database');
        if (! str_ends_with($database, '_testing')) {
            throw new RuntimeException("Tests must use a *_testing database; got [{$database}]. Check phpunit.xml.");
        }
    }

    protected function staff(?string $role = 'admin', array $attributes = []): Staff
    {
        static $sequence = 0;
        $sequence++;

        if ($role !== null) {
            $this->seed(RolesAndPermissionsSeeder::class);
        }

        $staff = Staff::query()->create([
            'employee_code' => sprintf('T-%04d', $sequence),
            'name' => "Test staff {$sequence}",
            'email' => "staff{$sequence}@example.test",
            'password' => 'correct-horse-battery',
            'status' => StaffStatus::Active,
            'must_change_password' => false,
            ...$attributes,
        ]);

        if ($role !== null) {
            $staff->assignRole($role);
        }

        return $staff;
    }

    protected function customer(array $attributes = []): Customer
    {
        return Customer::query()->create([
            'name' => 'Test Customer',
            'phone' => '8801711000001',
            'email' => 'customer@example.test',
            'password' => 'secret123',
            'stage' => 'customer',
            'source' => 'website_form',
            ...$attributes,
        ]);
    }

    /** Sends the next requests with a bearer token for this staff member or customer. */
    protected function actingAsApi(Staff|Customer $subject): static
    {
        $this->resetAuthState();

        return $this->withToken($this->app->make(JWT::class)->fromSubject($subject));
    }

    /**
     * The test app lives across requests, but jwt-auth caches the parsed token on a scoped container
     * instance and guards cache their user. Production handles each request in a fresh process; tests
     * reset both so one request's token can't leak into the next.
     */
    protected function resetAuthState(): void
    {
        $this->app->forgetScopedInstances();
        $this->app['auth']->forgetGuards();
    }
}
