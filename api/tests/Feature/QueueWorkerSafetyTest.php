<?php

namespace Tests\Feature;

use App\Mail\AdminAlertMail;
use App\Models\NotificationMessage;
use App\Support\Queue\QueuePreflight;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** docs/deployment.md §5: the queue worker on the shared server — its own Redis databases, and a stall is noticed. */
class QueueWorkerSafetyTest extends TestCase
{
    use RefreshDatabase;

    private function serverConfig(array $overrides = []): void
    {
        config(array_merge([
            'queue.default' => 'redis',
            'database.redis.client' => 'predis',
            'queue.connections.redis.connection' => 'default',
            'database.redis.default.database' => '12',
            'database.redis.cache.database' => '13',
            'database.redis.options.prefix' => 'bhabaghure_',
            'queue.connections.redis.retry_after' => 180,
        ], $overrides));
    }

    #[Test]
    public function the_reserved_redis_databases_prefix_and_retry_window_pass(): void
    {
        $this->serverConfig();

        $this->assertSame([], QueuePreflight::configProblems(12, 13, 120));
    }

    #[Test]
    public function the_preflight_refuses_another_projects_index_a_shared_index_a_missing_prefix_and_double_handout(): void
    {
        $this->serverConfig([
            'database.redis.default.database' => '0',
            'database.redis.options.prefix' => 'laravel-database-',
            'queue.connections.redis.retry_after' => 90,
        ]);
        $problems = implode("\n", QueuePreflight::configProblems(12, 13, 120));

        $this->assertStringContainsString('REDIS_DB (queue) is Redis database 0; this server reserves 12', $problems);
        $this->assertStringContainsString("REDIS_PREFIX is 'laravel-database-'", $problems);
        $this->assertStringContainsString('REDIS_QUEUE_RETRY_AFTER (90s) must be longer', $problems);
        $this->assertStringContainsString('different Redis databases', implode("\n", QueuePreflight::configProblems(12, 12, 120)));

        config(['queue.default' => 'sync']);
        $this->assertStringContainsString("QUEUE_CONNECTION is 'sync'", implode("\n", QueuePreflight::configProblems(12, 13, 120)));

        // The unit stops on exit code 1 — before any connection to Redis is attempted.
        $this->artisan('bhabaghure:queue-preflight', ['--redis-db' => 12, '--redis-cache-db' => 13, '--job-timeout' => 120])->assertExitCode(1);
        $this->artisan('bhabaghure:queue-preflight', ['--redis-db' => 12])->assertExitCode(2);
    }

    #[Test]
    public function messages_left_unclaimed_for_fifteen_minutes_alert_the_notification_managers_once(): void
    {
        config(['queue.default' => 'redis']);
        Queue::fake();
        Mail::fake();
        $admin = $this->staff('admin');
        $customer = $this->customer();
        $row = fn (int $minutesAgo) => NotificationMessage::query()->create([
            'event' => 'staff_message', 'channel' => 'whatsapp', 'to_address' => $customer->phone, 'recipient_type' => 'customer',
            'recipient_id' => $customer->id, 'locale' => 'bn', 'body' => 'x', 'status' => 'pending', 'provider' => 'whatsapp',
            'scheduled_for' => now()->subMinutes($minutesAgo), 'dedupe_key' => "stall-test:{$minutesAgo}",
        ]);

        $row(3);
        $this->artisan('notifications:dispatch')->assertSuccessful();
        Mail::assertNothingSent();

        $row(20);
        $this->artisan('notifications:dispatch')->assertSuccessful();
        $this->artisan('notifications:dispatch')->assertSuccessful();

        Mail::assertSent(AdminAlertMail::class, 1);
        Mail::assertSent(AdminAlertMail::class, fn (AdminAlertMail $mail) => $mail->hasTo($admin->email) && str_contains($mail->alert, 'queue worker'));
    }
}
