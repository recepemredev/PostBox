<?php

declare(strict_types=1);

namespace App\Support\Operations;

/**
 * How many of the current tenant's endpoints sit in each breaker state.
 * `closed` includes every endpoint that has never tripped at all — a
 * breaker row only exists once it has (EndpointCircuitBreaker) — so this is
 * derived from the total endpoint count rather than a query over rows that
 * do not exist for most endpoints most of the time.
 */
final readonly class BreakerCounts
{
    public function __construct(
        public int $closed,
        public int $open,
        public int $halfOpen,
    ) {}
}
