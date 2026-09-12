<?php

declare(strict_types=1);

use App\Enums\BreakerState;
use App\Models\Endpoint;

/*
 * postbox:breakers is DeadLetterQueueCommand's counterpart for the breaker:
 * an operator's read-only view, one tenant at a time, of whatever is
 * currently tripped.
 */

it('lists tripped breakers for an operator, one tenant at a time', function (): void {
    $acme = tenantNamed('Acme');
    $acmeEndpoint = forTenant($acme, fn (): Endpoint => Endpoint::factory()->create());
    breakerAt($acme, $acmeEndpoint, BreakerState::Open);

    $globex = tenantNamed('Globex');
    $globexEndpoint = forTenant($globex, fn (): Endpoint => Endpoint::factory()->create());
    breakerAt($globex, $globexEndpoint, BreakerState::HalfOpen);

    $this->artisan('postbox:breakers')
        ->expectsOutputToContain($acmeEndpoint->public_id)
        ->expectsOutputToContain($globexEndpoint->public_id)
        ->expectsOutputToContain('breakers: 2 tripped breakers listed.')
        ->assertSuccessful();
});

it('does not list a breaker that has closed again', function (): void {
    $tenant = tenantNamed('Acme');
    $endpoint = forTenant($tenant, fn (): Endpoint => Endpoint::factory()->create());
    breakerAt($tenant, $endpoint, BreakerState::Closed);

    $this->artisan('postbox:breakers')
        ->expectsOutputToContain('breakers: 0 tripped breakers listed.')
        ->doesntExpectOutputToContain($endpoint->public_id)
        ->assertSuccessful();
});

it('shows nothing while no breaker has ever tripped', function (): void {
    $this->artisan('postbox:breakers')
        ->expectsOutputToContain('breakers: 0 tripped breakers listed.')
        ->assertSuccessful();
});
