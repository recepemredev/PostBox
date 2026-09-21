<?php

declare(strict_types=1);

use App\Enums\DeliveryStatus;
use App\Enums\MessageSource;
use App\Enums\RoleSlug;
use App\Models\Application;
use App\Models\Delivery;
use App\Models\Endpoint;
use App\Models\EventType;
use App\Models\Message;
use App\Models\Replay;
use Carbon\CarbonImmutable;

/*
 * The message list and detail (Step 14): filtered, cursor-paginated, and —
 * for the detail view — every delivery a message ever opened, original and
 * replayed alike (D78). Ledger's own read permission (ledger.read) is
 * granted to both roles; only Recovery's own write actions stay admin-only.
 */

beforeEach(function (): void {
    $this->acme = tenantNamed('Acme');
    $this->globex = tenantNamed('Globex');

    $this->admin = memberOf($this->acme);
    $this->viewer = memberOf($this->acme, RoleSlug::Viewer);

    [$this->application, $this->eventType] = registerProducer($this->acme);

    $this->endpoint = forTenant(
        $this->acme,
        fn (): Endpoint => subscribedEndpoint($this->application, $this->eventType),
    );
});

/**
 * A message with one delivery to $endpoint in the given status.
 */
function messageWithDelivery(
    Application $application,
    EventType $eventType,
    Endpoint $endpoint,
    DeliveryStatus $status = DeliveryStatus::Pending,
    ?CarbonImmutable $at = null,
): Message {
    $message = Message::factory()->create([
        'application_id' => $application->id,
        'event_type_id' => $eventType->id,
        ...($at !== null ? ['created_at' => $at] : []),
    ]);

    $factory = Delivery::factory()->for($message)->for($endpoint);

    (match ($status) {
        DeliveryStatus::Succeeded => $factory->succeeded(),
        DeliveryStatus::Exhausted => $factory->exhausted(),
        DeliveryStatus::Pending => $factory,
    })->create();

    return $message;
}

it('lists a tenant\'s messages, newest last, with a delivery summary per row', function (): void {
    forTenant($this->acme, fn (): Message => messageWithDelivery(
        $this->application,
        $this->eventType,
        $this->endpoint,
        DeliveryStatus::Succeeded,
    ));

    forTenant($this->globex, function (): void {
        $tenant = $this->globex;
        [$application, $eventType] = registerProducer($tenant);
        $endpoint = subscribedEndpoint($application, $eventType);
        messageWithDelivery($application, $eventType, $endpoint);
    });

    $response = $this->actingAs($this->admin)->getJson(route('messages.index'))->assertOk();

    expect($response->json('data'))->toHaveCount(1);
    $response->assertJsonPath('data.0.deliveries', ['total' => 1, 'succeeded' => 1, 'pending' => 0, 'exhausted' => 0])
        ->assertJsonPath('data.0.event_type', $this->eventType->name)
        ->assertJsonPath('data.0.application_id', $this->application->public_id);

    expect((string) $response->json('data.0.id'))->toStartWith('msg_');
});

it('filters the list by endpoint', function (): void {
    $other = forTenant($this->acme, fn (): Endpoint => subscribedEndpoint($this->application, $this->eventType));

    $matching = forTenant(
        $this->acme,
        fn (): Message => messageWithDelivery($this->application, $this->eventType, $this->endpoint),
    );
    forTenant($this->acme, fn (): Message => messageWithDelivery($this->application, $this->eventType, $other));

    $response = $this->actingAs($this->admin)
        ->getJson(route('messages.index', ['endpoint' => $this->endpoint->public_id]))
        ->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.id'))->toBe($matching->public_id);
});

it('filters the list by event type', function (): void {
    $otherType = forTenant($this->acme, fn (): EventType => EventType::factory()->create(['name' => 'invoice.void']));
    $otherEndpoint = forTenant(
        $this->acme,
        fn (): Endpoint => subscribedEndpoint($this->application, $otherType),
    );

    $matching = forTenant(
        $this->acme,
        fn (): Message => messageWithDelivery($this->application, $this->eventType, $this->endpoint),
    );
    forTenant($this->acme, fn (): Message => messageWithDelivery($this->application, $otherType, $otherEndpoint));

    $response = $this->actingAs($this->admin)
        ->getJson(route('messages.index', ['event_type' => $this->eventType->name]))
        ->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.id'))->toBe($matching->public_id);
});

it('filters the list by delivery status', function (): void {
    $exhausted = forTenant(
        $this->acme,
        fn (): Message => messageWithDelivery($this->application, $this->eventType, $this->endpoint, DeliveryStatus::Exhausted),
    );
    forTenant(
        $this->acme,
        fn (): Message => messageWithDelivery($this->application, $this->eventType, $this->endpoint, DeliveryStatus::Succeeded),
    );

    $response = $this->actingAs($this->admin)
        ->getJson(route('messages.index', ['status' => 'exhausted']))
        ->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.id'))->toBe($exhausted->public_id);
});

it('filters the list by a time range', function (): void {
    // Anchored to "now" rather than a fixed date: messages is partitioned by
    // month, with only the current month and a short runway ahead of it
    // actually created (MonthlyPartitions::ensure), so a fixed past date has
    // no partition to land in the moment this test is run.
    $inRange = CarbonImmutable::now();

    $matching = forTenant($this->acme, fn (): Message => messageWithDelivery(
        $this->application,
        $this->eventType,
        $this->endpoint,
        at: $inRange,
    ));
    forTenant($this->acme, fn (): Message => messageWithDelivery(
        $this->application,
        $this->eventType,
        $this->endpoint,
        at: $inRange->addDays(10),
    ));

    $response = $this->actingAs($this->admin)
        ->getJson(route('messages.index', [
            'from' => $inRange->subHour()->toIso8601String(),
            'to' => $inRange->addHour()->toIso8601String(),
        ]))
        ->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.id'))->toBe($matching->public_id);
});

it('pages the list by cursor without a gap or a duplicate, tied instants included', function (): void {
    // Anchored to "now" for the same reason the time-range test is.
    $moment = CarbonImmutable::now();

    $ids = forTenant($this->acme, fn (): array => collect(range(1, 3))
        ->map(fn (): string => messageWithDelivery($this->application, $this->eventType, $this->endpoint, at: $moment)->public_id)
        ->all());

    $first = $this->actingAs($this->admin)
        ->getJson(route('messages.index', ['limit' => 2]))
        ->assertOk();

    expect($first->json('data'))->toHaveCount(2);
    $cursor = $first->json('meta.next_cursor');
    expect($cursor)->not->toBeNull();

    $second = $this->actingAs($this->admin)
        ->getJson(route('messages.index', ['limit' => 2, 'cursor' => $cursor]))
        ->assertOk();

    expect($second->json('data'))->toHaveCount(1)
        ->and($second->json('meta.next_cursor'))->toBeNull();

    $seenIds = collect($first->json('data'))->pluck('id')
        ->merge(collect($second->json('data'))->pluck('id'))
        ->sort()->values()->all();

    expect($seenIds)->toBe(collect($ids)->sort()->values()->all());
});

it('marks a dashboard test message so it never passes for producer traffic (D76)', function (): void {
    forTenant($this->acme, fn (): Message => Message::factory()->create([
        'application_id' => $this->application->id,
        'event_type_id' => $this->eventType->id,
        'source' => MessageSource::DashboardTest,
    ]));

    $this->actingAs($this->admin)
        ->getJson(route('messages.index'))
        ->assertOk()
        ->assertJsonPath('data.0.source', 'dashboard_test');
});

it('shows every delivery a message opened in its detail view, original and replayed alike', function (): void {
    $message = forTenant(
        $this->acme,
        fn (): Message => messageWithDelivery($this->application, $this->eventType, $this->endpoint, DeliveryStatus::Exhausted),
    );

    forTenant($this->acme, function () use ($message): void {
        $replay = Replay::factory()->forMessageAndEndpoint()->create([
            'message_id' => $message->id,
            'endpoint_id' => $this->endpoint->id,
        ]);

        Delivery::factory()->create([
            'message_id' => $message->id,
            'endpoint_id' => $this->endpoint->id,
            'replay_id' => $replay->id,
        ]);
    });

    $response = $this->actingAs($this->admin)
        ->getJson(route('messages.show', ['message' => $message->public_id]))
        ->assertOk();

    expect($response->json('deliveries'))->toHaveCount(2)
        ->and($response->json('payload'))->toBe($message->payload);

    $replayIds = collect($response->json('deliveries'))->pluck('replay_id');
    expect($replayIds->filter()->count())->toBe(1)
        ->and($replayIds->filter(fn ($id) => $id === null)->count())->toBe(1);
});

it('answers 404 rather than 403 for a message belonging to another tenant', function (): void {
    $foreign = forTenant($this->globex, function (): Message {
        $tenant = $this->globex;
        [$application, $eventType] = registerProducer($tenant);
        $endpoint = subscribedEndpoint($application, $eventType);

        return messageWithDelivery($application, $eventType, $endpoint);
    });

    $this->actingAs($this->admin)
        ->getJson(route('messages.show', ['message' => $foreign->public_id]))
        ->assertNotFound();
});

it('lets a viewer read both the list and the detail view', function (): void {
    $message = forTenant(
        $this->acme,
        fn (): Message => messageWithDelivery($this->application, $this->eventType, $this->endpoint),
    );

    $this->actingAs($this->viewer)->getJson(route('messages.index'))->assertOk();

    $this->actingAs($this->viewer)
        ->getJson(route('messages.show', ['message' => $message->public_id]))
        ->assertOk();
});
