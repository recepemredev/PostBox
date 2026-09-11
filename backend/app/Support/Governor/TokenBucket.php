<?php

declare(strict_types=1);

namespace App\Support\Governor;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use RuntimeException;

/**
 * The token bucket itself: one atomic Lua script, so a check-and-decrement can
 * never split into two round trips a concurrent request could land between.
 *
 * The script runs through command() rather than the eval() helper
 * PhpRedisConnection adds on top of it: RedisFactory::connection() is typed to
 * the base Connection class, and command() reaches the same underlying client
 * call with the same [script, args, numkeys] argument shape the phpredis
 * extension expects — the client this application is fixed to, per
 * docker/backend/Dockerfile and REDIS_CLIENT.
 */
final readonly class TokenBucket
{
    private string $script;

    public function __construct(private RedisFactory $redis)
    {
        $script = file_get_contents(resource_path('lua/token_bucket.lua'));

        $this->script = $script !== false
            ? $script
            : throw new RuntimeException('resources/lua/token_bucket.lua is missing.');
    }

    /**
     * @return array{allowed: bool, remaining: int, resetMs: int, retryMs: int}
     */
    public function consume(string $key, RateLimit $limit, CarbonImmutable $now): array
    {
        // Long enough for a bucket left completely idle to refill from empty,
        // plus a minute of slack — past that, nothing distinguishes it from a
        // bucket that has never been touched, so there is nothing worth keeping.
        $ttlSeconds = (int) ceil($limit->capacity / $limit->refillPerSecond) + 60;

        /** @var list<int> $result */
        $result = $this->redis->connection()->command('eval', [
            $this->script,
            [$key, $limit->capacity, $limit->refillPerSecond, $now->getTimestampMs(), $ttlSeconds],
            1,
        ]);

        return [
            'allowed' => $result[0] === 1,
            'remaining' => $result[1],
            'resetMs' => $result[2],
            'retryMs' => $result[3],
        ];
    }
}
