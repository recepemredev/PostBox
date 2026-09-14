<?php

declare(strict_types=1);

use App\Enums\RoleSlug;
use App\Models\Delivery;
use App\Models\DeliveryAttempt;
use App\Models\Endpoint;
use App\Models\EndpointSecret;
use App\Models\Message;
use App\Models\Replay;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Delivery\TransportResult;
use App\Support\Idempotency\Fingerprint;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
 * Replaying an already-published message: a new obligation against the same
 * message and endpoint, never a change to what already happened. The last
 * test in this file is the one that matters most, for the same reason
 * IdempotentPublishTest's own last test does: it is the race, run against a
 * reservation another session has genuinely committed.
 */

beforeEach(function (): void {
    $this->tenant = tenantNamed('Acme');
    $this->admin = memberOf($this->tenant);
    $this->viewer = memberOf($this->tenant, RoleSlug::Viewer);

    [$this->application, $this->eventType] = registerProducer($this->tenant);

    // $this->first carries a pinned, resolvable URL and an active secret —
    // the same arrangement publishedDelivery() uses — because one test in
    // this file actually attempts a replay delivery. $this->second stays
    // Faker's random, unresolvable default: nothing else here ever sends to
    // it, only counts its rows.
    [$this->first, $this->second] = forTenant($this->tenant, fn (): array => [
        subscribedEndpoint($this->application, $this->eventType, url: 'http://93.184.216.34/webhook'),
        subscribedEndpoint($this->application, $this->eventType),
    ]);

    forTenant($this->tenant, function (): void {
        EndpointSecret::factory()->for($this->first)->create(['secret' => 'whsec_test_secret']);
    });

    $token = issueKeyFor($this->tenant, $this->admin)->token;
    publishEvent(invoicePaid(), token: $token, applicationId: $this->application->public_id)->assertCreated();

    $this->message = forTenant($this->tenant, fn (): Message => Message::query()->sole());
});

/**
 * Replays as an operator would: a session credential, and the message in the
 * path.
 *
 * @param  array<string, mixed>  $body
 * @param  array<string, string>  $headers
 */
function replayMessage(Message $message, array $body = [], array $headers = [], ?User $as = null): TestResponse
{
    /** @var TestCase $test */
    $test = test();

    return $test->actingAs($as ?? $test->admin)
        ->postJson(route('replays.message', ['message' => $message->public_id]), $body, $headers);
}

/**
 * @return Collection<int, Delivery>
 */
function originalDeliveries(Tenant $tenant, Message $message): Collection
{
    return forTenant($tenant, fn (): Collection => Delivery::query()
        ->where('message_id', $message->id)
        ->whereNull('replay_id')
        ->orderBy('endpoint_id')
        ->get());
}

it("opens a new delivery for every endpoint the message's own fan-out reached", function (): void {
    replayMessage($this->message)
        ->assertCreated()
        ->assertJsonPath('message_id', $this->message->public_id)
        ->assertJsonPath('endpoint_id', null)
        ->assertJsonPath('delivery_count', 2);

    $deliveries = forTenant(
        $this->tenant,
        fn (): Collection => Delivery::query()->where('message_id', $this->message->id)->get(),
    );

    expect($deliveries)->toHaveCount(4)
        ->and($deliveries->whereNotNull('replay_id'))->toHaveCount(2)
        ->and($deliveries->whereNotNull('replay_id')->pluck('endpoint_id')->sort()->values()->all())
        ->toBe([$this->first->id, $this->second->id]);
});

it('opens a new delivery for one named endpoint only', function (): void {
    replayMessage($this->message, ['endpoint' => $this->first->public_id])
        ->assertCreated()
        ->assertJsonPath('endpoint_id', $this->first->public_id)
        ->assertJsonPath('delivery_count', 1);

    $deliveries = forTenant(
        $this->tenant,
        fn (): Collection => Delivery::query()->where('message_id', $this->message->id)->get(),
    );

    expect($deliveries)->toHaveCount(3)
        ->and($deliveries->whereNotNull('replay_id')->pluck('endpoint_id')->all())->toBe([$this->first->id]);
});

it('never mutates the original message or either of the original deliveries', function (): void {
    $before = originalDeliveries($this->tenant, $this->message);

    replayMessage($this->message)->assertCreated();

    $after = originalDeliveries($this->tenant, $this->message);
    $message = forTenant($this->tenant, fn (): Message => $this->message->fresh());

    expect($message->payload)->toBe($this->message->payload)
        ->and($after->pluck('status')->all())->toBe($before->pluck('status')->all())
        ->and($after->pluck('attempt_count')->all())->toBe($before->pluck('attempt_count')->all())
        ->and($after->pluck('next_attempt_at')->all())->toEqual($before->pluck('next_attempt_at')->all());
});

it("produces an attempt that is distinguishable from the original delivery's own", function (): void {
    fakeTransport(TransportResult::responded(200, [], 'ok', 5), times: 2);

    replayMessage($this->message, ['endpoint' => $this->first->public_id])->assertCreated();

    [$original, $replay] = forTenant($this->tenant, fn (): array => [
        Delivery::query()->where('message_id', $this->message->id)
            ->where('endpoint_id', $this->first->id)->whereNull('replay_id')->sole(),
        Delivery::query()->where('message_id', $this->message->id)
            ->where('endpoint_id', $this->first->id)->whereNotNull('replay_id')->sole(),
    ]);

    attemptDelivery($this->tenant, $original);
    attemptDelivery($this->tenant, $replay);

    [$originalAttempt, $replayAttempt] = forTenant($this->tenant, fn (): array => [
        DeliveryAttempt::query()->where('delivery_id', $original->id)->sole(),
        DeliveryAttempt::query()->where('delivery_id', $replay->id)->sole(),
    ]);

    // Two distinct delivery rows, each with its own attempt log starting
    // fresh at 1 — a replay is a second obligation, never a continuation of
    // the first one's attempt count.
    expect($original->replay_id)->toBeNull()
        ->and($replay->replay_id)->not->toBeNull()
        ->and($originalAttempt->delivery_id)->not->toBe($replayAttempt->delivery_id)
        ->and($originalAttempt->attempt_number)->toBe(1)
        ->and($replayAttempt->attempt_number)->toBe(1);
});

it('answers 404 when the named endpoint never received the original delivery', function (): void {
    $stranger = forTenant($this->tenant, fn (): Endpoint => Endpoint::factory()->create());

    replayMessage($this->message, ['endpoint' => $stranger->public_id])->assertNotFound();

    expect(forTenant($this->tenant, fn (): int => Delivery::query()->whereNotNull('replay_id')->count()))->toBe(0);
});

it('answers 404 for a message belonging to another tenant', function (): void {
    $other = tenantNamed('Globex');
    $foreign = forTenant($other, fn (): Message => Message::factory()->create());

    replayMessage($foreign)->assertNotFound();
});

it('refuses a viewer, who may read but not replay', function (): void {
    replayMessage($this->message, as: $this->viewer)->assertForbidden();
});

it('is idempotent: the same key returns the same receipt without opening deliveries twice', function (): void {
    $first = replayMessage($this->message, headers: ['Idempotency-Key' => 'recover-1'])->assertCreated();
    $again = replayMessage($this->message, headers: ['Idempotency-Key' => 'recover-1']);

    $again->assertOk()->assertHeader('Idempotent-Replay', 'true');

    expect($again->getContent())->toBe($first->getContent())
        ->and(forTenant($this->tenant, fn (): int => Replay::query()->count()))->toBe(1)
        ->and(forTenant($this->tenant, fn (): int => Delivery::query()->whereNotNull('replay_id')->count()))->toBe(2);
});

it('opens a fresh set of deliveries when no key is supplied, every time', function (): void {
    replayMessage($this->message)->assertCreated();
    replayMessage($this->message)->assertCreated();

    expect(forTenant($this->tenant, fn (): int => Delivery::query()->whereNotNull('replay_id')->count()))->toBe(4);
});

it('refuses a key already spent on a different replay scope', function (): void {
    replayMessage($this->message, ['endpoint' => $this->first->public_id], ['Idempotency-Key' => 'recover-2'])
        ->assertCreated();

    replayMessage($this->message, ['endpoint' => $this->second->public_id], ['Idempotency-Key' => 'recover-2'])
        ->assertConflict();

    expect(forTenant($this->tenant, fn (): int => Delivery::query()->whereNotNull('replay_id')->count()))->toBe(1);
});

it('is rejected by the rate limit exactly like a fresh publish would be', function (): void {
    // A capacity of one token, refilling far slower than this test can run —
    // TokenBucket divides by the refill rate, so it stays positive rather
    // than zero.
    config()->set('postbox.governor.rate_limit.free.capacity', 1);
    config()->set('postbox.governor.rate_limit.free.refill_per_second', 1);

    // A fresh tenant, so the capacity above governs its bucket from the very
    // first spend — the token bucket seeds its own state on first touch, and
    // a tenant reused from beforeEach would already have spent one against
    // the default capacity.
    $tenant = tenantNamed('Throttled');
    $admin = memberOf($tenant);
    [$application, $eventType] = registerProducer($tenant);
    $endpoint = forTenant($tenant, fn (): Endpoint => subscribedEndpoint($application, $eventType));
    $token = issueKeyFor($tenant, $admin)->token;

    // Spends the tenant's only token — the same bucket a replay draws from
    // (modules.md: "Governor sits in front of Ingest and Recovery, not
    // inside them. A replay consumes quota through the same path as a fresh
    // event.").
    publishEvent(invoicePaid(), token: $token, applicationId: $application->public_id)->assertCreated();

    $message = forTenant($tenant, fn (): Message => Message::query()->sole());

    replayMessage($message, ['endpoint' => $endpoint->public_id], as: $admin)->assertStatus(429);
});

it("is rejected once the tenant's quota for the period is exhausted", function (): void {
    config()->set('postbox.governor.quota.free.messages_per_period', 1);

    $tenant = tenantNamed('Capped');
    $admin = memberOf($tenant);
    [$application, $eventType] = registerProducer($tenant);
    $endpoint = forTenant($tenant, fn (): Endpoint => subscribedEndpoint($application, $eventType));
    $token = issueKeyFor($tenant, $admin)->token;

    // Spends the tenant's only message of the period — the same quota a
    // replay draws from.
    publishEvent(invoicePaid(), token: $token, applicationId: $application->public_id)->assertCreated();

    $message = forTenant($tenant, fn (): Message => Message::query()->sole());

    replayMessage($message, ['endpoint' => $endpoint->public_id], as: $admin)->assertStatus(402);
});

/*
 * The race. RefreshDatabase wraps this test in a transaction on the
 * application connection, so a second session cannot normally see anything it
 * sets up. The rival's reservation is therefore committed for real through the
 * schema owner's connection, hung off Replay's own creating() event — which
 * fires at exactly the point this test needs it: the reservation lookup has
 * already missed, and our own INSERT is the very next statement.
 */

function commitRivalReplay(Tenant $tenant, string $key, Message $message, string $fingerprint): void
{
    $rival = DB::connection('pgsql_admin');

    // Row Level Security is FORCE, so the owner is subject to it too: the
    // rival session says which tenant it acts for exactly as the application
    // does.
    $rival->statement('select set_config(?, ?, false)', ['postbox.tenant_id', (string) $tenant->id]);

    $rival->table('replays')->insert([
        'public_id' => 'rpl_'.strtolower((string) Str::ulid()),
        'tenant_id' => $tenant->id,
        'idempotency_key' => $key,
        'request_hash' => $fingerprint,
        'message_id' => $message->id,
        'delivery_count' => 0,
        'created_at' => now(),
    ]);
}

it('returns the winner when two identical replay requests race for the same key', function (): void {
    $tenant = committedTenant('Racer');
    $admin = memberOf($tenant);

    [$application, $eventType] = registerProducer($tenant);
    $endpoint = forTenant($tenant, fn (): Endpoint => subscribedEndpoint($application, $eventType));

    $token = issueKeyFor($tenant, $admin)->token;
    publishEvent(invoicePaid(), token: $token, applicationId: $application->public_id)->assertCreated();

    $message = forTenant($tenant, fn (): Message => Message::query()->sole());

    $fingerprint = Fingerprint::of('message', $message->public_id, '');

    // The rival commits between our read and our write.
    Replay::creating(function () use ($tenant, $message, $fingerprint): void {
        commitRivalReplay($tenant, 'replay-race', $message, $fingerprint->toString());
    });

    replayMessage($message, headers: ['Idempotency-Key' => 'replay-race'], as: $admin)
        ->assertOk()
        ->assertHeader('Idempotent-Replay', 'true');

    forTenant($tenant, function () use ($endpoint): void {
        // Ours went back with the transaction: the rival's reservation is the
        // only replay left, and it opened no deliveries of its own.
        expect(Replay::query()->count())->toBe(1)
            ->and(Delivery::query()->where('endpoint_id', $endpoint->id)->whereNotNull('replay_id')->count())->toBe(0);
    });
});
