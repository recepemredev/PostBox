<?php

declare(strict_types=1);

use App\Enums\AttemptOutcome;
use App\Enums\DeliveryStatus;
use App\Models\Delivery;
use App\Models\DeliveryAttempt;
use App\Support\Delivery\Signature;
use App\Support\Delivery\TransportResult;
use Carbon\CarbonImmutable;

/*
 * SendDelivery end to end: a real job resolving a real tenant and a real
 * delivery, through a real AttemptDelivery, against a faked HttpTransport —
 * the one seam this whole chain is built around. What is asserted here is
 * the ledger CLAUDE.md promises: every attempt recorded, including the ones
 * that never reach the network. What the schedule between those attempts is
 * belongs to the Resilience group, not here.
 */

beforeEach(function (): void {
    [$this->tenant, $this->endpoint, $this->delivery, $this->secret] = publishedDelivery();

    // Half the delay fixed, half spread — pinning the spread at its midpoint
    // makes every delay in this file a single number.
    pinJitter(0.5);
});

it('signs and sends a successful delivery, marking it succeeded', function (): void {
    fakeTransport(TransportResult::responded(200, ['Content-Type' => 'application/json'], '{"ok":true}', 42));

    attemptDelivery($this->tenant, $this->delivery);

    $delivery = freshDelivery($this->tenant, $this->delivery);
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

it('leaves a retryable failure pending, due again on the schedule the policy chose', function (): void {
    // The dispatcher's lease is already in the future when a worker picks a
    // delivery up. Pinning it far out is what proves the new time came from
    // the retry policy rather than from the lease being left alone.
    $lease = CarbonImmutable::now()->addHours(4);
    forTenant($this->tenant, fn () => $this->delivery->update(['next_attempt_at' => $lease]));

    fakeTransport(TransportResult::responded(500, [], '{"error":"server_error"}', 12));

    attemptDelivery($this->tenant, $this->delivery);

    $delivery = freshDelivery($this->tenant, $this->delivery);
    $attempt = onlyAttempt($this->tenant);

    // That the new time came from the policy is what this file asserts; what
    // the policy's numbers actually are is RetryScheduleTest's, and asserting
    // the sequence in both places would be two definitions of one schedule.
    expect($delivery->status)->toBe(DeliveryStatus::Pending)
        ->and($delivery->exhausted_at)->toBeNull()
        ->and($delivery->next_attempt_at?->lessThan($lease))->toBeTrue()
        ->and($delivery->next_attempt_at?->greaterThanOrEqualTo($delivery->last_attempted_at))->toBeTrue()
        ->and($delivery->attempt_count)->toBe(1)
        ->and($attempt->outcome)->toBe(AttemptOutcome::Failed)
        ->and($attempt->response_status)->toBe(500);
});

it('records every transport failure class and leaves the delivery pending', function (AttemptOutcome $failure): void {
    fakeTransport(TransportResult::failed($failure, 'a transport-level failure', 3));

    attemptDelivery($this->tenant, $this->delivery);

    $delivery = freshDelivery($this->tenant, $this->delivery);
    $attempt = onlyAttempt($this->tenant);

    expect($delivery->status)->toBe(DeliveryStatus::Pending)
        ->and($delivery->next_attempt_at)->not->toBeNull()
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

    refusingTransport();

    attemptDelivery($this->tenant, $this->delivery);

    $delivery = freshDelivery($this->tenant, $this->delivery);
    $attempt = onlyAttempt($this->tenant);

    expect($delivery->status)->toBe(DeliveryStatus::Pending)
        ->and($attempt->outcome)->toBe(AttemptOutcome::Blocked)
        ->and($attempt->error_message)->toContain('no active signing secret');
});

it('refuses to send and records Blocked when the target address is disallowed', function (): void {
    forTenant($this->tenant, fn () => $this->endpoint->update(['url' => 'http://127.0.0.1/webhook']));

    refusingTransport();

    attemptDelivery($this->tenant, $this->delivery);

    $delivery = freshDelivery($this->tenant, $this->delivery);
    $attempt = onlyAttempt($this->tenant);

    expect($delivery->status)->toBe(DeliveryStatus::Pending)
        ->and($attempt->outcome)->toBe(AttemptOutcome::Blocked)
        ->and($attempt->error_message)->toContain('disallowed range');
});

it('does not attempt a delivery that has already settled', function (): void {
    forTenant($this->tenant, fn () => $this->delivery->update(['status' => DeliveryStatus::Succeeded]));

    refusingTransport();

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
