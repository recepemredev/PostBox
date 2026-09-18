<?php

declare(strict_types=1);

use App\Enums\EndpointStatus;
use App\Enums\RoleSlug;
use App\Models\Application;
use App\Models\Endpoint;

/*
 * The HTTP surface over Endpoint: nested creation under an application,
 * show, update. CLAUDE.md: "Endpoint URLs are validated against private,
 * loopback and link-local ranges before every request, not only at
 * registration" — the registration half is what this file proves; the
 * per-request half already has AddressGuardTest.
 */

beforeEach(function (): void {
    $this->acme = tenantNamed('Acme');
    $this->globex = tenantNamed('Globex');

    $this->admin = memberOf($this->acme);
    $this->viewer = memberOf($this->acme, RoleSlug::Viewer);

    $this->application = forTenant($this->acme, fn (): Application => Application::factory()->create());
});

it('creates an endpoint under an application', function (): void {
    $response = $this->actingAs($this->admin)->postJson(
        route('applications.endpoints.store', ['application' => $this->application->public_id]),
        ['name' => 'Primary', 'url' => 'http://93.184.216.34/webhook'],
    );

    $response->assertCreated()
        ->assertJsonPath('name', 'Primary')
        ->assertJsonPath('url', 'http://93.184.216.34/webhook')
        ->assertJsonPath('status', 'enabled')
        ->assertJsonPath('subscriptions', [])
        ->assertJsonPath('breaker', null)
        ->assertJsonPath('active_secret_count', 0)
        ->assertJsonPath('application_id', $this->application->public_id);

    expect((string) $response->json('id'))->toStartWith('ep_');
});

it('refuses an endpoint URL that resolves to a disallowed range', function (): void {
    $this->actingAs($this->admin)
        ->postJson(
            route('applications.endpoints.store', ['application' => $this->application->public_id]),
            ['name' => 'Internal', 'url' => 'http://10.0.0.5/hook'],
        )
        ->assertUnprocessable()
        ->assertJsonValidationErrorFor('url');

    expect(forTenant($this->acme, fn (): int => Endpoint::query()->count()))->toBe(0);
});

it('updates an endpoint partially, leaving unmentioned fields alone', function (): void {
    $endpoint = forTenant(
        $this->acme,
        fn (): Endpoint => Endpoint::factory()->for($this->application)->create([
            'name' => 'Primary',
            'url' => 'http://93.184.216.34/webhook',
        ]),
    );

    $this->actingAs($this->admin)
        ->patchJson(route('endpoints.update', ['endpoint' => $endpoint->public_id]), ['status' => 'disabled'])
        ->assertOk()
        ->assertJsonPath('status', 'disabled')
        ->assertJsonPath('name', 'Primary')
        ->assertJsonPath('url', 'http://93.184.216.34/webhook');

    expect(forTenant($this->acme, fn (): EndpointStatus => $endpoint->fresh()->status))
        ->toBe(EndpointStatus::Disabled);
});

it('lets a viewer read endpoints but not create or update one', function (): void {
    $endpoint = forTenant($this->acme, fn (): Endpoint => Endpoint::factory()->for($this->application)->create());

    $this->actingAs($this->viewer)
        ->getJson(route('applications.endpoints.index', ['application' => $this->application->public_id]))
        ->assertOk();

    $this->actingAs($this->viewer)
        ->postJson(
            route('applications.endpoints.store', ['application' => $this->application->public_id]),
            ['name' => 'x', 'url' => 'http://93.184.216.34/webhook'],
        )
        ->assertForbidden();

    $this->actingAs($this->viewer)
        ->patchJson(route('endpoints.update', ['endpoint' => $endpoint->public_id]), ['status' => 'disabled'])
        ->assertForbidden();
});

it('answers 404 rather than 403 for an endpoint belonging to another tenant', function (): void {
    $foreignApplication = forTenant($this->globex, fn (): Application => Application::factory()->create());
    $foreign = forTenant($this->globex, fn (): Endpoint => Endpoint::factory()->for($foreignApplication)->create());

    $this->actingAs($this->admin)
        ->getJson(route('endpoints.show', ['endpoint' => $foreign->public_id]))
        ->assertNotFound();

    $this->actingAs($this->admin)
        ->postJson(
            route('applications.endpoints.store', ['application' => $foreignApplication->public_id]),
            ['name' => 'x', 'url' => 'http://93.184.216.34/webhook'],
        )
        ->assertNotFound();
});
