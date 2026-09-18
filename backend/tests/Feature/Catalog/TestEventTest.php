<?php

declare(strict_types=1);

use App\Enums\DeliveryStatus;
use App\Enums\RoleSlug;
use App\Models\Delivery;
use App\Models\Endpoint;
use App\Models\EventType;
use App\Models\Message;

/*
 * D76: not a second delivery path. A test event is authorized like any
 * publish, is counted against quota, and is distinguishable from production
 * traffic in the message list — the three properties the roadmap names.
 */

beforeEach(function (): void {
    $this->acme = tenantNamed('Acme');
    $this->globex = tenantNamed('Globex');

    $this->admin = memberOf($this->acme);
    $this->viewer = memberOf($this->acme, RoleSlug::Viewer);

    [$this->application, $this->eventType] = registerProducer($this->acme);

    $this->endpoint = forTenant(
        $this->acme,
        fn (): Endpoint => subscribedEndpoint($this->application, $this->eventType, url: 'http://93.184.216.34/webhook'),
    );
});

it('sends a test event and opens one delivery, marked distinctly from a producer publish', function (): void {
    $response = $this->actingAs($this->admin)->postJson(
        route('endpoints.test-events.store', ['endpoint' => $this->endpoint->public_id]),
        ['event_type' => $this->eventType->name],
    );

    $response->assertCreated()
        ->assertJsonPath('message.source', 'dashboard_test')
        ->assertJsonStructure(['message' => ['id', 'event_type', 'source', 'created_at'], 'delivery_id', 'delivery_status']);

    forTenant($this->acme, function (): void {
        $message = Message::query()->sole();

        expect($message->source->value)->toBe('dashboard_test');

        $delivery = Delivery::query()->sole();
        expect($delivery->endpoint_id)->toBe($this->endpoint->id)
            ->and($delivery->status)->toBe(DeliveryStatus::Pending);
    });
});

it('opens a delivery only for the targeted endpoint, not its siblings', function (): void {
    $sibling = forTenant(
        $this->acme,
        fn (): Endpoint => subscribedEndpoint($this->application, $this->eventType, url: 'http://93.184.216.34/webhook'),
    );

    $this->actingAs($this->admin)->postJson(
        route('endpoints.test-events.store', ['endpoint' => $this->endpoint->public_id]),
        ['event_type' => $this->eventType->name],
    )->assertCreated();

    forTenant($this->acme, function () use ($sibling): void {
        expect(Delivery::query()->count())->toBe(1)
            ->and(Delivery::query()->sole()->endpoint_id)->not->toBe($sibling->id);
    });
});

it('rejects an event type the endpoint is not subscribed to', function (): void {
    forTenant($this->acme, fn (): EventType => EventType::factory()->create(['name' => 'invoice.voided']));

    $this->actingAs($this->admin)->postJson(
        route('endpoints.test-events.store', ['endpoint' => $this->endpoint->public_id]),
        ['event_type' => 'invoice.voided'],
    )->assertUnprocessable()->assertJsonValidationErrorFor('event_type');

    forTenant($this->acme, fn () => expect(Message::query()->count())->toBe(0));
});

it('spends a rate-limit token and a quota unit like any publish', function (): void {
    config(['postbox.governor.quota.free.messages_per_period' => 5]);

    $this->actingAs($this->admin)->postJson(
        route('endpoints.test-events.store', ['endpoint' => $this->endpoint->public_id]),
        ['event_type' => $this->eventType->name],
    )
        ->assertCreated()
        ->assertHeader('Quota-Remaining', '4');
});

it('lets a viewer see nothing here: sending a test event needs its own permission', function (): void {
    $this->actingAs($this->viewer)->postJson(
        route('endpoints.test-events.store', ['endpoint' => $this->endpoint->public_id]),
        ['event_type' => $this->eventType->name],
    )->assertForbidden();
});

it('answers 404 rather than 403 for an endpoint belonging to another tenant', function (): void {
    [$foreignApplication, $foreignEventType] = registerProducer($this->globex);

    $foreign = forTenant(
        $this->globex,
        fn (): Endpoint => subscribedEndpoint($foreignApplication, $foreignEventType, url: 'http://93.184.216.34/webhook'),
    );

    $this->actingAs($this->admin)->postJson(
        route('endpoints.test-events.store', ['endpoint' => $foreign->public_id]),
        ['event_type' => $foreignEventType->name],
    )->assertNotFound();
});
