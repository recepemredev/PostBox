<?php

declare(strict_types=1);

use App\Enums\RoleSlug;
use App\Models\Application;

/*
 * The HTTP surface over Application: list, create, show, rename. Tenant
 * isolation and the read/manage permission split are the properties worth
 * proving here — the uniqueness constraint itself already has a test at the
 * schema layer (ApplicationTest.php).
 */

beforeEach(function (): void {
    $this->acme = tenantNamed('Acme');
    $this->globex = tenantNamed('Globex');

    $this->admin = memberOf($this->acme);
    $this->viewer = memberOf($this->acme, RoleSlug::Viewer);
    $this->outsider = memberOf($this->globex);
});

it('creates an application and lists it back with its endpoint count', function (): void {
    $response = $this->actingAs($this->admin)
        ->postJson(route('applications.store'), ['name' => 'Billing']);

    $response->assertCreated()
        ->assertJsonPath('name', 'Billing')
        ->assertJsonPath('endpoint_count', 0)
        ->assertJsonStructure(['id', 'name', 'endpoint_count', 'created_at']);

    expect((string) $response->json('id'))->toStartWith('app_');

    $this->actingAs($this->admin)
        ->getJson(route('applications.index'))
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('renames an application', function (): void {
    $application = forTenant($this->acme, fn (): Application => Application::factory()->create(['name' => 'Old Name']));

    $this->actingAs($this->admin)
        ->patchJson(route('applications.update', ['application' => $application->public_id]), ['name' => 'New Name'])
        ->assertOk()
        ->assertJsonPath('name', 'New Name');

    expect(forTenant($this->acme, fn (): string => $application->fresh()->name))->toBe('New Name');
});

it('lets a viewer read applications but not create or rename one', function (): void {
    $application = forTenant($this->acme, fn (): Application => Application::factory()->create());

    $this->actingAs($this->viewer)->getJson(route('applications.index'))->assertOk();

    $this->actingAs($this->viewer)
        ->postJson(route('applications.store'), ['name' => 'Mine'])
        ->assertForbidden();

    $this->actingAs($this->viewer)
        ->patchJson(route('applications.update', ['application' => $application->public_id]), ['name' => 'Renamed'])
        ->assertForbidden();
});

it('answers 404 rather than 403 for an application belonging to another tenant', function (): void {
    $foreign = forTenant($this->globex, fn (): Application => Application::factory()->create());

    $this->actingAs($this->admin)
        ->getJson(route('applications.show', ['application' => $foreign->public_id]))
        ->assertNotFound();

    $this->actingAs($this->admin)
        ->patchJson(route('applications.update', ['application' => $foreign->public_id]), ['name' => 'Stolen'])
        ->assertNotFound();
});

it('refuses every application route without a session', function (): void {
    $this->getJson(route('applications.index'))->assertUnauthorized();
    $this->postJson(route('applications.store'), ['name' => 'x'])->assertUnauthorized();
});
