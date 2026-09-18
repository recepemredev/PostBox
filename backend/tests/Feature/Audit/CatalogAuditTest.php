<?php

declare(strict_types=1);

use App\Actions\Resilience\TransitionBreaker;
use App\Enums\BreakerState;
use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Endpoint;
use Carbon\CarbonImmutable;

/*
 * CLAUDE.md: "Endpoint and secret changes are written to an immutable audit
 * log." Catalog (Step 13) is the first writer to carry a real actor and
 * address — RecordAuditEntry's other caller, TransitionBreaker, has neither,
 * which the last test here guards against regressing.
 */

beforeEach(function (): void {
    $this->acme = tenantNamed('Acme');
    $this->admin = memberOf($this->acme);

    $this->application = forTenant($this->acme, fn (): Application => Application::factory()->create());
});

it('records who created an endpoint and from where', function (): void {
    $this->actingAs($this->admin)->postJson(
        route('applications.endpoints.store', ['application' => $this->application->public_id]),
        ['name' => 'Primary', 'url' => 'http://93.184.216.34/webhook'],
    )->assertCreated();

    $entry = forTenant($this->acme, fn (): AuditLog => AuditLog::query()->where('action', 'endpoint.created')->sole());

    // toEqual, not toBe: jsonb does not preserve the key order changes was
    // written with (the same lesson D43 records for MessageResource).
    expect($entry->actor_id)->toBe($this->admin->id)
        ->and($entry->entity_type)->toBe('endpoint')
        ->and($entry->ip_address)->not->toBeNull()
        ->and($entry->changes)->toEqual(['name' => 'Primary', 'url' => 'http://93.184.216.34/webhook']);
});

it('records an endpoint update with before and after values', function (): void {
    $endpoint = forTenant(
        $this->acme,
        fn (): Endpoint => Endpoint::factory()->for($this->application)->create(['status' => 'enabled']),
    );

    $this->actingAs($this->admin)
        ->patchJson(route('endpoints.update', ['endpoint' => $endpoint->public_id]), ['status' => 'disabled'])
        ->assertOk();

    $entry = forTenant($this->acme, fn (): AuditLog => AuditLog::query()->where('action', 'endpoint.updated')->sole());

    expect($entry->changes)->toEqual(['before' => ['status' => 'enabled'], 'after' => ['status' => 'disabled']]);
});

it('records issuing and revoking a secret', function (): void {
    $endpoint = forTenant($this->acme, fn (): Endpoint => Endpoint::factory()->for($this->application)->create());

    $issued = $this->actingAs($this->admin)
        ->postJson(route('endpoints.secrets.store', ['endpoint' => $endpoint->public_id]))
        ->json('id');

    $this->actingAs($this->admin)
        ->deleteJson(route('endpoints.secrets.destroy', ['endpoint' => $endpoint->public_id, 'secret' => $issued]))
        ->assertNoContent();

    forTenant($this->acme, function () use ($issued): void {
        $issuedEntry = AuditLog::query()->where('action', 'endpoint_secret.issued')->sole();
        $revokedEntry = AuditLog::query()->where('action', 'endpoint_secret.revoked')->sole();

        expect($issuedEntry->entity_public_id)->toBe($issued)
            ->and($issuedEntry->actor_id)->toBe($this->admin->id)
            ->and($revokedEntry->entity_public_id)->toBe($issued)
            ->and($revokedEntry->actor_id)->toBe($this->admin->id);
    });
});

it('never carries a secret value in its own audit entry', function (): void {
    $endpoint = forTenant($this->acme, fn (): Endpoint => Endpoint::factory()->for($this->application)->create());

    $this->actingAs($this->admin)
        ->postJson(route('endpoints.secrets.store', ['endpoint' => $endpoint->public_id]))
        ->assertCreated();

    $entry = forTenant($this->acme, fn (): AuditLog => AuditLog::query()->where('action', 'endpoint_secret.issued')->sole());

    expect(json_encode($entry->changes))->not->toContain('whsec_');
});

it('still writes a breaker transition with no actor and no address', function (): void {
    $endpoint = forTenant($this->acme, fn (): Endpoint => Endpoint::factory()->for($this->application)->create());

    forTenant($this->acme, function () use ($endpoint): void {
        app(TransitionBreaker::class)->handle($endpoint, BreakerState::Open, CarbonImmutable::now());
    });

    $entry = forTenant($this->acme, fn (): AuditLog => AuditLog::query()->where('action', 'breaker.opened')->sole());

    expect($entry->actor_id)->toBeNull()
        ->and($entry->ip_address)->toBeNull();
});
