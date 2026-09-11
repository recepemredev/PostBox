<?php

declare(strict_types=1);

namespace App\Support\Governor;

/**
 * A plan's token bucket shape: how big a burst it holds, and how fast it
 * refills. Both are whole numbers — the bucket never holds a fraction of a
 * token, which is what keeps the Lua script's refill arithmetic exact.
 */
final readonly class RateLimit
{
    public function __construct(
        public int $capacity,
        public int $refillPerSecond,
    ) {}
}
