<?php

declare(strict_types=1);

use App\Enums\DeliveryStatus;
use App\Models\Delivery;
use App\Models\Endpoint;
use App\Models\IdempotencyKey;
use App\Models\Message;
use App\Models\Tenant;
use App\Support\Ingest\RequestFingerprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
 * A replay returns the original message and produces nothing — no second
 * message, no second delivery, no delivery of a message already delivered. The
 * last test in this file is the one that matters most: it is the race, run
 * against a reservation another session has genuinely committed.
 */

beforeEach(function (): void {
    $this->acme = tenantNamed('Acme');
    $this->globex = tenantNamed('Globex');
    $this->token = issueKeyFor($this->acme, memberOf($this->acme))->token;

    [$this->application, $this->eventType] = registerProducer($this->acme);
});

function publishWithKey(string $key, ?array $body = null): TestResponse
{
    return publishEvent($body ?? invoicePaid(), headers: ['Idempotency-Key' => $key]);
}

it('returns the original message and writes nothing on a replay', function (): void {
    forTenant($this->acme, fn (): Endpoint => subscribedEndpoint($this->application, $this->eventType));

    $first = publishWithKey('req-001')->assertCreated();
    $replay = publishWithKey('req-001');

    $replay->assertOk()->assertHeader('Idempotent-Replay', 'true');

    // Byte for byte, not merely equivalent. Comparing the decoded bodies would
    // accept two spellings of the same document, which is exactly the mistake
    // that let a reordered payload through until it was found by hand.
    expect($replay->getContent())->toBe($first->getContent());

    forTenant($this->acme, function (): void {
        expect(Message::query()->count())->toBe(1)
            ->and(Delivery::query()->count())->toBe(1)
            ->and(IdempotencyKey::query()->count())->toBe(1);
    });
});

it('does not redeliver a message whose delivery already succeeded', function (): void {
    forTenant($this->acme, fn (): Endpoint => subscribedEndpoint($this->application, $this->eventType));

    publishWithKey('req-002')->assertCreated();

    $delivered = forTenant($this->acme, function (): Delivery {
        $delivery = Delivery::query()->sole();
        $delivery->update(['status' => DeliveryStatus::Succeeded, 'next_attempt_at' => null]);

        return $delivery->fresh();
    });

    publishWithKey('req-002')->assertOk();

    forTenant($this->acme, function () use ($delivered): void {
        expect(Delivery::query()->count())->toBe(1)
            ->and(Delivery::query()->sole()->status)->toBe(DeliveryStatus::Succeeded)
            ->and(Delivery::query()->sole()->id)->toBe($delivered->id);
    });
});

it('publishes twice when no key is supplied', function (): void {
    publishEvent(invoicePaid())->assertCreated();
    publishEvent(invoicePaid())->assertCreated();

    forTenant($this->acme, fn () => expect(Message::query()->count())->toBe(2));
});

it('refuses a key that was spent on a different body', function (): void {
    publishWithKey('req-003')->assertCreated();

    publishWithKey('req-003', ['event_type' => 'invoice.paid', 'payload' => ['total' => 9900]])
        ->assertConflict();

    forTenant($this->acme, fn () => expect(Message::query()->count())->toBe(1));
});

it('refuses a key that was spent on a different event type', function (): void {
    registerProducer($this->acme, 'invoice.voided');

    publishWithKey('req-004')->assertCreated();

    publishWithKey('req-004', ['event_type' => 'invoice.voided', 'payload' => ['total' => 4200]])
        ->assertConflict();
});

it('keeps one tenant key from touching another tenant', function (): void {
    publishWithKey('shared-key')->assertCreated();

    [$application] = registerProducer($this->globex);
    $token = issueKeyFor($this->globex, memberOf($this->globex))->token;

    publishEvent(
        invoicePaid(),
        token: $token,
        applicationId: $application->public_id,
        headers: ['Idempotency-Key' => 'shared-key'],
    )->assertCreated();

    forTenant($this->acme, fn () => expect(Message::query()->count())->toBe(1));
    forTenant($this->globex, fn () => expect(Message::query()->count())->toBe(1));
});

it('rejects a key carrying characters that do not belong in a log line', function (): void {
    publishWithKey("req-005\nX-Injected: yes")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('idempotency_key');
});

/*
 * The race.
 *
 * RefreshDatabase wraps each test in a transaction on the application
 * connection, so a second session cannot normally see anything the test set up.
 * Two rows are therefore committed for real through the owner's connection —
 * the tenant, and the rival's reservation — and removed once the test's own
 * transaction has rolled back and released the locks that reference them. That
 * is the whole trick, and it buys the one branch nothing else can reach: the
 * reservation read misses, and by the time the write lands, someone else has
 * taken the key.
 */

function committedTenant(string $name): Tenant
{
    $tenant = Tenant::factory()->connection('pgsql_admin')->create(['name' => $name]);

    /** @var TestCase $test */
    $test = test();

    // After RefreshDatabase's own rollback, which was registered first. Every
    // row the test wrote for this tenant is gone by then; the rival's
    // reservation goes with the tenant, through the cascade.
    $test->beforeApplicationDestroyed(function () use ($tenant): void {
        DB::connection('pgsql_admin')->table('tenants')->where('id', $tenant->id)->delete();
    });

    return $tenant;
}

function commitRivalReservation(Tenant $tenant, string $key, Message $message, string $fingerprint): void
{
    $rival = DB::connection('pgsql_admin');

    // Row Level Security is FORCE, so the owner is subject to it too: the rival
    // session says which tenant it acts for exactly as the application does.
    $rival->statement('select set_config(?, ?, false)', ['postbox.tenant_id', (string) $tenant->id]);

    $rival->table('idempotency_keys')->insert([
        'tenant_id' => $tenant->id,
        'key' => $key,
        'request_hash' => $fingerprint,
        'message_id' => $message->id,
        'created_at' => now(),
        'expires_at' => now()->addDay(),
    ]);
}

it('returns the winner when two identical requests race for the same key', function (): void {
    $tenant = committedTenant('Racer');
    $token = issueKeyFor($tenant, memberOf($tenant))->token;
    [$application, $eventType] = registerProducer($tenant);

    $rivalMessage = forTenant($tenant, function () use ($application, $eventType): Message {
        subscribedEndpoint($application, $eventType);

        return Message::factory()->create([
            'application_id' => $application->id,
            'event_type_id' => $eventType->id,
            'payload' => ['total' => 4200],
        ]);
    });

    $fingerprint = RequestFingerprint::of($application, 'invoice.paid', ['total' => 4200]);

    // The rival commits between our read and our write. Hanging it off the
    // message being created puts it exactly there: the reservation lookup has
    // already missed, and our own reservation is the next statement.
    Message::created(function () use ($tenant, $rivalMessage, $fingerprint): void {
        commitRivalReservation($tenant, 'race-key', $rivalMessage, $fingerprint->toString());
    });

    publishEvent(
        invoicePaid(),
        token: $token,
        applicationId: $application->public_id,
        headers: ['Idempotency-Key' => 'race-key'],
    )
        ->assertOk()
        ->assertHeader('Idempotent-Replay', 'true')
        ->assertJsonPath('id', $rivalMessage->public_id);

    forTenant($tenant, function () use ($rivalMessage): void {
        // Ours went back with the transaction: the rival's message is the only
        // one left, and it opened no deliveries of its own.
        expect(Message::query()->count())->toBe(1)
            ->and(Message::query()->sole()->id)->toBe($rivalMessage->id)
            ->and(Delivery::query()->count())->toBe(0);
    });
});
