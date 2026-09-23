<?php

declare(strict_types=1);

namespace PostBox;

/**
 * The one place PostBox::publish()'s retry schedule is defined: attempt
 * count, base delay, growth factor and ceiling. Mirrors the shape of the
 * backend's own retry schedule (CLAUDE.md: attempt count, base delay, growth
 * factor, jitter, ceiling, defined once) without carrying jitter — the
 * backend's schedule spaces retries across minutes and jitter exists there
 * to avoid a thundering herd across many endpoints; three retries inside one
 * publish() call, at most a few seconds apart, present no such herd, and the
 * fixed schedule is what a test can then assert without stubbing randomness.
 *
 * A 429 defers to the server's own Retry-After instead of this schedule,
 * because the server is the one holding the token bucket.
 */
final readonly class RetryPolicy
{
    public function __construct(
        private int $maxAttempts = 3,
        private int $baseDelayMs = 500,
        private int $factor = 2,
        private int $ceilingMs = 8_000,
    ) {}

    public function maxAttempts(): int
    {
        return $this->maxAttempts;
    }

    /**
     * $status is null for a transport-level failure (no response reached at
     * all) — always retryable, the same as a 5xx or a 429.
     */
    public function isRetryable(?int $status): bool
    {
        return $status === null || $status === 429 || $status >= 500;
    }

    /**
     * The wait before the next attempt, in milliseconds. $attempt is the
     * attempt that just failed (1-indexed), so the first retry uses the base
     * delay unmultiplied. $retryAfterSeconds, when the server gave one on a
     * 429, wins over the computed delay outright.
     */
    public function delayMs(int $attempt, ?int $retryAfterSeconds = null): int
    {
        if ($retryAfterSeconds !== null) {
            return $retryAfterSeconds * 1_000;
        }

        $exponential = $this->baseDelayMs * ($this->factor ** ($attempt - 1));

        return min($exponential, $this->ceilingMs);
    }
}
