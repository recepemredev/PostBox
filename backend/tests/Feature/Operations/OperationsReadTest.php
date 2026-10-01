<?php

declare(strict_types=1);

use App\Enums\BreakerState;
use App\Enums\RoleSlug;
use App\Models\Endpoint;
use App\Models\EndpointCircuitBreaker;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Contracts\MetricsRepository;
use Laravel\Horizon\Contracts\WorkloadRepository;

/*
 * The operations screen's one read (Step 17): the three delivery queues'
 * workload — shared infrastructure, scoped to no tenant — and this tenant's
 * own breakers by state. Horizon's own repositories are doubled the same
 * way HttpTransport is elsewhere (fakeTransport()): the one seam this
 * action is built around.
 */

/**
 * @param  list<array{name: string, length: int, wait: float, processes: int, split_queues: null}>  $workloadRows
 * @param  array<string, float>  $runtimeMs
 * @param  array<string, int>  $throughput
 */
function fakeHorizon(array $workloadRows, array $runtimeMs = [], array $throughput = []): void
{
    $workload = Mockery::mock(WorkloadRepository::class);
    $workload->shouldReceive('get')->andReturn($workloadRows);
    app()->instance(WorkloadRepository::class, $workload);

    $metrics = Mockery::mock(MetricsRepository::class);
    $metrics->shouldReceive('runtimeForQueue')->andReturnUsing(fn (string $queue): float => $runtimeMs[$queue] ?? 0.0);
    $metrics->shouldReceive('throughputForQueue')->andReturnUsing(fn (string $queue): int => $throughput[$queue] ?? 0);
    app()->instance(MetricsRepository::class, $metrics);
}

it("returns queue workload and this tenant's breaker counts", function (): void {
    $tenant = tenantNamed('Acme');
    $admin = memberOf($tenant);

    forTenant($tenant, fn (): Endpoint => Endpoint::factory()->create()); // never trips: counts as closed
    $openEndpoint = forTenant($tenant, fn (): Endpoint => Endpoint::factory()->create());
    breakerAt($tenant, $openEndpoint, BreakerState::Open);

    fakeHorizon(
        workloadRows: [
            ['name' => 'deliveries', 'length' => 3, 'wait' => 2.0, 'processes' => 10, 'split_queues' => null],
            ['name' => 'retries', 'length' => 1, 'wait' => 0.0, 'processes' => 5, 'split_queues' => null],
        ],
        runtimeMs: ['deliveries' => 12.5, 'retries' => 4.0],
        throughput: ['deliveries' => 42, 'retries' => 3],
    );

    $response = $this->actingAs($admin)->getJson(route('operations'))->assertOk();

    expect($response->json('queues.0'))->toBe([
        'name' => 'deliveries',
        'length' => 3,
        'wait_ms' => 2000,
        'processes' => 10,
        'runtime_ms' => 13,
        'throughput' => 42,
    ])
        // The third queue, maintenance, carries no traffic yet (D51) and is
        // never in Horizon's own workload rows — still present, at zero.
        ->and($response->json('queues.2'))->toBe([
            'name' => 'maintenance',
            'length' => 0,
            'wait_ms' => 0,
            'processes' => 0,
            'runtime_ms' => 0,
            'throughput' => 0,
        ]);

    expect($response->json('breakers.closed'))->toBe(1)
        ->and($response->json('breakers.open'))->toBe(1)
        ->and($response->json('breakers.half_open'))->toBe(0)
        ->and($response->json('breakers.tripped'))->toHaveCount(1)
        ->and($response->json('breakers.tripped.0.endpoint_id'))->toBe($openEndpoint->public_id)
        ->and($response->json('breakers.tripped.0.state'))->toBe('open');
});

it("never counts or lists another tenant's breaker", function (): void {
    $acme = tenantNamed('Acme');
    $globex = tenantNamed('Globex');
    $admin = memberOf($acme);

    forTenant($acme, fn (): Endpoint => Endpoint::factory()->create());
    $globexEndpoint = forTenant($globex, fn (): Endpoint => Endpoint::factory()->create());
    breakerAt($globex, $globexEndpoint, BreakerState::Open);

    fakeHorizon([]);

    $response = $this->actingAs($admin)->getJson(route('operations'))->assertOk();

    expect($response->json('breakers.closed'))->toBe(1)
        ->and($response->json('breakers.open'))->toBe(0)
        ->and($response->json('breakers.tripped'))->toBe([]);
});

it('permits a viewer to read it, not only an admin', function (): void {
    $tenant = tenantNamed('Acme');
    $viewer = memberOf($tenant, RoleSlug::Viewer);

    fakeHorizon([]);

    $this->actingAs($viewer)->getJson(route('operations'))->assertOk();
});

it('is denied at the Gate for a user with no membership in any tenant', function (): void {
    $tenant = tenantNamed('Acme');
    $stranger = User::factory()->create();

    forTenant($tenant, function () use ($stranger): void {
        expect(Gate::forUser($stranger)->allows('viewAny', EndpointCircuitBreaker::class))->toBeFalse();
    });
});

it('an unauthenticated request gets 401 JSON, never a redirect', function (): void {
    $this->getJson('/api/v1/operations')
        ->assertUnauthorized()
        ->assertJsonStructure(['message']);
});
