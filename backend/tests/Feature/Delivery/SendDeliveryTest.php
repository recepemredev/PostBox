<?php

declare(strict_types=1);

use App\Enums\AttemptOutcome;
use App\Enums\DeliveryStatus;
use App\Jobs\SendDelivery;
use App\Models\Delivery;
use App\Models\DeliveryAttempt;
use App\Models\Endpoint;
use App\Models\EndpointSecret;
use App\Models\Tenant;
use App\Support\Delivery\HttpTransport;
use App\Support\Delivery\Signature;
use App\Support\Delivery\TransportResult;
use Carbon\CarbonImmutable;

/*
 * SendDelivery end to end: a real job resolving a real tenant and a real
 * delivery, through a real AttemptDelivery, against a faked HttpTransport —
 * the one seam this whole chain is built around. What is asserted here is
 * the ledger CLAUDE.md promises: every attempt recorded, including the ones
 * that never reach the network, and a failure that leaves the delivery
 * pending rather than inventing a retry schedule Step 6 does not own.
 */

beforeEach(function (): void {
    $this->tenant = tenantNamed('Acme');
    $this->now = CarbonImmutable::now();
    $this->secret = 'whsec_test_secret';

    [$this->application, $this->eventType] = registerProducer($this->tenant);

    // AddressGuard resolves for real in this file — nothing here doubles
    // HostResolver — so the endpoint URL is a literal public IP rather than
    // Faker's random hostname: a literal never touches DNS, which is what
    // keeps every test deterministic and independent of the network.
    $this->endpoint = forTenant(
        $this->tenant,
        fn (): Endpoint => subscribedEndpoint($this->application, $this->eventType, url: 'http://93.184.216.34/webhook'),
    );

    forTenant($this->tenant, function (): void {
        EndpointSecret::factory()->for($this->endpoint)->create(['secret' => $this->secret]);
    });

    $token = issueKeyFor($this->tenant, memberOf($this->tenant))->token;
    publishEvent(invoicePaid(), token: $token, applicationId: $this->application->public_id)->assertCreated();

    $this->delivery = forTenant($this->tenant, fn (): Delivery => Delivery::query()->sole());
});

function attemptDelivery(Tenant $tenant, Delivery $delivery): void
{
    app()->call([new SendDelivery($tenant->public_id, $delivery->public_id), 'handle']);
}

function fakeTransport(TransportResult $result): void
{
    $mock = Mockery::mock(HttpTransport::class);
    $mock->shouldReceive('send')->once()->andReturn($result);

    app()->instance(HttpTransport::class, $mock);
}

function onlyAttempt(Tenant $tenant): DeliveryAttempt
{
    return forTenant($tenant, fn (): DeliveryAttempt => DeliveryAttempt::query()->sole());
}

it('signs and sends a successful delivery, marking it succeeded', function (): void {
    fakeTransport(TransportResult::responded(200, ['Content-Type' => 'application/json'], '{"ok":true}', 42));

    attemptDelivery($this->tenant, $this->delivery);

    $delivery = forTenant($this->tenant, fn (): Delivery => $this->delivery->fresh());
    $attempt = onlyAttempt($this->tenant);

    expect($delivery->status)->toBe(DeliveryStatus::Succeeded)
        ->and($delivery->next_attempt_at)->toBeNull()
        ->and($delivery->last_attempted_at)->not->toBeNull()
        ->and($delivery->attempt_count)->toBe(1)
        ->and($attempt->outcome)->toBe(AttemptOutcome::Succeeded)
        ->and($attempt->attempt_number)->toBe(1)
        ->and($attempt->response_status)->toBe(200)
        ->and($attempt->duration_ms)->toBe(42)
        ->and($attempt->request_headers)->toHaveKeys([
            'Content-Type', 'PostBox-Message-Id', 'PostBox-Event-Type', 'PostBox-Timestamp', 'PostBox-Signature',
        ]);
});

it('signs exactly the bytes it records as the request body', function (): void {
    fakeTransport(TransportResult::responded(200, [], 'ok', 5));

    attemptDelivery($this->tenant, $this->delivery);

    $attempt = onlyAttempt($this->tenant);
    $signature = app(Signature::class);

    $verified = $signature->verify(
        $attempt->request_headers['PostBox-Signature'],
        $attempt->request_body,
        CarbonImmutable::createFromTimestamp((int) $attempt->request_headers['PostBox-Timestamp'], 'UTC'),
        CarbonImmutable::now(),
        [$this->secret],
    );

    expect($verified)->toBeTrue();
});

it('leaves a failed delivery pending, without inventing a retry delay', function (): void {
    $sentinel = $this->now->addMinutes(5);
    forTenant($this->tenant, fn () => $this->delivery->update(['next_attempt_at' => $sentinel]));

    fakeTransport(TransportResult::responded(500, [], '{"error":"server_error"}', 12));

    attemptDelivery($this->tenant, $this->delivery);

    $delivery = forTenant($this->tenant, fn (): Delivery => $this->delivery->fresh());
    $attempt = onlyAttempt($this->tenant);

    expect($delivery->status)->toBe(DeliveryStatus::Pending)
        // The column is second-precision, so the round trip loses the
        // microseconds $sentinel carries in memory — comparing Unix seconds
        // is what "settle() never touches next_attempt_at" actually means.
        ->and($delivery->next_attempt_at?->timestamp)->toBe($sentinel->timestamp)
        ->and($delivery->attempt_count)->toBe(1)
        ->and($attempt->outcome)->toBe(AttemptOutcome::Failed)
        ->and($attempt->response_status)->toBe(500);
});

it('records every transport failure class and leaves the delivery pending', function (AttemptOutcome $failure): void {
    fakeTransport(TransportResult::failed($failure, 'a transport-level failure', 3));

    attemptDelivery($this->tenant, $this->delivery);

    $delivery = forTenant($this->tenant, fn (): Delivery => $this->delivery->fresh());
    $attempt = onlyAttempt($this->tenant);

    expect($delivery->status)->toBe(DeliveryStatus::Pending)
        ->and($attempt->outcome)->toBe($failure)
        ->and($attempt->response_status)->toBeNull()
        ->and($attempt->error_message)->toBe('a transport-level failure');
})->with([
    AttemptOutcome::Timeout,
    AttemptOutcome::DnsError,
    AttemptOutcome::TlsError,
    AttemptOutcome::ConnectionError,
]);

it('refuses to send and records Blocked when the endpoint has no active secret', function (): void {
    forTenant($this->tenant, fn () => $this->endpoint->secrets()->delete());

    $mock = Mockery::mock(HttpTransport::class);
    $mock->shouldReceive('send')->never();
    app()->instance(HttpTransport::class, $mock);

    attemptDelivery($this->tenant, $this->delivery);

    $delivery = forTenant($this->tenant, fn (): Delivery => $this->delivery->fresh());
    $attempt = onlyAttempt($this->tenant);

    expect($delivery->status)->toBe(DeliveryStatus::Pending)
        ->and($attempt->outcome)->toBe(AttemptOutcome::Blocked)
        ->and($attempt->error_message)->toContain('no active signing secret');
});

it('refuses to send and records Blocked when the target address is disallowed', function (): void {
    forTenant($this->tenant, fn () => $this->endpoint->update(['url' => 'http://127.0.0.1/webhook']));

    $mock = Mockery::mock(HttpTransport::class);
    $mock->shouldReceive('send')->never();
    app()->instance(HttpTransport::class, $mock);

    attemptDelivery($this->tenant, $this->delivery);

    $delivery = forTenant($this->tenant, fn (): Delivery => $this->delivery->fresh());
    $attempt = onlyAttempt($this->tenant);

    expect($delivery->status)->toBe(DeliveryStatus::Pending)
        ->and($attempt->outcome)->toBe(AttemptOutcome::Blocked)
        ->and($attempt->error_message)->toContain('disallowed range');
});

it('does not attempt a delivery that has already settled', function (): void {
    forTenant($this->tenant, fn () => $this->delivery->update(['status' => DeliveryStatus::Succeeded]));

    $mock = Mockery::mock(HttpTransport::class);
    $mock->shouldReceive('send')->never();
    app()->instance(HttpTransport::class, $mock);

    attemptDelivery($this->tenant, $this->delivery);

    expect(forTenant($this->tenant, fn (): int => DeliveryAttempt::query()->count()))->toBe(0);
});

it('runs safely twice, sending only once', function (): void {
    fakeTransport(TransportResult::responded(200, [], 'ok', 5));

    attemptDelivery($this->tenant, $this->delivery);
    attemptDelivery($this->tenant, $this->delivery);

    expect(forTenant($this->tenant, fn (): int => DeliveryAttempt::query()->count()))->toBe(1);
});

it('scrubs a sensitive response header before it is stored', function (): void {
    fakeTransport(TransportResult::responded(200, ['Set-Cookie' => 'session=abc'], 'ok', 5));

    attemptDelivery($this->tenant, $this->delivery);

    expect(onlyAttempt($this->tenant)->response_headers)->not->toHaveKey('Set-Cookie');
});

it('caps an oversized response body before it is stored', function (): void {
    $ceiling = config()->integer('postbox.delivery.max_recorded_body_bytes');
    fakeTransport(TransportResult::responded(200, [], str_repeat('a', $ceiling + 100), 5));

    attemptDelivery($this->tenant, $this->delivery);

    expect(strlen((string) onlyAttempt($this->tenant)->response_body))->toBeLessThanOrEqual($ceiling);
});
