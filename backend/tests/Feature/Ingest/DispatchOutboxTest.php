<?php

declare(strict_types=1);

use App\Enums\DeliveryStatus;
use App\Jobs\SendDelivery;
use App\Models\Delivery;
use App\Models\Endpoint;
use App\Models\Tenant;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;

/*
 * The dispatcher is the half of the outbox that talks to the queue. What is
 * asserted here is the claim: a due delivery goes out once, a leased one is not
 * handed out again while its lease holds, and one whose worker never ran comes
 * back when it expires. That last test is what "a crash between the commit and
 * the enqueue still results in delivery" actually means.
 */

beforeEach(function (): void {
    Queue::fake();

    $this->acme = tenantNamed('Acme');
    $this->globex = tenantNamed('Globex');
    $this->token = issueKeyFor($this->acme, memberOf($this->acme))->token;

    [$this->application, $this->eventType] = registerProducer($this->acme);

    forTenant($this->acme, fn (): Endpoint => subscribedEndpoint($this->application, $this->eventType));
});

function dispatchOutbox(): void
{
    test()->artisan('outbox:dispatch')->assertSuccessful();
}

function onlyDelivery(Tenant $tenant): Delivery
{
    return forTenant($tenant, fn (): Delivery => Delivery::query()->sole());
}

it('hands a due delivery to the queue', function (): void {
    publishEvent(invoicePaid())->assertCreated();

    $delivery = onlyDelivery($this->acme);

    dispatchOutbox();

    Queue::assertPushed(
        SendDelivery::class,
        fn (SendDelivery $job): bool => $job->deliveryId === $delivery->public_id
            && $job->tenantId === $this->acme->public_id
            // The worker-deliveries supervisor is the only one draining this
            // queue (config/horizon.php); a job that landed anywhere else
            // would sit unprocessed rather than merely delayed.
            && $job->queue === 'deliveries',
    );
    Queue::assertPushed(SendDelivery::class, 1);
});

it('leaves a delivery that is not due yet', function (): void {
    publishEvent(invoicePaid())->assertCreated();

    forTenant($this->acme, fn (): bool => Delivery::query()->sole()
        ->update(['next_attempt_at' => now()->addMinutes(5)]));

    dispatchOutbox();

    Queue::assertNothingPushed();
});

it('leaves a delivery that is already settled', function (DeliveryStatus $status): void {
    publishEvent(invoicePaid())->assertCreated();

    // An exhausted delivery carries the moment it gave up, because
    // deliveries_exhausted_shape_check will not hold one without the other.
    $settled = ['status' => $status, 'next_attempt_at' => null]
        + ($status === DeliveryStatus::Exhausted ? ['exhausted_at' => now()] : []);

    forTenant($this->acme, fn (): bool => Delivery::query()->sole()->update($settled));

    dispatchOutbox();

    Queue::assertNothingPushed();
})->with([DeliveryStatus::Succeeded, DeliveryStatus::Exhausted]);

it('does not hand the same delivery out twice while its lease holds', function (): void {
    publishEvent(invoicePaid())->assertCreated();

    dispatchOutbox();
    dispatchOutbox();

    Queue::assertPushed(SendDelivery::class, 1);

    // The claim is the lease, and nothing else about the row moved: the status
    // is still the worker's to change.
    expect(onlyDelivery($this->acme)->status)->toBe(DeliveryStatus::Pending)
        ->and(onlyDelivery($this->acme)->attempt_count)->toBe(0)
        ->and(onlyDelivery($this->acme)->next_attempt_at?->isFuture())->toBeTrue();
});

it('hands a delivery out again once its lease has expired', function (): void {
    publishEvent(invoicePaid())->assertCreated();

    dispatchOutbox();

    // The worker this was handed to never ran. Nothing recorded that, and
    // nothing needs to: the lease runs out and the row is due again.
    $this->travel(Config::integer('postbox.outbox.lease_seconds') + 1)->seconds();

    dispatchOutbox();

    Queue::assertPushed(SendDelivery::class, 2);
});

it('dispatches for every tenant', function (): void {
    publishEvent(invoicePaid())->assertCreated();

    [$application, $eventType] = registerProducer($this->globex);
    $token = issueKeyFor($this->globex, memberOf($this->globex))->token;

    forTenant($this->globex, fn (): Endpoint => subscribedEndpoint($application, $eventType));

    publishEvent(invoicePaid(), token: $token, applicationId: $application->public_id)->assertCreated();

    dispatchOutbox();

    Queue::assertPushed(SendDelivery::class, 2);

    expect(onlyDelivery($this->acme)->next_attempt_at?->isFuture())->toBeTrue()
        ->and(onlyDelivery($this->globex)->next_attempt_at?->isFuture())->toBeTrue();
});

it('dispatches nothing when the outbox is empty', function (): void {
    dispatchOutbox();

    Queue::assertNothingPushed();
});
