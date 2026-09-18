<?php

declare(strict_types=1);

use App\Models\Application;
use App\Models\Endpoint;
use App\Models\EndpointSubscription;
use App\Models\EventType;

/*
 * Bringing an endpoint's subscriptions to exactly the given set of event
 * type names — additions, removals, and idempotency all in one call.
 */

beforeEach(function (): void {
    $this->acme = tenantNamed('Acme');
    $this->admin = memberOf($this->acme);

    [$this->application, $this->endpoint] = forTenant($this->acme, function (): array {
        $application = Application::factory()->create();

        return [$application, Endpoint::factory()->for($application)->create()];
    });
});

it('subscribes an endpoint to the given event types', function (): void {
    forTenant($this->acme, fn (): EventType => EventType::factory()->create(['name' => 'invoice.paid']));
    forTenant($this->acme, fn (): EventType => EventType::factory()->create(['name' => 'invoice.voided']));

    $this->actingAs($this->admin)
        ->putJson(
            route('endpoints.subscriptions.sync', ['endpoint' => $this->endpoint->public_id]),
            ['event_types' => ['invoice.paid', 'invoice.voided']],
        )
        ->assertOk()
        ->assertJsonPath('subscriptions', ['invoice.paid', 'invoice.voided']);
});

it('removes a subscription no longer named and adds a new one in the same call', function (): void {
    forTenant($this->acme, function (): void {
        $paid = EventType::factory()->create(['name' => 'invoice.paid']);
        EventType::factory()->create(['name' => 'invoice.voided']);

        EndpointSubscription::factory()->create([
            'endpoint_id' => $this->endpoint->id,
            'event_type_id' => $paid->id,
        ]);
    });

    $this->actingAs($this->admin)
        ->putJson(
            route('endpoints.subscriptions.sync', ['endpoint' => $this->endpoint->public_id]),
            ['event_types' => ['invoice.voided']],
        )
        ->assertOk()
        ->assertJsonPath('subscriptions', ['invoice.voided']);

    expect(forTenant($this->acme, fn (): int => $this->endpoint->subscriptions()->count()))->toBe(1);
});

it('changes nothing when called twice with the same names', function (): void {
    forTenant($this->acme, fn (): EventType => EventType::factory()->create(['name' => 'invoice.paid']));

    $sync = fn () => $this->actingAs($this->admin)->putJson(
        route('endpoints.subscriptions.sync', ['endpoint' => $this->endpoint->public_id]),
        ['event_types' => ['invoice.paid']],
    )->assertOk();

    $sync();
    $sync();

    expect(forTenant($this->acme, fn (): int => $this->endpoint->subscriptions()->count()))->toBe(1);
});

it('clears every subscription when given an empty list', function (): void {
    forTenant($this->acme, function (): void {
        $type = EventType::factory()->create(['name' => 'invoice.paid']);

        EndpointSubscription::factory()->create([
            'endpoint_id' => $this->endpoint->id,
            'event_type_id' => $type->id,
        ]);
    });

    $this->actingAs($this->admin)
        ->putJson(
            route('endpoints.subscriptions.sync', ['endpoint' => $this->endpoint->public_id]),
            ['event_types' => []],
        )
        ->assertOk()
        ->assertJsonPath('subscriptions', []);
});
