<?php

declare(strict_types=1);

namespace App\Actions\Resilience;

use App\Enums\AttemptOutcome;
use App\Enums\BreakerState;
use App\Models\DeliveryAttempt;
use App\Models\Endpoint;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;

/**
 * What one just-recorded attempt means for its endpoint's breaker. Called
 * after AttemptDelivery has already written the delivery_attempts row, so the
 * rolling window this reads already includes the attempt that triggered it —
 * there is no separate counter to keep in step with the ledger.
 *
 * Every non-success counts the same way, whatever kind of failure it was: a
 * timeout, a 500, a terminal 404 and a Blocked refusal are all, from this
 * class's one question — "is this endpoint currently accepting deliveries
 * successfully" — the same "no". RetryPolicy's retryable/terminal split
 * answers a different question (is trying again worth it) and stays out of
 * this one on purpose; folding them together would make a terminal failure on
 * an otherwise healthy endpoint start counting toward a breaker it has
 * nothing to do with tripping just because it also stops a retry.
 */
final readonly class RecordAttemptOutcome
{
    public function __construct(private TransitionBreaker $transition) {}

    public function handle(Endpoint $endpoint, AttemptOutcome $outcome, CarbonImmutable $now): void
    {
        $breaker = $endpoint->breaker;
        $state = $breaker !== null ? $breaker->state : BreakerState::Closed;

        if ($outcome === AttemptOutcome::Succeeded) {
            // Re-enabling is only ever the probe's to decide (CLAUDE.md): a
            // success reaching here while the breaker is still open is a
            // stray attempt that started before the breaker tripped, and it
            // does not get to close anything on its own.
            if ($state === BreakerState::HalfOpen) {
                $this->transition->handle($endpoint, BreakerState::Closed, $now);
            }

            return;
        }

        if ($state === BreakerState::HalfOpen) {
            $this->transition->handle($endpoint, BreakerState::Open, $now);

            return;
        }

        if ($state === BreakerState::Open) {
            // Already refusing traffic; the dispatcher is what is supposed to
            // stop this attempt from ever having been sent. Nothing to do.
            return;
        }

        if ($this->failuresInWindow($endpoint, $now) >= Config::integer('postbox.breaker.failure_threshold')) {
            $this->transition->handle($endpoint, BreakerState::Open, $now);
        }
    }

    /**
     * Non-success attempts in the trailing window, read straight from the
     * ledger rather than a counter kept beside it — one fact, one place,
     * through the same (endpoint_id, created_at) index the migration built
     * for exactly this read.
     */
    private function failuresInWindow(Endpoint $endpoint, CarbonImmutable $now): int
    {
        return DeliveryAttempt::query()
            ->where('endpoint_id', $endpoint->id)
            ->where('created_at', '>=', $now->subSeconds(Config::integer('postbox.breaker.window_seconds')))
            ->where('outcome', '!=', AttemptOutcome::Succeeded)
            ->count();
    }
}
