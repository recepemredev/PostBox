<?php

declare(strict_types=1);

use App\Actions\Resilience\TransitionBreaker;
use App\Enums\BreakerState;
use App\Enums\DeliveryStatus;
use App\Jobs\SendDelivery;
use App\Models\Delivery;
use App\Models\DeliveryAttempt;
use App\Models\Endpoint;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;

/*
 * DispatchOutbox asks an endpoint's breaker once per pass, before anything
 * reaches a worker. What is asserted here is the claim: an open breaker holds
 * every claimed delivery back without spending an attempt on any of them, a
 * half-open one lets through exactly one, and a message claimed while the
 * breaker is open is never lost — only deferred to whenever the breaker says
 * to try again.
 */

beforeEach(function (): void {
    Queue::fake();

    config()->set('postbox.breaker.open_seconds', 60);
    config()->set('postbox.breaker.probe_timeout_seconds', 300);

    $this->tenant = tenantNamed('Acme');
    $this->token = issueKeyFor($this->tenant, memberOf($this->tenant))->token;
    [$this->application, $this->eventType] = registerProducer($this->tenant);

    $this->endpoint = forTenant(
        $this->tenant,
        fn (): Endpoint => subscribedEndpoint($this->application, $this->eventType),
    );
});

function publishDueDelivery(Tenant $tenant, string $token, string $applicationId): void
{
    publishEvent(invoicePaid(), token: $token, applicationId: $applicationId)->assertCreated();
}

/**
 * @return Collection<int, Delivery>
 */
function deliveriesFor(Tenant $tenant, Endpoint $endpoint): Collection
{
    return forTenant($tenant, fn (): Collection => Delivery::query()->where('endpoint_id', $endpoint->id)->get());
}

it('holds every claimed delivery back while the breaker is open, without spending an attempt', function (): void {
    breakerAt($this->tenant, $this->endpoint, BreakerState::Open);

    publishDueDelivery($this->tenant, $this->token, $this->application->public_id);
    publishDueDelivery($this->tenant, $this->token, $this->application->public_id);

    dispatchOutbox();

    Queue::assertNothingPushed();

    $deliveries = deliveriesFor($this->tenant, $this->endpoint);

    expect($deliveries)->toHaveCount(2);

    foreach ($deliveries as $delivery) {
        expect($delivery->status)->toBe(DeliveryStatus::Pending)
            ->and($delivery->attempt_count)->toBe(0)
            ->and($delivery->next_attempt_at?->isFuture())->toBeTrue();
    }

    forTenant($this->tenant, fn () => expect(DeliveryAttempt::query()->count())->toBe(0));
});

it('admits exactly one probe once the open duration has elapsed, and defers the rest', function (): void {
    breakerAt($this->tenant, $this->endpoint, BreakerState::Open);

    publishDueDelivery($this->tenant, $this->token, $this->application->public_id);
    publishDueDelivery($this->tenant, $this->token, $this->application->public_id);

    $this->travel(61)->seconds();

    dispatchOutbox();

    Queue::assertPushed(SendDelivery::class, 1);

    expect(endpointBreaker($this->tenant, $this->endpoint)?->state)->toBe(BreakerState::HalfOpen);

    /** @var SendDelivery $probeJob */
    $probeJob = Queue::pushed(SendDelivery::class)->sole();

    $deferred = deliveriesFor($this->tenant, $this->endpoint)
        ->reject(fn (Delivery $delivery): bool => $delivery->public_id === $probeJob->deliveryId)
        ->sole();

    expect($deferred->status)->toBe(DeliveryStatus::Pending)
        ->and($deferred->attempt_count)->toBe(0)
        ->and($deferred->next_attempt_at?->isFuture())->toBeTrue();
});

it('does not admit a second probe from a later pass while the first is still outstanding', function (): void {
    breakerAt($this->tenant, $this->endpoint, BreakerState::Open);
    publishDueDelivery($this->tenant, $this->token, $this->application->public_id);

    $this->travel(61)->seconds();

    dispatchOutbox();

    Queue::assertPushed(SendDelivery::class, 1);

    // A second delivery becomes due while the first pass's probe is still
    // within its timeout. The claim itself does not care about the breaker —
    // this delivery is due and skip-locked would happily hand it out — so
    // admitting nothing here is the breaker doing its job, not the claim's.
    publishDueDelivery($this->tenant, $this->token, $this->application->public_id);

    dispatchOutbox();

    Queue::assertPushed(SendDelivery::class, 1);
});

it('admits a fresh probe once the outstanding one has timed out', function (): void {
    breakerAt($this->tenant, $this->endpoint, BreakerState::Open);
    publishDueDelivery($this->tenant, $this->token, $this->application->public_id);

    $this->travel(61)->seconds();
    dispatchOutbox();
    Queue::assertPushed(SendDelivery::class, 1);

    // The worker holding the first probe never ran — Queue::fake() guarantees
    // that here — so once the probe's own timeout passes, a later pass is
    // free to admit a fresh one rather than waiting on it forever.
    publishDueDelivery($this->tenant, $this->token, $this->application->public_id);
    $this->travel(Config::integer('postbox.breaker.probe_timeout_seconds') + 1)->seconds();

    dispatchOutbox();

    Queue::assertPushed(SendDelivery::class, 2);
});

it('admits everything again once a probe has closed the breaker', function (): void {
    breakerAt($this->tenant, $this->endpoint, BreakerState::HalfOpen);

    forTenant(
        $this->tenant,
        fn (): bool => app(TransitionBreaker::class)->handle($this->endpoint, BreakerState::Closed, CarbonImmutable::now()),
    );

    publishDueDelivery($this->tenant, $this->token, $this->application->public_id);
    publishDueDelivery($this->tenant, $this->token, $this->application->public_id);

    dispatchOutbox();

    Queue::assertPushed(SendDelivery::class, 2);
});

it('does not hold back deliveries for a different, healthy endpoint', function (): void {
    breakerAt($this->tenant, $this->endpoint, BreakerState::Open);

    $healthy = forTenant(
        $this->tenant,
        fn (): Endpoint => subscribedEndpoint($this->application, $this->eventType),
    );

    // One publish fans out to both endpoints — the open one and the healthy
    // one — so this is the same batch, not a second event.
    publishDueDelivery($this->tenant, $this->token, $this->application->public_id);

    dispatchOutbox();

    Queue::assertPushed(SendDelivery::class, 1);

    $pushed = Queue::pushed(SendDelivery::class)->sole();
    $healthyDelivery = forTenant(
        $this->tenant,
        fn (): Delivery => Delivery::query()->where('endpoint_id', $healthy->id)->sole(),
    );

    expect($pushed->deliveryId)->toBe($healthyDelivery->public_id);
});
