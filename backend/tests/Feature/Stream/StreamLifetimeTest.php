<?php

declare(strict_types=1);

use App\Models\Delivery;
use App\Support\Delivery\TransportResult;
use App\Support\Pagination\KeysetCursor;
use App\Support\Stream\StreamSlots;
use Carbon\CarbonImmutable;

/**
 * The route itself: headers, the wire format's own retry:/id: framing, and
 * that a connection's slot is released once it completes. Each streamed
 * test drives the response's real loop for its own configured lifetime
 * (Laravel's TestResponse::streamedContent() executes it synchronously), so
 * lifetimes here are kept short on purpose rather than the 30s default.
 */
beforeEach(function (): void {
    [$this->tenant, $this->endpoint, $this->delivery] = publishedDelivery();
    $this->user = memberOf($this->tenant);
});

it('answers with the SSE headers: text/event-stream, no-cache, and buffering disabled for nginx', function (): void {
    $response = $this->actingAs($this->user)
        ->get('/api/v1/stream')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/event-stream; charset=utf-8')
        ->assertHeader('X-Accel-Buffering', 'no');

    // Symfony's ResponseHeaderBag appends ", private" to any Cache-Control
    // value that names neither public nor private nor s-maxage — correct
    // and even welcome for a per-tenant stream, so the assertion allows it
    // rather than fighting the framework for an exact string match.
    expect($response->headers->get('Cache-Control'))->toContain('no-cache');
});

it('opens with a retry: line, emits an attempt frame whose id: round-trips through KeysetCursor, and releases its slot on completion', function (): void {
    config([
        'postbox.stream.max_lifetime_seconds' => 2,
        'postbox.stream.poll_interval_ms' => 50,
        'postbox.stream.watermark_seconds' => 0,
    ]);

    fakeTransport(TransportResult::responded(200, [], 'ok', 5));
    attemptDelivery($this->tenant, $this->delivery);

    $slots = app(StreamSlots::class);
    $now = CarbonImmutable::now();
    expect($slots->active($this->tenant, $now))->toBe(0);

    $body = $this->actingAs($this->user)->get('/api/v1/stream')->streamedContent();

    expect($body)->toStartWith('retry: 2000');

    $frames = collect(parseSseFrames($body));
    $attemptFrame = $frames->firstWhere('event', 'attempt');

    expect($attemptFrame)->not->toBeNull()
        ->and($attemptFrame['id'])->not->toBeNull()
        ->and(KeysetCursor::decode($attemptFrame['id']))->not->toBeNull();

    $payload = json_decode((string) $attemptFrame['data'], true, flags: JSON_THROW_ON_ERROR);
    expect($payload['delivery_id'])->toBe($this->delivery->public_id);

    // Not a race against the request that just finished: sendContent() only
    // returns after the streaming closure's own finally has already run.
    expect($slots->active($this->tenant, CarbonImmutable::now()))->toBe(0);
});

it('emits a heartbeat comment while idle, so a proxy in between never sees a silent socket', function (): void {
    config([
        'postbox.stream.max_lifetime_seconds' => 2,
        'postbox.stream.poll_interval_ms' => 50,
        'postbox.stream.heartbeat_seconds' => 1,
    ]);

    $body = $this->actingAs($this->user)->get('/api/v1/stream')->streamedContent();

    expect($body)->toContain(': heartbeat');
});

/*
 * What this suite cannot prove: that the finally block still releases the
 * slot when StreamAttempts::poll() itself throws mid-loop. StreamAttempts
 * is a final class with no interface — deliberately, per CLAUDE.md's "a new
 * abstraction waits for the third use case" and the two already-approved
 * boundary interfaces being the only exceptions — so it cannot be swapped
 * for a throwing double the way HttpTransport or JitterSource can. The
 * guarantee here is structural instead (visible in StreamController's own
 * try/finally) and behavioural for the ordinary path above, the same
 * "what this technique cannot prove" admission conventions.md's own D96
 * already makes for a different guarantee.
 */

it('resumes across a reconnect with a disjoint, complete set of attempts — no gap, no duplicate', function (): void {
    config([
        'postbox.stream.max_lifetime_seconds' => 1,
        'postbox.stream.poll_interval_ms' => 30,
        'postbox.stream.watermark_seconds' => 0,
        'postbox.stream.heartbeat_seconds' => 10,
    ]);

    fakeTransport(TransportResult::responded(200, [], 'ok', 5));
    attemptDelivery($this->tenant, $this->delivery);

    $first = $this->actingAs($this->user)->get('/api/v1/stream')->streamedContent();
    $firstIds = collect(parseSseFrames($first))->where('event', 'attempt')->pluck('id');

    expect($firstIds)->toHaveCount(1);
    $lastEventId = $firstIds->last();

    // A second, independent delivery to the same endpoint — opened only
    // after the first connection already closed, and attempted once. The
    // original delivery is no longer pending after its own succeeded
    // attempt above, so this is a fresh row rather than a second attempt
    // at the first one (AttemptDelivery::reserveAttemptNumber() is a no-op
    // once status has left pending, the same idempotence SendDeliveryTest's
    // own "runs safely twice, sending only once" already proves).
    $secondDelivery = forTenant($this->tenant, fn (): Delivery => Delivery::factory()->for($this->endpoint)->create());

    fakeTransport(TransportResult::responded(200, [], 'ok', 5));
    attemptDelivery($this->tenant, $secondDelivery);

    $second = $this->actingAs($this->user)
        ->withHeaders(['Last-Event-ID' => $lastEventId])
        ->get('/api/v1/stream')
        ->streamedContent();

    $secondIds = collect(parseSseFrames($second))->where('event', 'attempt')->pluck('id');

    expect($secondIds)->toHaveCount(1)
        ->and($secondIds->intersect($firstIds))->toBeEmpty();
});
