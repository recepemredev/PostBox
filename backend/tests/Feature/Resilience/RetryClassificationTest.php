<?php

declare(strict_types=1);

use App\Enums\AttemptOutcome;
use App\Enums\DeliveryStatus;
use App\Support\Delivery\TransportResult;

/*
 * Which failures are worth trying again, asserted through a real delivery
 * rather than against the policy in isolation — the classification only matters
 * because of what it does to a delivery's state, and that is the pair this
 * file checks.
 *
 * The distinction is not "did it work" but "could the same request work later".
 * A 404 is the endpoint telling PostBox it understood and refused; sending the
 * identical bytes seven more times is not resilience, it is noise the operator
 * has to read past to find the failure that mattered.
 */

beforeEach(function (): void {
    [$this->tenant, $this->endpoint, $this->delivery] = publishedDelivery();

    pinJitter(0.5);
});

it('dead-letters a terminal response on the very first attempt', function (int $status): void {
    fakeTransport(TransportResult::responded($status, [], 'no', 4));

    attemptDelivery($this->tenant, $this->delivery);

    $delivery = freshDelivery($this->tenant, $this->delivery);

    expect($delivery->status)->toBe(DeliveryStatus::Exhausted)
        ->and($delivery->attempt_count)->toBe(1)
        ->and($delivery->next_attempt_at)->toBeNull()
        ->and($delivery->exhausted_at)->not->toBeNull()
        ->and($delivery->failure_reason)->toBe("terminal response: {$status}")
        ->and(onlyAttempt($this->tenant)->outcome)->toBe(AttemptOutcome::Failed);
})->with([400, 401, 403, 404, 409, 410, 422, 451, 301]);

it('keeps retrying a response that could plausibly change', function (int $status): void {
    fakeTransport(TransportResult::responded($status, [], 'later', 4));

    attemptDelivery($this->tenant, $this->delivery);

    $delivery = freshDelivery($this->tenant, $this->delivery);

    expect($delivery->status)->toBe(DeliveryStatus::Pending)
        ->and($delivery->next_attempt_at)->not->toBeNull()
        ->and($delivery->exhausted_at)->toBeNull()
        ->and($delivery->failure_reason)->toBeNull();
})->with([408, 429, 500, 502, 503, 504]);

it('keeps retrying every transport failure', function (AttemptOutcome $failure): void {
    fakeTransport(TransportResult::failed($failure, 'the network said no', 3));

    attemptDelivery($this->tenant, $this->delivery);

    $delivery = freshDelivery($this->tenant, $this->delivery);

    expect($delivery->status)->toBe(DeliveryStatus::Pending)
        ->and($delivery->next_attempt_at)->not->toBeNull()
        ->and($delivery->exhausted_at)->toBeNull();
})->with([
    AttemptOutcome::Timeout,
    AttemptOutcome::DnsError,
    AttemptOutcome::TlsError,
    AttemptOutcome::ConnectionError,
]);

/*
 * Blocked is PostBox's own refusal, not a fact about the endpoint's network,
 * and both of its causes are things an operator can fix while the delivery is
 * still in flight: add a secret, or point the endpoint at an address that is
 * not in a disallowed range. So it retries — and it is counted, so a
 * misconfiguration nobody fixes dead-letters instead of retrying forever.
 */
it('keeps retrying a delivery it refused to send for want of a secret', function (): void {
    forTenant($this->tenant, fn () => $this->endpoint->secrets()->delete());
    refusingTransport();

    attemptDelivery($this->tenant, $this->delivery);

    $delivery = freshDelivery($this->tenant, $this->delivery);

    expect($delivery->status)->toBe(DeliveryStatus::Pending)
        ->and($delivery->next_attempt_at)->not->toBeNull()
        ->and(onlyAttempt($this->tenant)->outcome)->toBe(AttemptOutcome::Blocked);
});

it('keeps retrying a delivery it refused to send to a disallowed address', function (): void {
    forTenant($this->tenant, fn () => $this->endpoint->update(['url' => 'http://127.0.0.1/webhook']));
    refusingTransport();

    attemptDelivery($this->tenant, $this->delivery);

    $delivery = freshDelivery($this->tenant, $this->delivery);

    expect($delivery->status)->toBe(DeliveryStatus::Pending)
        ->and($delivery->next_attempt_at)->not->toBeNull()
        ->and(onlyAttempt($this->tenant)->outcome)->toBe(AttemptOutcome::Blocked);
});

it('dead-letters a refusal that is never fixed, rather than retrying it forever', function (): void {
    config()->set('postbox.retry.max_attempts', 3);

    forTenant($this->tenant, fn () => $this->endpoint->secrets()->delete());
    refusingTransport();

    foreach (range(1, 3) as $ignored) {
        attemptDelivery($this->tenant, $this->delivery);
    }

    $delivery = freshDelivery($this->tenant, $this->delivery);

    expect($delivery->status)->toBe(DeliveryStatus::Exhausted)
        ->and($delivery->attempt_count)->toBe(3)
        ->and($delivery->failure_reason)->toBe('exhausted after 3 attempts; last outcome: blocked');
});
