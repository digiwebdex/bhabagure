<?php

namespace App\Support\Queue;

use Predis\Client;
use Throwable;

/**
 * Checks, before the queue worker starts, that it will run on this project's own Redis databases (docs/deployment.md §5).
 * The server is shared: a wrong .env must stop the worker, not let it read or write another site's queue or cache.
 * Run by bhabaghure-queue.service (`php artisan bhabaghure:queue-preflight`) before every start.
 */
final class QueuePreflight
{
    /** Every key this project writes to Redis starts with the connection prefix (REDIS_PREFIX). */
    public const PREFIX = 'bhabaghure_';

    /** @return list<string> problems with the configuration alone */
    public static function configProblems(int $redisDb, int $cacheDb, int $jobTimeout): array
    {
        $problems = [];

        if (config('queue.default') !== 'redis') {
            $problems[] = sprintf("QUEUE_CONNECTION is '%s'; the worker expects 'redis'.", config('queue.default'));
        }
        if (config('database.redis.client') === 'phpredis' && ! extension_loaded('redis')) {
            $problems[] = "REDIS_CLIENT is 'phpredis' but PHP's redis extension isn't installed; set REDIS_CLIENT=predis.";
        }
        if ($redisDb === $cacheDb) {
            $problems[] = "The queue and the cache must use different Redis databases (both {$redisDb}).";
        }

        $queueConnection = (string) config('queue.connections.redis.connection', 'default');
        $databases = [
            'REDIS_DB (queue)' => [(int) config("database.redis.{$queueConnection}.database"), $redisDb],
            'REDIS_CACHE_DB (cache)' => [(int) config('database.redis.cache.database'), $cacheDb],
        ];
        foreach ($databases as $name => [$configured, $expected]) {
            if ($configured !== $expected) {
                $problems[] = "{$name} is Redis database {$configured}; this server reserves {$expected} for Bhabaghure.";
            }
        }

        $prefix = (string) config('database.redis.options.prefix');
        if ($prefix !== self::PREFIX) {
            $problems[] = sprintf("REDIS_PREFIX is '%s'; expected '%s'.", $prefix, self::PREFIX);
        }

        $retryAfter = (int) config('queue.connections.redis.retry_after');
        if ($retryAfter <= $jobTimeout) {
            $problems[] = "REDIS_QUEUE_RETRY_AFTER ({$retryAfter}s) must be longer than the worker's job timeout ({$jobTimeout}s), or a slow job is handed out twice.";
        }

        return $problems;
    }

    /**
     * Connects to each reserved database without the key prefix and looks for keys that aren't ours: if there are any,
     * another project uses the index. Only counts keys; never reads or prints another project's key names or values.
     *
     * @return list<string>
     */
    public static function redisProblems(int $redisDb, int $cacheDb, int $scanLimit = 20000): array
    {
        $problems = [];
        $server = (array) config('database.redis.default');

        foreach (array_unique([$redisDb, $cacheDb]) as $database) {
            $client = new Client(array_filter([
                'host' => $server['host'] ?? '127.0.0.1',
                'port' => (int) ($server['port'] ?? 6379),
                'username' => $server['username'] ?? null,
                'password' => $server['password'] ?? null,
                'database' => $database,
                'timeout' => 3.0,
            ], fn ($value) => $value !== null && $value !== ''));

            try {
                $client->ping();
                $cursor = '0';
                $scanned = 0;
                $foreign = 0;
                do {
                    [$cursor, $keys] = $client->scan($cursor, ['COUNT' => 500]);
                    foreach ($keys as $key) {
                        $scanned++;
                        $foreign += str_starts_with((string) $key, self::PREFIX) ? 0 : 1;
                    }
                } while ((string) $cursor !== '0' && $scanned < $scanLimit);
            } catch (Throwable $e) {
                $problems[] = "Redis database {$database} can't be checked: {$e->getMessage()}";

                continue;
            } finally {
                $client->disconnect();
            }

            if ($foreign > 0) {
                $problems[] = "Redis database {$database} holds {$foreign} key(s) without the '".self::PREFIX."' prefix: another project uses it.";
            }
        }

        return $problems;
    }
}
