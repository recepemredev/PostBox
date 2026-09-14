<?php

declare(strict_types=1);

use App\Enums\RoleSlug;
use App\Models\Delivery;
use App\Models\Endpoint;
use App\Models\Message;
use App\Models\Replay;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
 * Replaying an endpoint's own exhausted deliveries over a bounded window:
 * the dead letter queue an outage left behind, recovered without touching
 * the database directly, and without one call ever holding a transaction
 * open for an unbounded backlog.
 */

beforeEach(function (): void {
    $this->tenant = tenantNamed('Acme');
    $this->admin = memberOf($this->tenant);
    $this->viewer = memberOf($this->tenant, RoleSlug::Viewer);

    [$this->application, $this->eventType] = registerProducer($this->tenant);

    $this->endpoint = forTenant(
        $this->tenant,
        fn (): Endpoint => subscribedEndpoint($this->application, $this->eventType),
    );

    $this->windowStart = CarbonImmutable::parse('2026-01-01 00:00:00');
    $this->windowEnd = CarbonImmutable::parse('2026-01-02 00:00:00');
});

/**
 * @param  array<string, mixed>  $body
 * @param  array<string, string>  $headers
 */
function replayRange(Endpoint $endpoint, array $body, array $headers = [], ?User $as = null): TestResponse
{
    /** @var TestCase $test */
    $test = test();

    return $test->actingAs($as ?? $test->admin)
        ->postJson(route('replays.range', ['endpoint' => $endpoint->public_id]), $body, $headers);
}

/**
 * An exhausted delivery pinned to a moment, against a fresh message — a
 * fresh message because deliveries_original_fanout_unique allows only one
 * original per (message, endpoint) pair.
 */
function exhaustedDeliveryAt(Tenant $tenant, Endpoint $endpoint, CarbonImmutable $moment): Delivery
{
    return forTenant($tenant, function () use ($endpoint, $moment): Delivery {
        $message = Message::factory()->create();

        return Delivery::factory()->exhausted()->create([
            'message_id' => $message->id,
            'endpoint_id' => $endpoint->id,
            'exhausted_at' => $moment,
        ]);
    });
}

it('replays only the exhausted deliveries inside the window', function (): void {
    $before = exhaustedDeliveryAt($this->tenant, $this->endpoint, $this->windowStart->subHour());
    $inside = exhaustedDeliveryAt($this->tenant, $this->endpoint, $this->windowStart->addHour());
    $after = exhaustedDeliveryAt($this->tenant, $this->endpoint, $this->windowEnd->addHour());

    // Still trying on its own retry schedule — never exhausted, so never a
    // candidate regardless of when it last attempted.
    forTenant($this->tenant, fn (): Delivery => Delivery::factory()->create([
        'message_id' => Message::factory()->create()->id,
        'endpoint_id' => $this->endpoint->id,
    ]));

    replayRange($this->endpoint, [
        'from' => $this->windowStart->toIso8601String(),
        'to' => $this->windowEnd->toIso8601String(),
    ])->assertCreated()->assertJsonPath('delivery_count', 1);

    $replayed = forTenant(
        $this->tenant,
        fn (): Delivery => Delivery::query()->whereNotNull('replay_id')->sole(),
    );

    expect($replayed->message_id)->toBe($inside->message_id)
        ->and($replayed->message_id)->not->toBe($before->message_id)
        ->and($replayed->message_id)->not->toBe($after->message_id);
});

it('answers 200 with nothing recovered when the window is genuinely empty, not a 404', function (): void {
    replayRange($this->endpoint, [
        'from' => $this->windowStart->toIso8601String(),
        'to' => $this->windowEnd->toIso8601String(),
    ])->assertCreated()->assertJsonPath('delivery_count', 0);
});

it('is bounded to the configured batch size and hands back a cursor for the rest', function (): void {
    config()->set('postbox.replay.max_deliveries_per_request', 2);

    $first = exhaustedDeliveryAt($this->tenant, $this->endpoint, $this->windowStart->addMinutes(1));
    $second = exhaustedDeliveryAt($this->tenant, $this->endpoint, $this->windowStart->addMinutes(2));
    $third = exhaustedDeliveryAt($this->tenant, $this->endpoint, $this->windowStart->addMinutes(3));

    $firstPage = replayRange($this->endpoint, [
        'from' => $this->windowStart->toIso8601String(),
        'to' => $this->windowEnd->toIso8601String(),
    ])->assertCreated();

    $firstPage->assertJsonPath('delivery_count', 2);
    $cursor = $firstPage->json('next_cursor');
    expect($cursor)->not->toBeNull();

    $secondPage = replayRange($this->endpoint, [
        'from' => $this->windowStart->toIso8601String(),
        'to' => $this->windowEnd->toIso8601String(),
        'cursor' => $cursor,
    ])->assertCreated();

    $secondPage->assertJsonPath('delivery_count', 1)->assertJsonPath('next_cursor', null);

    // Every original exactly once — no gap, no overlap across the two pages.
    $replayedMessageIds = forTenant(
        $this->tenant,
        fn (): array => Delivery::query()->whereNotNull('replay_id')->pluck('message_id')->sort()->values()->all(),
    );

    expect($replayedMessageIds)->toBe(collect([$first->message_id, $second->message_id, $third->message_id])->sort()->values()->all());
});

it('breaks a tie on id when two deliveries exhaust in the same second', function (): void {
    config()->set('postbox.replay.max_deliveries_per_request', 1);

    $moment = $this->windowStart->addMinutes(5);
    $first = exhaustedDeliveryAt($this->tenant, $this->endpoint, $moment);
    $second = exhaustedDeliveryAt($this->tenant, $this->endpoint, $moment);

    $firstPage = replayRange($this->endpoint, [
        'from' => $this->windowStart->toIso8601String(),
        'to' => $this->windowEnd->toIso8601String(),
    ])->assertCreated();

    $firstReplayed = forTenant(
        $this->tenant,
        fn (): Delivery => Delivery::query()->whereNotNull('replay_id')->sole(),
    );

    expect($firstReplayed->message_id)->toBe($first->message_id);

    replayRange($this->endpoint, [
        'from' => $this->windowStart->toIso8601String(),
        'to' => $this->windowEnd->toIso8601String(),
        'cursor' => $firstPage->json('next_cursor'),
    ])->assertCreated()->assertJsonPath('delivery_count', 1);

    $secondReplayed = forTenant($this->tenant, fn (): Delivery => Delivery::query()
        ->whereNotNull('replay_id')
        ->where('message_id', $second->message_id)
        ->sole());

    expect($secondReplayed->message_id)->toBe($second->message_id);
});

it('rejects a cursor that is not exactly what this endpoint ever produced', function (): void {
    replayRange($this->endpoint, [
        'from' => $this->windowStart->toIso8601String(),
        'to' => $this->windowEnd->toIso8601String(),
        'cursor' => 'not-a-real-cursor',
    ])->assertUnprocessable()->assertJsonValidationErrors('cursor');
});

it('answers 404 for an endpoint belonging to another tenant', function (): void {
    $other = tenantNamed('Globex');
    $foreign = forTenant($other, fn (): Endpoint => Endpoint::factory()->create());

    replayRange($foreign, [
        'from' => $this->windowStart->toIso8601String(),
        'to' => $this->windowEnd->toIso8601String(),
    ])->assertNotFound();
});

it('refuses a viewer, who may read but not replay', function (): void {
    replayRange($this->endpoint, [
        'from' => $this->windowStart->toIso8601String(),
        'to' => $this->windowEnd->toIso8601String(),
    ], as: $this->viewer)->assertForbidden();
});

it('rejects a range whose end does not come after its start', function (): void {
    replayRange($this->endpoint, [
        'from' => $this->windowEnd->toIso8601String(),
        'to' => $this->windowStart->toIso8601String(),
    ])->assertUnprocessable()->assertJsonValidationErrors('to');
});

it('is idempotent: the same key returns the same receipt without opening deliveries twice', function (): void {
    exhaustedDeliveryAt($this->tenant, $this->endpoint, $this->windowStart->addHour());

    $body = ['from' => $this->windowStart->toIso8601String(), 'to' => $this->windowEnd->toIso8601String()];

    $first = replayRange($this->endpoint, $body, ['Idempotency-Key' => 'recover-range-1'])->assertCreated();
    $again = replayRange($this->endpoint, $body, ['Idempotency-Key' => 'recover-range-1']);

    $again->assertOk()->assertHeader('Idempotent-Replay', 'true');

    expect($again->getContent())->toBe($first->getContent())
        ->and(forTenant($this->tenant, fn (): int => Replay::query()->count()))->toBe(1)
        ->and(forTenant($this->tenant, fn (): int => Delivery::query()->whereNotNull('replay_id')->count()))->toBe(1);
});

it('refuses a key already spent on a different window', function (): void {
    exhaustedDeliveryAt($this->tenant, $this->endpoint, $this->windowStart->addHour());

    replayRange($this->endpoint, [
        'from' => $this->windowStart->toIso8601String(),
        'to' => $this->windowEnd->toIso8601String(),
    ], ['Idempotency-Key' => 'recover-range-2'])->assertCreated();

    replayRange($this->endpoint, [
        'from' => $this->windowStart->toIso8601String(),
        'to' => $this->windowEnd->addDay()->toIso8601String(),
    ], ['Idempotency-Key' => 'recover-range-2'])->assertConflict();
});
