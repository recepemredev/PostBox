<?php

declare(strict_types=1);

use App\Enums\RoleSlug;
use App\Models\ApiKey;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    $this->acme = tenantNamed('Acme');
    $this->globex = tenantNamed('Globex');

    $this->admin = memberOf($this->acme);
    $this->viewer = memberOf($this->acme, RoleSlug::Viewer);
    $this->outsider = memberOf($this->globex);
});

it('issues a key and shows the secret exactly once', function (): void {
    $response = $this->actingAs($this->admin)
        ->postJson(route('api-keys.store'), ['name' => 'production ingest']);

    $response->assertCreated()
        ->assertJsonPath('name', 'production ingest')
        ->assertJsonStructure(['id', 'name', 'last_four', 'token']);

    $token = (string) $response->json('token');

    expect($token)->toMatch('/^pbk_[0-9a-z]{26}_[A-Za-z0-9]{43}$/')
        ->and($response->json('last_four'))->toBe(substr($token, -4));

    $stored = forTenant($this->acme, fn (): ApiKey => ApiKey::query()->firstOrFail());

    expect($stored->token_hash)->toBe(hash('sha256', $token));

    $this->actingAs($this->admin)
        ->getJson(route('api-keys.index'))
        ->assertOk()
        ->assertJsonMissingPath('data.0.token');
});

it('records who issued the key and when it expires', function (): void {
    $expiry = CarbonImmutable::now()->addMonth()->startOfSecond();

    $this->actingAs($this->admin)
        ->postJson(route('api-keys.store'), [
            'name' => 'temporary',
            'expires_at' => $expiry->toIso8601String(),
        ])
        ->assertCreated()
        ->assertJsonPath('expires_at', $expiry->utc()->toIso8601ZuluString());

    $stored = forTenant($this->acme, fn (): ApiKey => ApiKey::query()->firstOrFail());

    expect($stored->created_by)->toBe($this->admin->id);
});

it('refuses an expiry that has already passed', function (): void {
    $this->actingAs($this->admin)
        ->postJson(route('api-keys.store'), [
            'name' => 'stale',
            'expires_at' => CarbonImmutable::now()->subDay()->toIso8601String(),
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrorFor('expires_at');
});

it('lets a viewer read the keys but not create one', function (): void {
    $this->actingAs($this->admin)->postJson(route('api-keys.store'), ['name' => 'production'])->assertCreated();

    $this->actingAs($this->viewer)->getJson(route('api-keys.index'))->assertOk()->assertJsonCount(1, 'data');

    $this->actingAs($this->viewer)
        ->postJson(route('api-keys.store'), ['name' => 'mine'])
        ->assertForbidden();
});

it('lists only the keys of the tenant the request is acting for', function (): void {
    issueKeyFor($this->acme, $this->admin);
    issueKeyFor($this->globex, $this->outsider);
    issueKeyFor($this->globex, $this->outsider);

    $this->actingAs($this->admin)
        ->getJson(route('api-keys.index'))
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->actingAs($this->outsider)
        ->getJson(route('api-keys.index'))
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('revokes a key without deleting the row behind it', function (): void {
    $issued = issueKeyFor($this->acme, $this->admin);

    $this->actingAs($this->admin)
        ->deleteJson(route('api-keys.destroy', ['apiKey' => $issued->key->public_id]))
        ->assertNoContent();

    $key = forTenant($this->acme, fn (): ApiKey => ApiKey::query()->firstOrFail());

    expect($key->revoked_at)->not->toBeNull()
        ->and(forTenant($this->acme, fn (): int => ApiKey::query()->count()))->toBe(1);
});

it('answers 404 rather than 403 for a key belonging to another tenant', function (): void {
    $foreign = issueKeyFor($this->globex, $this->outsider);

    $this->actingAs($this->admin)
        ->deleteJson(route('api-keys.destroy', ['apiKey' => $foreign->key->public_id]))
        ->assertNotFound();

    expect(forTenant($this->globex, fn () => ApiKey::query()->firstOrFail()->revoked_at))->toBeNull();
});

it('refuses every key route without a session', function (): void {
    $this->getJson(route('api-keys.index'))->assertUnauthorized();
    $this->postJson(route('api-keys.store'), ['name' => 'x'])->assertUnauthorized();
});
