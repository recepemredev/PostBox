<?php

declare(strict_types=1);

namespace App\Actions\Resilience;

use App\Enums\BreakerState;
use App\Models\Endpoint;
use App\Models\EndpointCircuitBreaker;
use App\Support\Resilience\Admission;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;

/**
 * Whether an endpoint's breaker will let a claimed batch of deliveries go to a
 * worker at all. DispatchOutbox asks this once per endpoint, ahead of handing
 * anything to the queue — the whole point is that a worker never spends a slot
 * finding out an endpoint is cut off; the dispatcher already knew.
 *
 * A closed breaker, or no breaker at all, admits everything. An open one
 * admits nothing until its hold expires, at which point admitting is itself
 * the transition to half-open — asking is what opens the probe window, not a
 * separate step afterwards. A half-open breaker admits exactly one probe; the
 * rest of the batch is deferred to whenever that probe is expected to settle.
 */
final readonly class AdmitEndpoint
{
    public function __construct(private TransitionBreaker $transition) {}

    public function handle(Endpoint $endpoint, CarbonImmutable $now): Admission
    {
        return $this->resolve($endpoint, $endpoint->breaker, $now);
    }

    private function resolve(Endpoint $endpoint, ?EndpointCircuitBreaker $breaker, CarbonImmutable $now): Admission
    {
        if ($breaker === null || $breaker->state === BreakerState::Closed) {
            return Admission::all();
        }

        return $breaker->state === BreakerState::Open
            ? $this->fromOpen($endpoint, $breaker, $now)
            : $this->fromHalfOpen($endpoint, $breaker, $now);
    }

    private function fromOpen(Endpoint $endpoint, EndpointCircuitBreaker $breaker, CarbonImmutable $now): Admission
    {
        assert($breaker->opened_at !== null);

        $reopensAt = $breaker->opened_at->addSeconds(Config::integer('postbox.breaker.open_seconds'));

        if ($now->lessThan($reopensAt)) {
            return Admission::deferUntil($reopensAt);
        }

        if ($this->transition->handle($endpoint, BreakerState::HalfOpen, $now)) {
            return Admission::probe($this->probeTimesOutAt($now));
        }

        /*
         * Lost the race: another dispatcher pass moved this breaker on
         * already — to half-open with its own probe, or further still.
         * Re-reading and resolving again catches up to whatever it actually
         * left behind, rather than assuming which move it was.
         */
        return $this->resolve($endpoint, $this->fresh($endpoint), $now);
    }

    private function fromHalfOpen(Endpoint $endpoint, EndpointCircuitBreaker $breaker, CarbonImmutable $now): Admission
    {
        assert($breaker->probe_started_at !== null);

        $probeExpiresAt = $breaker->probe_started_at->addSeconds(Config::integer('postbox.breaker.probe_timeout_seconds'));

        if ($now->lessThan($probeExpiresAt)) {
            return Admission::deferUntil($probeExpiresAt);
        }

        /*
         * The worker holding this probe never ran — the same "the lease
         * expired" reasoning the outbox's own claim makes. The state does not
         * change, only which attempt owns the slot, so this re-arms the
         * timestamp directly rather than asking TransitionBreaker for a move
         * BreakerState does not even have (half-open has no edge to itself).
         */
        if ($this->rearm($breaker, $now)) {
            return Admission::probe($this->probeTimesOutAt($now));
        }

        // Lost that race too — the probe settled (closed or re-opened) or
        // another pass re-armed it first. Re-read and resolve again.
        return $this->resolve($endpoint, $this->fresh($endpoint), $now);
    }

    private function probeTimesOutAt(CarbonImmutable $probeStartedAt): CarbonImmutable
    {
        return $probeStartedAt->addSeconds(Config::integer('postbox.breaker.probe_timeout_seconds'));
    }

    private function rearm(EndpointCircuitBreaker $breaker, CarbonImmutable $now): bool
    {
        return EndpointCircuitBreaker::query()
            ->whereKey($breaker->getKey())
            ->where('state', BreakerState::HalfOpen)
            ->where('probe_started_at', $breaker->probe_started_at)
            ->update(['probe_started_at' => $now, 'state_changed_at' => $now]) === 1;
    }

    private function fresh(Endpoint $endpoint): EndpointCircuitBreaker
    {
        /** @var EndpointCircuitBreaker $breaker */
        $breaker = EndpointCircuitBreaker::query()->where('endpoint_id', $endpoint->id)->firstOrFail();

        return $breaker;
    }
}
