<?php

declare(strict_types=1);

use App\Actions\Ingest\DispatchOutbox;
use App\Enums\DeliveryStatus;
use App\Models\Delivery;
use App\Models\DeliveryAttempt;
use App\Models\Tenant;
use App\Support\Delivery\TransportResult;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Queue;

/*
 * The end of the line. A delivery that has used up its attempts is not deleted,
 * not moved and not summarised into another table — it is the same row it has
 * always been, with a status that says it stopped and a reason that says why.
 * That is what makes "inspectable and replayable, never deleted by the delivery
 * path" true by construction: there is nothing for the delivery path to delete.
 */

beforeEach(function (): void {
    config()->set('postbox.retry.max_attempts', 3);

    [$this->tenant, $this->endpoint, $this->delivery] = publishedDelivery();

    pinJitter(0.5);
});

/**
 * Runs the delivery to exhaustion against an endpoint that always answers 500.
 */
function exhaust(Tenant $tenant, Delivery $delivery): void
{
    $attempts = config()->integer('postbox.retry.max_attempts');

    fakeTransport(TransportResult::responded(500, [], 'down', 7), $attempts);

    foreach (range(1, $attempts) as $ignored) {
        attemptDelivery($tenant, $delivery);
    }
}

it('dead-letters a delivery once it runs out of attempts', function (): void {
    exhaust($this->tenant, $this->delivery);

    $delivery = freshDelivery($this->tenant, $this->delivery);

    expect($delivery->status)->toBe(DeliveryStatus::Exhausted)
        ->and($delivery->attempt_count)->toBe(3)
        ->and($delivery->next_attempt_at)->toBeNull()
        ->and($delivery->exhausted_at)->not->toBeNull()
        ->and($delivery->failure_reason)->toBe('exhausted after 3 attempts; last outcome: failed');
});

it('keeps every attempt that led there', function (): void {
    exhaust($this->tenant, $this->delivery);

    $attempts = forTenant($this->tenant, fn () => DeliveryAttempt::query()->orderBy('attempt_number')->get());

    expect($attempts)->toHaveCount(3)
        ->and($attempts->pluck('attempt_number')->all())->toBe([1, 2, 3])
        ->and($attempts->pluck('response_status')->all())->toBe([500, 500, 500]);
});

it('lands in the dead letter queue exactly once, whatever else settles afterwards', function (): void {
    exhaust($this->tenant, $this->delivery);

    $exhaustedAt = freshDelivery($this->tenant, $this->delivery)->exhausted_at;

    // A worker whose lease expired mid-send finishes late and tries to settle a
    // delivery another worker has already given up on. Its write must not land:
    // an exhausted delivery that could be re-exhausted is a delivery that can be
    // dead-lettered twice, and a replay queue with two of the same row in it.
    attemptDelivery($this->tenant, $this->delivery);

    $delivery = freshDelivery($this->tenant, $this->delivery);

    expect($delivery->status)->toBe(DeliveryStatus::Exhausted)
        ->and($delivery->exhausted_at?->timestamp)->toBe($exhaustedAt?->timestamp)
        ->and($delivery->attempt_count)->toBe(3)
        ->and($delivery->failure_reason)->toBe('exhausted after 3 attempts; last outcome: failed');
});

it('never hands a dead-lettered delivery back to the queue', function (): void {
    exhaust($this->tenant, $this->delivery);

    Queue::fake();

    $dispatched = forTenant($this->tenant, fn (): int => app(DispatchOutbox::class)->handle());

    expect($dispatched)->toBe(0);
    Queue::assertNothingPushed();
});

it('never deletes what it gave up on', function (): void {
    exhaust($this->tenant, $this->delivery);

    expect(forTenant($this->tenant, fn (): int => Delivery::query()->deadLettered()->count()))->toBe(1)
        ->and(forTenant($this->tenant, fn (): int => DeliveryAttempt::query()->count()))->toBe(3);
});

it('lists the queue for an operator, one tenant at a time', function (): void {
    exhaust($this->tenant, $this->delivery);

    [$other, , $otherDelivery] = publishedDelivery('Globex');
    exhaust($other, $otherDelivery);

    $delivery = freshDelivery($this->tenant, $this->delivery);
    $stranger = freshDelivery($other, $otherDelivery);

    $this->artisan('postbox:dlq')
        ->expectsOutputToContain($delivery->public_id)
        ->expectsOutputToContain($stranger->public_id)
        ->expectsOutputToContain('dlq: 2 dead-lettered deliveries listed.')
        ->assertSuccessful();

    // Each row was read under its own tenant — the listing is two passes, not
    // one query, because Row Level Security is FORCE and there is no such thing
    // as a query across tenants here.
    expect(forTenant($this->tenant, fn (): int => Delivery::query()->deadLettered()->count()))->toBe(1);
});

it('shows nothing while nothing has been given up on', function (): void {
    $this->artisan('postbox:dlq')
        ->expectsOutputToContain('dlq: 0 dead-lettered deliveries listed.')
        ->assertSuccessful();
});

it('refuses to record an exhausted delivery without the moment it gave up', function (): void {
    forTenant($this->tenant, function (): void {
        Delivery::query()->whereKey($this->delivery->getKey())->update([
            'status' => DeliveryStatus::Exhausted,
            'exhausted_at' => null,
        ]);
    });
})->throws(QueryException::class);

it('accepts a delivery that is pending again only without an exhaustion time', function (): void {
    $now = CarbonImmutable::now();

    forTenant($this->tenant, function () use ($now): void {
        Delivery::query()->whereKey($this->delivery->getKey())->update([
            'status' => DeliveryStatus::Pending,
            'exhausted_at' => $now,
        ]);
    });
})->throws(QueryException::class);
