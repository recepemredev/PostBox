<?php

declare(strict_types=1);

namespace App\Support\Resilience;

/**
 * The randomness in the retry schedule, and nothing else — the second of the
 * two pre-approved boundary interfaces (architecture.md). It exists so a test
 * can pin the jitter and assert the exact sequence of delays, not because a
 * second implementation is coming; RandomJitter is the only one that ships.
 *
 * The clock, which CLAUDE.md names alongside this one, is not an interface
 * here and does not need to be: `CarbonImmutable $now` is already a parameter
 * threaded from SendDelivery through AttemptDelivery into RetryPolicy, so a
 * test pins time by passing a different value rather than by binding a double.
 * That is the same determinism with one less indirection (D62).
 */
interface JitterSource
{
    /**
     * A fraction in [0, 1], multiplied into the spread half of a retry delay.
     */
    public function fraction(): float;
}
