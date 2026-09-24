<?php

declare(strict_types=1);

use App\Actions\Resilience\TransitionBreaker;
use App\Enums\BreakerState;
use App\Enums\DeliveryStatus;
use App\Models\Endpoint;
use App\Support\Delivery\TransportResult;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/*
 * One log line per state change on the delivery path, carrying only public
 * ids and outcomes — never a payload, a header, a URL or a secret (CLAUDE.md:
 * "sensitive data never reaches logs"). An exact with() match on the whole
 * context array is what proves the second half: any of those fields sneaking
 * in would fail the match, not slip past a partial assertion.
 */

it('logs a recorded attempt with public ids and outcome, never the payload or headers', function (): void {
    Log::spy();
    [$tenant, $endpoint, $delivery] = publishedDelivery();
    fakeTransport(TransportResult::responded(200, ['Set-Cookie' => 'session=abc'], '{"ok":true}', 42));

    attemptDelivery($tenant, $delivery);

    $attempt = onlyAttempt($tenant);

    Log::shouldHaveReceived('info')->with('delivery.attempt_recorded', [
        'attempt_id' => $attempt->public_id,
        'delivery_id' => $delivery->public_id,
        'endpoint_id' => $endpoint->public_id,
        'outcome' => 'succeeded',
        'response_status' => 200,
        'duration_ms' => 42,
    ])->once();
});

it('logs a dead-lettered delivery with the reason but never the payload', function (): void {
    Log::spy();
    [$tenant, $endpoint, $delivery] = publishedDelivery();
    $maxAttempts = config()->integer('postbox.retry.max_attempts');
    forTenant($tenant, fn () => $delivery->update(['attempt_count' => $maxAttempts - 1]));
    fakeTransport(TransportResult::responded(500, [], '{"error":"down"}', 9));

    attemptDelivery($tenant, $delivery);

    $reloaded = freshDelivery($tenant, $delivery);
    expect($reloaded->status)->toBe(DeliveryStatus::Exhausted);

    Log::shouldHaveReceived('info')->with('delivery.dead_lettered', [
        'delivery_id' => $delivery->public_id,
        'endpoint_id' => $endpoint->public_id,
        'attempt_count' => $maxAttempts,
        'reason' => $reloaded->failure_reason,
    ])->once();
});

it('logs a breaker transition with the endpoint id and both states', function (): void {
    Log::spy();
    $tenant = tenantNamed('Acme');
    $endpoint = forTenant($tenant, fn (): Endpoint => Endpoint::factory()->create());

    forTenant(
        $tenant,
        fn (): bool => app(TransitionBreaker::class)->handle($endpoint, BreakerState::Open, CarbonImmutable::now()),
    );

    Log::shouldHaveReceived('info')->with('breaker.transitioned', [
        'endpoint_id' => $endpoint->public_id,
        'from' => 'closed',
        'to' => 'open',
    ])->once();
});

it('logs the outbox dispatch count, but only when something was actually dispatched', function (): void {
    Log::spy();
    [$tenant] = publishedDelivery();

    dispatchOutbox();

    Log::shouldHaveReceived('info')->with('outbox.dispatched', [
        'tenant_id' => $tenant->public_id,
        'count' => 1,
    ])->once();
});

it('does not log an outbox pass that dispatched nothing', function (): void {
    Log::spy();
    tenantNamed('Empty Tenant');

    dispatchOutbox();

    Log::shouldNotHaveReceived('info');
});
