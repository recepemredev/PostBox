<?php

declare(strict_types=1);

use App\Models\IdempotencyKey;
use App\Models\Message;
use Illuminate\Support\Facades\Config;

/*
 * Expiry is a deletion. Nothing reads expires_at to decide whether a key still
 * counts, so what this pass does is the whole of what a key's lifetime means:
 * before it, a replay returns the original; after it, the same key publishes
 * again.
 */

beforeEach(function (): void {
    $this->acme = tenantNamed('Acme');
    $this->globex = tenantNamed('Globex');
    $this->token = issueKeyFor($this->acme, memberOf($this->acme))->token;

    [$this->application, $this->eventType] = registerProducer($this->acme);
});

function prune(): void
{
    test()->artisan('idempotency:prune')->assertSuccessful();
}

function ttlHours(): int
{
    return Config::integer('postbox.ingest.idempotency.ttl_hours');
}

it('keeps a reservation that is still within its window', function (): void {
    publishEvent(invoicePaid(), headers: ['Idempotency-Key' => 'req-001'])->assertCreated();

    $this->travel(ttlHours() - 1)->hours();

    prune();

    forTenant($this->acme, fn () => expect(IdempotencyKey::query()->count())->toBe(1));

    publishEvent(invoicePaid(), headers: ['Idempotency-Key' => 'req-001'])
        ->assertOk()
        ->assertHeader('Idempotent-Replay', 'true');
});

it('drops a reservation whose window has passed, and the key publishes again', function (): void {
    publishEvent(invoicePaid(), headers: ['Idempotency-Key' => 'req-002'])->assertCreated();

    $this->travel(ttlHours() + 1)->hours();

    prune();

    forTenant($this->acme, fn () => expect(IdempotencyKey::query()->count())->toBe(0));

    publishEvent(invoicePaid(), headers: ['Idempotency-Key' => 'req-002'])->assertCreated();

    forTenant($this->acme, fn () => expect(Message::query()->count())->toBe(2));
});

it('leaves the message a dropped reservation pointed at', function (): void {
    publishEvent(invoicePaid(), headers: ['Idempotency-Key' => 'req-003'])->assertCreated();

    $original = forTenant($this->acme, fn (): Message => Message::query()->sole());

    $this->travel(ttlHours() + 1)->hours();

    prune();

    forTenant($this->acme, function () use ($original): void {
        expect(Message::query()->count())->toBe(1)
            ->and(Message::query()->sole()->id)->toBe($original->id);
    });
});

it('prunes every tenant', function (): void {
    publishEvent(invoicePaid(), headers: ['Idempotency-Key' => 'shared'])->assertCreated();

    [$application] = registerProducer($this->globex);
    $token = issueKeyFor($this->globex, memberOf($this->globex))->token;

    publishEvent(
        invoicePaid(),
        token: $token,
        applicationId: $application->public_id,
        headers: ['Idempotency-Key' => 'shared'],
    )->assertCreated();

    $this->travel(ttlHours() + 1)->hours();

    prune();

    forTenant($this->acme, fn () => expect(IdempotencyKey::query()->count())->toBe(0));
    forTenant($this->globex, fn () => expect(IdempotencyKey::query()->count())->toBe(0));
});
