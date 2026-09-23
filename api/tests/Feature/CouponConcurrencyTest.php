<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Customer;
use App\Support\Database\LedgerQueryGuard;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * docs/coupons.md §2.4: two customers trying for a coupon's last use at the same moment — one gets it. Two real PHP
 * processes with their own database connections race (tests/Fixtures/coupon-race.php); the first holds the coupon's
 * lock while the second tries.
 *
 * Deliberately without RefreshDatabase: the other processes must see these rows, so they are committed — and deleted
 * again in tearDown, audit rows included, leaving the database as every other test expects it.
 */
class CouponConcurrencyTest extends TestCase
{
    /** @var array{customers: list<int>, bookings: list<int>, coupons: list<int>} */
    private array $created = ['customers' => [], 'bookings' => [], 'coupons' => []];

    protected function setUp(): void
    {
        parent::setUp();
        // What RefreshDatabase does first in a process, without its rollback transaction.
        if (! RefreshDatabaseState::$migrated) {
            $this->artisan('migrate:fresh', ['--drop-views' => true]);
            $this->app[Kernel::class]->setArtisan(null);
            RefreshDatabaseState::$migrated = true;
        }
    }

    protected function tearDown(): void
    {
        LedgerQueryGuard::withoutGuard(function () {
            DB::table('audit_logs')->where('auditable_type', 'booking')->whereIn('auditable_id', $this->created['bookings'])->delete();
            CouponRedemption::query()->whereIn('booking_id', $this->created['bookings'])->delete();
            Booking::withTrashed()->whereIn('id', $this->created['bookings'])->forceDelete();
            Coupon::withTrashed()->whereIn('id', $this->created['coupons'])->forceDelete();
            Customer::withTrashed()->whereIn('id', $this->created['customers'])->forceDelete();
        });

        parent::tearDown();
    }

    #[Test]
    public function two_bookings_racing_for_the_last_use_get_one_between_them(): void
    {
        $customer = Customer::query()->create(['name' => 'Race Customer', 'phone' => '8801799000901', 'stage' => 'lead', 'source' => 'website_form']);
        $this->created['customers'][] = $customer->id;
        $coupon = Coupon::query()->create([
            'code' => 'LAST-'.Str::upper(Str::random(6)), 'name' => 'Last one', 'kind' => Coupon::PUBLIC, 'discount_type' => Coupon::PERCENT,
            'discount_value' => 10, 'usage_limit' => 1, 'applies_to' => Coupon::APPLIES_ALL, 'is_active' => true,
        ]);
        $this->created['coupons'][] = $coupon->id;
        [$first, $second] = [$this->openBooking($customer), $this->openBooking($customer)];

        $ready = sys_get_temp_dir().DIRECTORY_SEPARATOR.'coupon-race-'.Str::random(12);
        $holder = $this->racer($coupon->code, $first, holdMs: 4000, ready: $ready);
        $holder->start();
        try {
            // The first has checked the uses and holds the coupon's lock: now the second tries.
            $deadline = microtime(true) + 30;
            while (! (is_file($ready) && file_get_contents($ready) === 'locked')) {
                $this->assertTrue($holder->isRunning() && microtime(true) < $deadline, 'The first process never took the lock: '.$holder->getErrorOutput().$holder->getOutput());
                usleep(50_000);
            }
            $rival = $this->racer($coupon->code, $second, holdMs: 0, ready: '-');
            $rival->run();
            $holder->wait();
        } finally {
            @unlink($ready);
        }

        $won = json_decode($holder->getOutput(), true) ?? $this->fail('First process: '.$holder->getErrorOutput().$holder->getOutput());
        $lost = json_decode($rival->getOutput(), true) ?? $this->fail('Second process: '.$rival->getErrorOutput().$rival->getOutput());
        $this->assertSame('reserved', $won['result']);
        $this->assertSame('used_up', $lost['result']);
        // The second really tried while the first was still inside its transaction, and waited for it.
        $this->assertLessThan($won['done'], $lost['checking']);
        $this->assertGreaterThan($won['done'], $lost['done']);
        $this->assertSame([$first->id], CouponRedemption::query()->where('coupon_id', $coupon->id)->live()->pluck('booking_id')->all());
    }

    private function racer(string $code, Booking $booking, int $holdMs, string $ready): Process
    {
        return new Process([PHP_BINARY, base_path('tests/Fixtures/coupon-race.php'), $code, (string) $booking->id, (string) $holdMs, $ready], base_path(), [
            // This test's database — *_testing, checked by TestCase — and nothing that sends anything.
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => config('database.default'),
            'DB_DATABASE' => config('database.connections.'.config('database.default').'.database'),
            'DB_URL' => '',
            'CACHE_STORE' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'MAIL_MAILER' => 'array',
            'WASENDER_MODE' => 'off',
            'NOTIFICATIONS_EMAIL' => 'false',
        ], timeout: 60);
    }

    /** An open website booking for two, without a coupon yet. */
    private function openBooking(Customer $customer): Booking
    {
        $booking = Booking::query()->create([
            'reference' => 'BH-RACE-'.Str::upper(Str::random(6)), 'customer_id' => $customer->id, 'package_title_en' => 'Mustang Valley Adventure',
            'pax_count' => 2, 'list_price' => 75000, 'unit_price' => 75000, 'subtotal_amount' => 150000, 'vat_rate' => 2, 'vat_amount' => 3000,
            'total_amount' => 153000, 'source' => 'website_form',
        ]);
        $this->created['bookings'][] = $booking->id;

        return $booking;
    }
}
