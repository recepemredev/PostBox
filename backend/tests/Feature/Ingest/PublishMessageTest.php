<?php

declare(strict_types=1);

use App\Enums\DeliveryStatus;
use App\Models\Application;
use App\Models\Delivery;
use App\Models\Endpoint;
use App\Models\EventType;
use App\Models\Message;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

/*
 * The ingest endpoint, up to but not including idempotency. What is being
 * asserted here is the outbox: one transaction produces the message and every
 * delivery it fans out to, and the request path never touches the queue.
 */

beforeEach(function (): void {
    $this->acme = tenantNamed('Acme');
    $this->globex = tenantNamed('Globex');
    $this->token = issueKeyFor($this->acme, memberOf($this->acme))->token;

    [$this->application, $this->eventType] = registerProducer($this->acme);
});

it('opens one delivery per subscribed and enabled endpoint', function (): void {
    forTenant($this->acme, function (): void {
        $this->first = subscribedEndpoint($this->application, $this->eventType);
        $this->second = subscribedEndpoint($this->application, $this->eventType);

        // Switched off by the operator.
        subscribedEndpoint($this->application, $this->eventType, enabled: false);

        // Subscribed, but to a different event.
        subscribedEndpoint($this->application, EventType::factory()->create(['name' => 'invoice.voided']));

        // Subscribed to this event, but under another application.
        subscribedEndpoint(Application::factory()->create(), $this->eventType);
    });

    publishEvent(invoicePaid())->assertCreated();

    forTenant($this->acme, function (): void {
        $deliveries = Delivery::query()->orderBy('endpoint_id')->get();

        expect($deliveries)->toHaveCount(2)
            ->and($deliveries->pluck('endpoint_id')->all())->toBe([$this->first->id, $this->second->id])
            ->and($deliveries->pluck('status')->unique()->all())->toBe([DeliveryStatus::Pending])
            ->and($deliveries->pluck('attempt_count')->unique()->all())->toBe([0])
            ->and($deliveries->whereNull('next_attempt_at'))->toBeEmpty();
    });
});

it('accepts an event nobody is subscribed to and opens no deliveries', function (): void {
    publishEvent(invoicePaid())->assertCreated();

    forTenant($this->acme, function (): void {
        expect(Message::query()->count())->toBe(1)
            ->and(Delivery::query()->count())->toBe(0);
    });
});

it('never enqueues from the request path', function (): void {
    Queue::fake();

    forTenant($this->acme, fn (): Endpoint => subscribedEndpoint($this->application, $this->eventType));

    publishEvent(invoicePaid())->assertCreated();

    Queue::assertNothingPushed();
});

it('writes the message and its deliveries in one transaction', function (): void {
    forTenant($this->acme, fn (): Endpoint => subscribedEndpoint($this->application, $this->eventType));

    Delivery::creating(function (): void {
        throw new RuntimeException('the delivery could not be written');
    });

    $this->withoutExceptionHandling();

    expect(fn (): TestResponse => publishEvent(invoicePaid()))->toThrow(RuntimeException::class);

    forTenant($this->acme, function (): void {
        expect(Message::query()->count())->toBe(0)
            ->and(Delivery::query()->count())->toBe(0);
    });
});

it('returns a receipt, and nothing about the payload or the deliveries', function (): void {
    $response = publishEvent(invoicePaid());

    $message = forTenant($this->acme, fn (): Message => Message::query()->sole());

    $response->assertCreated()->assertExactJson([
        'id' => $message->public_id,
        'event_type' => 'invoice.paid',
        'created_at' => $message->created_at->utc()->toIso8601ZuluString(),
    ]);

    expect($message->public_id)->toStartWith('msg_')
        ->and($message->payload)->toBe(['total' => 4200]);
});

it('rejects an event type that is not registered', function (): void {
    publishEvent(['event_type' => 'invoice.refunded', 'payload' => []])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('event_type');

    forTenant($this->acme, fn () => expect(Message::query()->count())->toBe(0));
});

it('cannot publish an event type registered by another tenant', function (): void {
    forTenant($this->globex, fn (): EventType => EventType::factory()->create(['name' => 'refund.issued']));

    publishEvent(['event_type' => 'refund.issued', 'payload' => []])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('event_type');
});

it('rejects a payload over the advertised ceiling', function (): void {
    publishEvent(['event_type' => 'invoice.paid', 'payload' => ['blob' => str_repeat('a', 262144)]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('payload');
});

it('rejects a request with no payload at all', function (): void {
    publishEvent(['event_type' => 'invoice.paid'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('payload');
});

it('answers 404 for an application belonging to another tenant', function (): void {
    $elsewhere = forTenant($this->globex, fn (): Application => Application::factory()->create());

    publishEvent(invoicePaid(), applicationId: $elsewhere->public_id)->assertNotFound();
});

it('rejects a request without a valid api key', function (): void {
    publishEvent(invoicePaid(), token: 'pbk_nonsense_nonsense')->assertUnauthorized();
});
