<?php

declare(strict_types=1);

use App\Enums\RoleSlug;
use App\Models\Application;
use App\Models\Endpoint;
use App\Models\EndpointSecret;
use Carbon\CarbonImmutable;

/*
 * The HTTP surface over EndpointSecret: issuing is how an endpoint rotates
 * (App\Actions\Catalog\IssueEndpointSecret), and nothing but the creation
 * response ever carries the plaintext.
 */

beforeEach(function (): void {
    $this->acme = tenantNamed('Acme');
    $this->globex = tenantNamed('Globex');

    $this->admin = memberOf($this->acme);
    $this->viewer = memberOf($this->acme, RoleSlug::Viewer);

    [$this->application, $this->endpoint] = forTenant($this->acme, function (): array {
        $application = Application::factory()->create();

        return [$application, Endpoint::factory()->for($application)->create()];
    });
});

it('issues a secret and shows the plaintext exactly once', function (): void {
    $response = $this->actingAs($this->admin)
        ->postJson(route('endpoints.secrets.store', ['endpoint' => $this->endpoint->public_id]));

    $response->assertCreated()->assertJsonStructure(['id', 'last_four', 'secret', 'created_at']);

    $secret = (string) $response->json('secret');

    expect($secret)->toStartWith('whsec_')
        ->and($response->json('last_four'))->toBe(substr($secret, -4));

    $stored = forTenant($this->acme, fn (): EndpointSecret => EndpointSecret::query()->firstOrFail());
    expect($stored->secret)->toBe($secret);

    $this->actingAs($this->admin)
        ->getJson(route('endpoints.secrets.index', ['endpoint' => $this->endpoint->public_id]))
        ->assertOk()
        ->assertJsonMissingPath('data.0.secret');
});

it('rotates: a second secret retires the first with an overlap rather than revoking it outright', function (): void {
    $this->actingAs($this->admin)
        ->postJson(route('endpoints.secrets.store', ['endpoint' => $this->endpoint->public_id]))
        ->assertCreated();

    $this->actingAs($this->admin)
        ->postJson(route('endpoints.secrets.store', ['endpoint' => $this->endpoint->public_id]))
        ->assertCreated();

    expect(forTenant($this->acme, fn (): int => $this->endpoint->secrets()->current()->count()))->toBe(2);

    $this->travelTo(CarbonImmutable::now()->addHours(
        config('postbox.secrets.rotation_overlap_hours') + 1,
    ));

    expect(forTenant($this->acme, fn (): int => $this->endpoint->secrets()->current()->count()))->toBe(1);
});

it('revokes a secret without deleting the row, and a second revoke changes nothing', function (): void {
    $issued = $this->actingAs($this->admin)
        ->postJson(route('endpoints.secrets.store', ['endpoint' => $this->endpoint->public_id]))
        ->json('id');

    $this->actingAs($this->admin)
        ->deleteJson(route('endpoints.secrets.destroy', ['endpoint' => $this->endpoint->public_id, 'secret' => $issued]))
        ->assertNoContent();

    $secret = forTenant($this->acme, fn (): EndpointSecret => EndpointSecret::query()->firstOrFail());
    $revokedAt = $secret->revoked_at;

    expect($revokedAt)->not->toBeNull();

    $this->travelTo(CarbonImmutable::now()->addMinute());

    $this->actingAs($this->admin)
        ->deleteJson(route('endpoints.secrets.destroy', ['endpoint' => $this->endpoint->public_id, 'secret' => $issued]))
        ->assertNoContent();

    expect(forTenant($this->acme, fn (): EndpointSecret => $secret->fresh())->revoked_at)
        ->toEqual($revokedAt)
        ->and(forTenant($this->acme, fn (): int => EndpointSecret::query()->count()))->toBe(1);
});

it('answers 404 for a secret that does not belong to the named endpoint', function (): void {
    $otherEndpoint = forTenant($this->acme, fn (): Endpoint => Endpoint::factory()->for($this->application)->create());

    $secret = forTenant($this->acme, fn (): EndpointSecret => EndpointSecret::factory()->for($otherEndpoint)->create());

    $this->actingAs($this->admin)
        ->deleteJson(route('endpoints.secrets.destroy', ['endpoint' => $this->endpoint->public_id, 'secret' => $secret->public_id]))
        ->assertNotFound();

    expect(forTenant($this->acme, fn (): EndpointSecret => $secret->fresh())->revoked_at)->toBeNull();
});

it('answers 404 rather than 403 for an endpoint belonging to another tenant', function (): void {
    $foreignApplication = forTenant($this->globex, fn (): Application => Application::factory()->create());
    $foreign = forTenant($this->globex, fn (): Endpoint => Endpoint::factory()->for($foreignApplication)->create());

    $this->actingAs($this->admin)
        ->postJson(route('endpoints.secrets.store', ['endpoint' => $foreign->public_id]))
        ->assertNotFound();
});

it('lets a viewer read secret metadata but not issue or revoke one', function (): void {
    $secret = forTenant($this->acme, fn (): EndpointSecret => EndpointSecret::factory()->for($this->endpoint)->create());

    $this->actingAs($this->viewer)
        ->getJson(route('endpoints.secrets.index', ['endpoint' => $this->endpoint->public_id]))
        ->assertOk();

    $this->actingAs($this->viewer)
        ->postJson(route('endpoints.secrets.store', ['endpoint' => $this->endpoint->public_id]))
        ->assertForbidden();

    $this->actingAs($this->viewer)
        ->deleteJson(route('endpoints.secrets.destroy', ['endpoint' => $this->endpoint->public_id, 'secret' => $secret->public_id]))
        ->assertForbidden();
});
