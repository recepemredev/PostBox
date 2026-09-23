<?php

declare(strict_types=1);

namespace App\Support\Stream;

use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\Facades\Config;
use RuntimeException;

/**
 * Per-tenant concurrent SSE connection admission. One atomic Lua script, the
 * same reasoning TokenBucket already carries: a check-and-add can never
 * split into two round trips a rival connection could land between.
 *
 * The key is its own namespace ("stream:slots:") rather than TokenBucket's
 * "governor:bucket:" — CLAUDE.md's "rate limit and quota are separate
 * mechanisms with separate storage" reasoning, applied a third time to a
 * third mechanism with a different lifetime.
 */
final readonly class StreamSlots
{
    private string $script;

    public function __construct(private RedisFactory $redis)
    {
        $script = file_get_contents(resource_path('lua/stream_slots.lua'));

        $this->script = $script !== false
            ? $script
            : throw new RuntimeException('resources/lua/stream_slots.lua is missing.');
    }

    /**
     * Try to admit one connection. False means the tenant's cap is already
     * reached — the caller answers 429.
     */
    public function admit(Tenant $tenant, string $connectionId, CarbonImmutable $now): bool
    {
        /** @var list<int> $result */
        $result = $this->redis->connection()->command('eval', [
            $this->script,
            [$this->key($tenant), $now->getTimestampMs(), $this->ttlMs(), $this->maxConcurrent(), $connectionId],
            1,
        ]);

        return $result[0] === 1;
    }

    /** Release a connection's slot. Always called from a finally block. */
    public function release(Tenant $tenant, string $connectionId): void
    {
        $this->redis->connection()->command('zrem', [$this->key($tenant), $connectionId]);
    }

    /**
     * The tenant's active slot count, for the test and nothing else — a
     * plain read (expire the stale, then count), never the admit script:
     * reusing that script here would insert a phantom connection as a side
     * effect of measuring, which is exactly the bug a "read" must not have.
     */
    public function active(Tenant $tenant, CarbonImmutable $now): int
    {
        $key = $this->key($tenant);

        $this->redis->connection()->command('zremrangebyscore', [$key, '-inf', (string) $now->getTimestampMs()]);

        /** @var int $count */
        $count = $this->redis->connection()->command('zcard', [$key]);

        return $count;
    }

    private function key(Tenant $tenant): string
    {
        return "stream:slots:{$tenant->public_id}";
    }

    private function ttlMs(): int
    {
        return Config::integer('postbox.stream.max_lifetime_seconds') * 1000 + 5000;
    }

    private function maxConcurrent(): int
    {
        return Config::integer('postbox.stream.max_concurrent_per_tenant');
    }
}
