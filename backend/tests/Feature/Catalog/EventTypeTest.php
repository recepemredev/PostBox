<?php

declare(strict_types=1);

use App\Models\Endpoint;
use App\Models\EndpointSubscription;
use App\Models\EventType;
use Illuminate\Database\QueryException;

/*
 * An event type's name is its identifier: unique within the tenant, and shaped so
 * it can go into a signed payload and a generated SDK type without surprises. A
 * subscription is a pair that cannot be made twice.
 *
 * The constraint violations abort the surrounding transaction, so each one is the
 * last thing its test does — the shape the row-level-security tests use for the
 * same reason.
 */

beforeEach(function (): void {
    $this->acme = tenantNamed('Acme');
    $this->globex = tenantNamed('Globex');
});

it('rejects a name the format constraint does not allow', function (): void {
    expect(fn () => forTenant($this->acme, fn (): EventType => EventType::factory()->create(['name' => 'Invoice Paid'])))
        ->toThrow(QueryException::class);
});

it('accepts a dotted lowercase name', function (): void {
    $type = forTenant($this->acme, fn (): EventType => EventType::factory()->create(['name' => 'invoice.paid']));

    expect($type->name)->toBe('invoice.paid');
});

it('scopes an event type name to the tenant', function (): void {
    forTenant($this->acme, fn (): EventType => EventType::factory()->create(['name' => 'invoice.paid']));
    $globex = forTenant($this->globex, fn (): EventType => EventType::factory()->create(['name' => 'invoice.paid']));

    expect($globex->name)->toBe('invoice.paid')
        ->and(fn () => forTenant($this->acme, fn (): EventType => EventType::factory()->create(['name' => 'invoice.paid'])))
        ->toThrow(QueryException::class);
});

it('refuses to subscribe an endpoint to the same event type twice', function (): void {
    [$endpoint, $type] = forTenant($this->acme, fn (): array => [
        Endpoint::factory()->create(),
        EventType::factory()->create(),
    ]);

    $subscribe = fn (): EndpointSubscription => EndpointSubscription::factory()->create([
        'endpoint_id' => $endpoint->id,
        'event_type_id' => $type->id,
    ]);

    forTenant($this->acme, $subscribe);

    expect(fn () => forTenant($this->acme, $subscribe))->toThrow(QueryException::class);
});
