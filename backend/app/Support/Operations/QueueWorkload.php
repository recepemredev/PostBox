<?php

declare(strict_types=1);

namespace App\Support\Operations;

/**
 * One queue's current standing, read from Horizon's own Redis-backed
 * repositories rather than kept anywhere of PostBox's own — Horizon is
 * already the supervisor counting these numbers for its own balancing
 * (config/horizon.php), so a second counter here would be the duplicated
 * fact CLAUDE.md rules out, not a second source of truth to reconcile.
 *
 * Shared infrastructure, not tenant data: every tenant's deliveries share
 * the same three queues, so nothing here is scoped to one tenant and
 * nothing here carries a tenant id.
 */
final readonly class QueueWorkload
{
    public function __construct(
        public string $name,
        public int $length,
        public int $waitMs,
        public int $processes,
        public int $runtimeMs,
        public int $throughput,
    ) {}
}
