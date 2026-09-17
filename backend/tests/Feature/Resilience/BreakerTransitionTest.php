<?php

declare(strict_types=1);

use App\Actions\Resilience\TransitionBreaker;
use App\Enums\BreakerState;
use App\Exceptions\InvalidBreakerTransition;
use App\Models\AuditLog;
use App\Models\Endpoint;
use App\Models\EndpointCircuitBreaker;
use App\Models\Tenant;
use Carbon\CarbonImmutable;

/*
 * BreakerState's transition table, exercised through its one writer. Every
 * legal edge lands a row and an audit entry together; everything else —
 * including a state transitioning to itself — is rejected before either is
 * touched.
 */

beforeEach(function (): void {
    $this->tenant = tenantNamed('Acme');
    $this->endpoint = forTenant($this->tenant, fn (): Endpoint => Endpoint::factory()->create());
});

function transition(Tenant $tenant, Endpoint $endpoint, BreakerState $to): bool
{
    return forTenant(
        $tenant,
        fn (): bool => app(TransitionBreaker::class)->handle($endpoint, $to, CarbonImmutable::now()),
    );
}

it('opens a breaker that has never tripped before, where there is no row to update', function (): void {
    expect(transition($this->tenant, $this->endpoint, BreakerState::Open))->toBeTrue();

    $breaker = endpointBreaker($this->tenant, $this->endpoint);

    expect($breaker->state)->toBe(BreakerState::Open)
        ->and($breaker->opened_at)->not->toBeNull()
        ->and($breaker->probe_started_at)->toBeNull();
});

it('reopens a breaker that had already closed once', function (): void {
    breakerAt($this->tenant, $this->endpoint, BreakerState::Closed);

    expect(transition($this->tenant, $this->endpoint, BreakerState::Open))->toBeTrue();

    expect(endpointBreaker($this->tenant, $this->endpoint)->state)->toBe(BreakerState::Open);
});

it('moves open to half-open, keeping the original opened_at', function (): void {
    $breaker = breakerAt($this->tenant, $this->endpoint, BreakerState::Open);

    expect(transition($this->tenant, $this->endpoint, BreakerState::HalfOpen))->toBeTrue();

    $reloaded = endpointBreaker($this->tenant, $this->endpoint);

    expect($reloaded->state)->toBe(BreakerState::HalfOpen)
        ->and($reloaded->opened_at?->equalTo($breaker->opened_at))->toBeTrue()
        ->and($reloaded->probe_started_at)->not->toBeNull();
});

it('moves half-open to closed on a successful probe, clearing the trip', function (): void {
    breakerAt($this->tenant, $this->endpoint, BreakerState::HalfOpen);

    expect(transition($this->tenant, $this->endpoint, BreakerState::Closed))->toBeTrue();

    $breaker = endpointBreaker($this->tenant, $this->endpoint);

    expect($breaker->state)->toBe(BreakerState::Closed)
        ->and($breaker->opened_at)->toBeNull()
        ->and($breaker->probe_started_at)->toBeNull();
});

it('moves half-open back to open on a failed probe, with a fresh opened_at', function (): void {
    $breaker = breakerAt($this->tenant, $this->endpoint, BreakerState::HalfOpen);

    expect(transition($this->tenant, $this->endpoint, BreakerState::Open))->toBeTrue();

    $reloaded = endpointBreaker($this->tenant, $this->endpoint);

    // opened_at is stored to whole-second precision (deliveries.next_attempt_at
    // takes the same trade), so "fresh" is asserted as not-older rather than a
    // strict greater-than that a fast test could tie on the same second.
    expect($reloaded->state)->toBe(BreakerState::Open)
        ->and($reloaded->opened_at?->greaterThanOrEqualTo($breaker->opened_at))->toBeTrue()
        ->and($reloaded->probe_started_at)->toBeNull();
});

it('writes an audit entry for every winning transition', function (): void {
    transition($this->tenant, $this->endpoint, BreakerState::Open);

    $entry = forTenant($this->tenant, fn (): AuditLog => AuditLog::query()->sole());

    expect($entry->action)->toBe('breaker.opened')
        ->and($entry->entity_type)->toBe('endpoint')
        ->and($entry->entity_id)->toBe($this->endpoint->id)
        ->and($entry->entity_public_id)->toBe($this->endpoint->public_id)
        ->and($entry->actor_id)->toBeNull()
        // toEqual rather than toBe: jsonb does not promise to preserve key
        // order, only the content (D43 makes the same distinction for a
        // payload read back through Postgres).
        ->and($entry->changes)->toEqual(['state' => ['before' => 'closed', 'after' => 'open']]);
});

it('rejects every illegal transition without writing a row or an audit entry', function (BreakerState $from, BreakerState $to): void {
    if ($from !== BreakerState::Closed) {
        breakerAt($this->tenant, $this->endpoint, $from);
    }

    expect(fn () => transition($this->tenant, $this->endpoint, $to))
        ->toThrow(InvalidBreakerTransition::class);

    forTenant($this->tenant, function () use ($from): void {
        expect(AuditLog::query()->count())->toBe(0);

        $breaker = EndpointCircuitBreaker::query()->first();

        // The row this call started from — none of it, is left untouched;
        // an illegal request never gets far enough to attempt a write.
        expect($breaker === null ? BreakerState::Closed : $breaker->state)->toBe($from);
    });
})->with([
    'closed to closed' => [BreakerState::Closed, BreakerState::Closed],
    'closed to half-open' => [BreakerState::Closed, BreakerState::HalfOpen],
    'open to open' => [BreakerState::Open, BreakerState::Open],
    'open to closed' => [BreakerState::Open, BreakerState::Closed],
    'half-open to half-open' => [BreakerState::HalfOpen, BreakerState::HalfOpen],
]);

/*
 * The race on an endpoint's very first trip, and on every later compare-and-
 * set move, is exercised with a genuine second session in BreakerRaceTest —
 * a real committed rival rather than a second connection this file would
 * otherwise have to open just for the two tests that need one.
 */
