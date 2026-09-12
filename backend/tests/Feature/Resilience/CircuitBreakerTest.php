<?php

declare(strict_types=1);

use App\Actions\Resilience\RecordAttemptOutcome;
use App\Enums\AttemptOutcome;
use App\Enums\BreakerState;
use App\Models\DeliveryAttempt;
use App\Models\Endpoint;
use App\Models\Tenant;
use Carbon\CarbonImmutable;

/*
 * Whether an endpoint's breaker trips, and whether a probe can close it again.
 * RecordAttemptOutcome keeps no counter of its own — the window is read
 * straight from delivery_attempts — so every test here seeds a real ledger row
 * for whatever it wants counted, the same trade RetryScheduleTest makes to
 * exercise RetryPolicy directly rather than through a whole delivery.
 */

beforeEach(function (): void {
    config()->set('postbox.breaker.failure_threshold', 3);
    config()->set('postbox.breaker.window_seconds', 60);

    $this->tenant = tenantNamed('Acme');
    $this->endpoint = forTenant($this->tenant, fn (): Endpoint => Endpoint::factory()->create());
});

/**
 * One ledger row against this test's endpoint, at the given outcome — what
 * AttemptDelivery would already have written before ever asking the breaker
 * about it.
 */
function seedAttempt(Tenant $tenant, Endpoint $endpoint, AttemptOutcome $outcome): void
{
    forTenant($tenant, function () use ($endpoint, $outcome): void {
        $factory = DeliveryAttempt::factory()->for($endpoint);

        (match ($outcome) {
            AttemptOutcome::Timeout => $factory->timedOut(),
            AttemptOutcome::Blocked => $factory->blocked(),
            AttemptOutcome::Succeeded => $factory,
            default => $factory->failed(),
        })->create();
    });
}

/**
 * Records the ledger row and asks the breaker about it in one step — the
 * shape AttemptDelivery itself always takes: write first, decide second.
 *
 * The endpoint is re-fetched rather than reused across calls: production
 * never asks twice on the same in-memory model — every real attempt is a
 * fresh job execution loading its own copy — and Eloquent caches a `breaker`
 * relation once it is read, so reusing $this->endpoint across several calls
 * in one test would keep answering with whatever the first call saw.
 */
function attemptWithOutcome(Tenant $tenant, Endpoint $endpoint, AttemptOutcome $outcome, CarbonImmutable $now): void
{
    seedAttempt($tenant, $endpoint, $outcome);

    forTenant($tenant, function () use ($endpoint, $outcome, $now): void {
        $fresh = Endpoint::query()->findOrFail($endpoint->id);

        app(RecordAttemptOutcome::class)->handle($fresh, $outcome, $now);
    });
}

it('opens the breaker once failures in the window reach the threshold', function (): void {
    $now = CarbonImmutable::now();

    attemptWithOutcome($this->tenant, $this->endpoint, AttemptOutcome::Failed, $now);
    attemptWithOutcome($this->tenant, $this->endpoint, AttemptOutcome::Failed, $now);

    expect(endpointBreaker($this->tenant, $this->endpoint))->toBeNull();

    attemptWithOutcome($this->tenant, $this->endpoint, AttemptOutcome::Failed, $now);

    expect(endpointBreaker($this->tenant, $this->endpoint)?->state)->toBe(BreakerState::Open);
});

it('counts every non-success outcome the same way toward the threshold', function (AttemptOutcome $outcome): void {
    $now = CarbonImmutable::now();

    attemptWithOutcome($this->tenant, $this->endpoint, $outcome, $now);
    attemptWithOutcome($this->tenant, $this->endpoint, $outcome, $now);
    attemptWithOutcome($this->tenant, $this->endpoint, $outcome, $now);

    expect(endpointBreaker($this->tenant, $this->endpoint)?->state)->toBe(BreakerState::Open);
})->with([
    'a timeout' => [AttemptOutcome::Timeout],
    'a 5xx response' => [AttemptOutcome::Failed],
    'a refusal PostBox made itself' => [AttemptOutcome::Blocked],
]);

it('does not let an interleaved success reset the failure count', function (): void {
    $now = CarbonImmutable::now();

    attemptWithOutcome($this->tenant, $this->endpoint, AttemptOutcome::Failed, $now);
    attemptWithOutcome($this->tenant, $this->endpoint, AttemptOutcome::Failed, $now);
    attemptWithOutcome($this->tenant, $this->endpoint, AttemptOutcome::Succeeded, $now);

    expect(endpointBreaker($this->tenant, $this->endpoint))->toBeNull();

    attemptWithOutcome($this->tenant, $this->endpoint, AttemptOutcome::Failed, $now);

    // Three failures inside the window, whatever succeeded in between them.
    expect(endpointBreaker($this->tenant, $this->endpoint)?->state)->toBe(BreakerState::Open);
});

it('does not count a failure that has fallen outside the window', function (): void {
    $start = CarbonImmutable::now();

    attemptWithOutcome($this->tenant, $this->endpoint, AttemptOutcome::Failed, $start);

    $later = $start->addSeconds(61);

    attemptWithOutcome($this->tenant, $this->endpoint, AttemptOutcome::Failed, $later);
    attemptWithOutcome($this->tenant, $this->endpoint, AttemptOutcome::Failed, $later);

    // Only 2 of the 3 failures are inside the trailing 60s window as seen
    // from $later — the first one is 61s old by then.
    expect(endpointBreaker($this->tenant, $this->endpoint))->toBeNull();
});

it('closes on a successful probe', function (): void {
    breakerAt($this->tenant, $this->endpoint, BreakerState::HalfOpen);

    attemptWithOutcome($this->tenant, $this->endpoint, AttemptOutcome::Succeeded, CarbonImmutable::now());

    expect(endpointBreaker($this->tenant, $this->endpoint)?->state)->toBe(BreakerState::Closed);
});

it('reopens on a failed probe, starting a fresh trip', function (): void {
    $breaker = breakerAt($this->tenant, $this->endpoint, BreakerState::HalfOpen);

    attemptWithOutcome($this->tenant, $this->endpoint, AttemptOutcome::Failed, CarbonImmutable::now());

    $reopened = endpointBreaker($this->tenant, $this->endpoint);

    // opened_at is whole-second precision, so "fresh" is not-older rather
    // than a strict greater-than a fast test could tie on the same second.
    expect($reopened?->state)->toBe(BreakerState::Open)
        ->and($reopened?->opened_at?->greaterThanOrEqualTo($breaker->opened_at))->toBeTrue();
});

it('does not let a stray success while open close the breaker on its own', function (): void {
    breakerAt($this->tenant, $this->endpoint, BreakerState::Open);

    // A success reaching the breaker while it is open is an attempt that was
    // already in flight before the trip — not the probe. CLAUDE.md's
    // "re-enabled only through the half-open probe path" means this does
    // nothing, however good the news.
    attemptWithOutcome($this->tenant, $this->endpoint, AttemptOutcome::Succeeded, CarbonImmutable::now());

    expect(endpointBreaker($this->tenant, $this->endpoint)?->state)->toBe(BreakerState::Open);
});
