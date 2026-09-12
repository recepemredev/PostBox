<?php

declare(strict_types=1);

namespace App\Support\Resilience;

use Carbon\CarbonImmutable;

/**
 * What the retry policy decided about one failed attempt: try again at a given
 * moment, or stop and say why. The caller writes the state; it never works out
 * either half for itself.
 *
 * The reason is only carried on a stop, because it is only ever read from one:
 * it is what lands in deliveries.failure_reason, which exists to answer "why is
 * this in the dead letter queue" and has nothing to say about a delivery that
 * is still going.
 */
final readonly class RetryDecision
{
    private function __construct(
        public bool $shouldRetry,
        public ?CarbonImmutable $nextAttemptAt = null,
        public ?string $reason = null,
    ) {}

    public static function retryAt(CarbonImmutable $at): self
    {
        return new self(shouldRetry: true, nextAttemptAt: $at);
    }

    public static function stop(string $reason): self
    {
        return new self(shouldRetry: false, reason: $reason);
    }
}
