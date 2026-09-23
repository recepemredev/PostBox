<?php

declare(strict_types=1);

namespace Tests\Support;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

/**
 * A PSR-20 clock that never advances — the conformance vectors pin `now`
 * explicitly, so Webhook::verify() needs a clock that answers exactly that
 * timestamp rather than the real one.
 */
final readonly class FixedClock implements ClockInterface
{
    public function __construct(private DateTimeImmutable $now) {}

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }
}
