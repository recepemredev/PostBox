<?php

declare(strict_types=1);

use App\Enums\BreakerState;
use App\Models\Endpoint;
use App\Models\EndpointCircuitBreaker;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * The two CHECK constraints the migration writes: the state column is held to
 * exactly the BreakerState enum, and a row's shape — which of opened_at and
 * probe_started_at are set — is held to what its state actually means. Both
 * are enforced in the database, not only by TransitionBreaker's own
 * discipline, the same way deliveries.exhausted_at is.
 */

beforeEach(function (): void {
    $this->tenant = tenantNamed('Acme');
    $this->endpoint = forTenant($this->tenant, fn (): Endpoint => Endpoint::factory()->create());
});

it('constrains the state column to exactly the BreakerState enum', function (): void {
    $constraint = DB::selectOne(
        "select pg_get_constraintdef(oid) as definition
         from pg_constraint where conname = 'endpoint_circuit_breakers_state_check'"
    );

    preg_match_all("/'([^']+)'/", (string) $constraint->definition, $matches);

    $allowed = collect($matches[1])->sort()->values()->all();
    $declared = collect(BreakerState::cases())
        ->map(fn (BreakerState $case): string => $case->value)
        ->sort()
        ->values()
        ->all();

    expect($allowed)->toBe($declared);
});

it('accepts every shape TransitionBreaker actually writes', function (BreakerState $state): void {
    $breaker = breakerAt($this->tenant, $this->endpoint, $state);

    expect($breaker->state)->toBe($state);
})->with([
    'closed' => [BreakerState::Closed],
    'open' => [BreakerState::Open],
    'half-open' => [BreakerState::HalfOpen],
]);

it('refuses an open breaker recorded without the moment it tripped', function (): void {
    forTenant($this->tenant, function (): void {
        EndpointCircuitBreaker::factory()->for($this->endpoint)->create([
            'state' => BreakerState::Open,
            'opened_at' => null,
        ]);
    });
})->throws(QueryException::class);

it('refuses a closed breaker recorded with a trip still attached', function (): void {
    forTenant($this->tenant, function (): void {
        EndpointCircuitBreaker::factory()->for($this->endpoint)->closed()->create([
            'opened_at' => now(),
        ]);
    });
})->throws(QueryException::class);

it('refuses a half-open breaker recorded without a probe', function (): void {
    forTenant($this->tenant, function (): void {
        EndpointCircuitBreaker::factory()->for($this->endpoint)->create([
            'state' => BreakerState::HalfOpen,
            'probe_started_at' => null,
        ]);
    });
})->throws(QueryException::class);

it('refuses a second breaker row for the same endpoint', function (): void {
    breakerAt($this->tenant, $this->endpoint, BreakerState::Open);

    expect(fn () => breakerAt($this->tenant, $this->endpoint, BreakerState::Open))
        ->toThrow(QueryException::class);
});

it('keeps two tenants from reading each other\'s breakers', function (): void {
    breakerAt($this->tenant, $this->endpoint, BreakerState::Open);

    $other = tenantNamed('Globex');
    $otherEndpoint = forTenant($other, fn (): Endpoint => Endpoint::factory()->create());

    expect(endpointBreaker($other, $otherEndpoint))->toBeNull();
    expect(forTenant($other, fn (): int => EndpointCircuitBreaker::query()->count()))->toBe(0);
});

it('has no breaker until an endpoint actually trips', function (): void {
    expect(endpointBreaker($this->tenant, $this->endpoint))->toBeNull();
});
