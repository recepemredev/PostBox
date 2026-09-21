<?php

declare(strict_types=1);

use App\Enums\RoleSlug;
use App\Models\Delivery;
use App\Models\DeliveryAttempt;
use App\Support\Delivery\AttemptRecord;
use App\Support\Delivery\TransportResult;
use Carbon\CarbonImmutable;

/*
 * The retry timeline's own read (Step 14): one delivery's own attempts, in
 * order, with every scrubbed header exactly as AttemptRecord stored it —
 * redacted, never omitted (design.md), so the inspector can show that
 * scrubbing happened rather than let it look like the header was never
 * sent.
 */

beforeEach(function (): void {
    [$this->tenant, $this->endpoint, $this->delivery] = publishedDelivery();
    $this->admin = memberOf($this->tenant);
    $this->viewer = memberOf($this->tenant, RoleSlug::Viewer);
});

it('lists a delivery\'s own attempts in order', function (): void {
    // Anchored to "now": delivery_attempts is partitioned by month, with only
    // the current month and a short runway ahead of it actually created
    // (MonthlyPartitions::ensure), so a fixed past date has no partition to
    // land in the moment this test is run.
    $moment = CarbonImmutable::now();

    forTenant($this->tenant, function () use ($moment): void {
        DeliveryAttempt::factory()->for($this->delivery)->for($this->endpoint)->create([
            'attempt_number' => 1,
            'created_at' => $moment,
        ]);
        DeliveryAttempt::factory()->for($this->delivery)->for($this->endpoint)->failed()->create([
            'attempt_number' => 2,
            'created_at' => $moment->addMinutes(5),
        ]);
    });

    $response = $this->actingAs($this->admin)
        ->getJson(route('deliveries.attempts.index', ['delivery' => $this->delivery->public_id]))
        ->assertOk();

    expect($response->json('data'))->toHaveCount(2)
        ->and($response->json('data.0.attempt_number'))->toBe(1)
        ->and($response->json('data.1.attempt_number'))->toBe(2);

    expect((string) $response->json('data.0.id'))->toStartWith('att_')
        ->and((string) $response->json('data.0.delivery_id'))->toBe($this->delivery->public_id)
        ->and((string) $response->json('data.0.endpoint_id'))->toBe($this->endpoint->public_id);
});

it('pages a delivery\'s attempts by cursor without a gap or a duplicate, tied instants included', function (): void {
    // Anchored to "now" for the same reason the ordering test above is.
    $moment = CarbonImmutable::now();

    $ids = forTenant($this->tenant, fn (): array => collect(range(1, 3))
        ->map(fn (int $n): string => DeliveryAttempt::factory()
            ->for($this->delivery)
            ->for($this->endpoint)
            ->create(['attempt_number' => $n, 'created_at' => $moment])
            ->public_id)
        ->all());

    $first = $this->actingAs($this->admin)
        ->getJson(route('deliveries.attempts.index', ['delivery' => $this->delivery->public_id, 'limit' => 2]))
        ->assertOk();

    expect($first->json('data'))->toHaveCount(2);
    $cursor = $first->json('meta.next_cursor');
    expect($cursor)->not->toBeNull();

    $second = $this->actingAs($this->admin)
        ->getJson(route('deliveries.attempts.index', [
            'delivery' => $this->delivery->public_id,
            'limit' => 2,
            'cursor' => $cursor,
        ]))
        ->assertOk();

    expect($second->json('data'))->toHaveCount(1)
        ->and($second->json('meta.next_cursor'))->toBeNull();

    $seenIds = collect($first->json('data'))->pluck('id')
        ->merge(collect($second->json('data'))->pluck('id'))
        ->sort()->values()->all();

    expect($seenIds)->toBe(collect($ids)->sort()->values()->all());
});

it('shows a scrubbed header as redacted, never as its real value, through the inspector', function (): void {
    fakeTransport(TransportResult::responded(200, ['Set-Cookie' => 'session=abc'], 'ok', 5));

    attemptDelivery($this->tenant, $this->delivery);

    $response = $this->actingAs($this->admin)
        ->getJson(route('deliveries.attempts.index', ['delivery' => $this->delivery->public_id]))
        ->assertOk();

    $headers = $response->json('data.0.response_headers');

    expect($headers)->toHaveKey('Set-Cookie', AttemptRecord::REDACTED);

    // The real value is nowhere in the response, not only absent under that
    // key — a defect that renamed or re-nested the header would still be
    // caught.
    expect($response->getContent())->not->toContain('session=abc');
});

it('answers 404 rather than 403 for a delivery belonging to another tenant', function (): void {
    $other = tenantNamed('Globex');
    $foreign = forTenant($other, function () use ($other): Delivery {
        [$application, $eventType] = registerProducer($other);
        $endpoint = subscribedEndpoint($application, $eventType);

        return Delivery::factory()->for($endpoint)->create();
    });

    $this->actingAs($this->admin)
        ->getJson(route('deliveries.attempts.index', ['delivery' => $foreign->public_id]))
        ->assertNotFound();
});

it('lets a viewer read the attempt history', function (): void {
    forTenant(
        $this->tenant,
        fn (): DeliveryAttempt => DeliveryAttempt::factory()->for($this->delivery)->for($this->endpoint)->create(),
    );

    $this->actingAs($this->viewer)
        ->getJson(route('deliveries.attempts.index', ['delivery' => $this->delivery->public_id]))
        ->assertOk();
});
