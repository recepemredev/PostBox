<?php

declare(strict_types=1);

use PostBox\Exception\AuthenticationFailed;
use PostBox\Exception\IdempotencyConflict;
use PostBox\Exception\NotFound;
use PostBox\Exception\PostBoxException;
use PostBox\Exception\QuotaExhausted;
use PostBox\Exception\RateLimited;
use PostBox\Exception\ServerError;
use PostBox\Exception\TransportFailed;
use PostBox\Exception\ValidationFailed;
use PostBox\RetryPolicy;
use Tests\Support\FakeClient;
use Tests\Support\FakeTransportException;

beforeEach(function (): void {
    $this->client = new FakeClient;
});

it('returns a Message and marks it not replayed on 201', function (): void {
    $this->client->queueResponse(jsonResponse(201, [
        'id' => 'msg_01test',
        'event_type' => 'invoice.paid',
        'source' => 'api',
        'created_at' => '2026-09-23T10:00:00Z',
    ]));

    $message = postBox($this->client)->publish('app_01test', 'invoice.paid', ['total' => 4200]);

    expect($message->id)->toBe('msg_01test')
        ->and($message->eventType)->toBe('invoice.paid')
        ->and($message->source)->toBe('api')
        ->and($message->createdAt)->toBe('2026-09-23T10:00:00Z')
        ->and($message->replayed)->toBeFalse();
});

it('marks the Message replayed on 200, the idempotent-replay status', function (): void {
    $this->client->queueResponse(jsonResponse(200, [
        'id' => 'msg_01test',
        'event_type' => 'invoice.paid',
        'source' => 'api',
        'created_at' => '2026-09-23T10:00:00Z',
    ], ['Idempotent-Replay' => 'true']));

    $message = postBox($this->client)->publish('app_01test', 'invoice.paid', ['total' => 4200]);

    expect($message->replayed)->toBeTrue();
});

it('sends the given idempotency key unchanged, never generating its own', function (): void {
    $this->client->queueResponse(jsonResponse(201, [
        'id' => 'msg_01test', 'event_type' => 'invoice.paid', 'source' => 'api', 'created_at' => '2026-09-23T10:00:00Z',
    ]));

    postBox($this->client)->publish('app_01test', 'invoice.paid', ['total' => 4200], idempotencyKey: 'my-own-key');

    expect($this->client->requests[0]->getHeaderLine('Idempotency-Key'))->toBe('my-own-key');
});

it('generates an idempotency key when none is given', function (): void {
    $this->client->queueResponse(jsonResponse(201, [
        'id' => 'msg_01test', 'event_type' => 'invoice.paid', 'source' => 'api', 'created_at' => '2026-09-23T10:00:00Z',
    ]));

    postBox($this->client)->publish('app_01test', 'invoice.paid', ['total' => 4200]);

    expect($this->client->requests[0]->getHeaderLine('Idempotency-Key'))->not->toBeEmpty();
});

it('carries the API key as a bearer token and the body as event_type plus payload', function (): void {
    $this->client->queueResponse(jsonResponse(201, [
        'id' => 'msg_01test', 'event_type' => 'invoice.paid', 'source' => 'api', 'created_at' => '2026-09-23T10:00:00Z',
    ]));

    postBox($this->client)->publish('app_01test', 'invoice.paid', ['total' => 4200]);

    $request = $this->client->requests[0];

    expect($request->getMethod())->toBe('POST')
        ->and((string) $request->getUri())->toBe('https://api.postbox.test/v1/apps/app_01test/messages')
        ->and($request->getHeaderLine('Authorization'))->toBe('Bearer pbk_test_key')
        ->and(json_decode((string) $request->getBody(), true))->toBe([
            'event_type' => 'invoice.paid',
            'payload' => ['total' => 4200],
        ]);
});

it('maps every documented error status to its own exception, without retrying it', function (
    int $status,
    string $exceptionClass,
): void {
    $this->client->queueResponse(jsonResponse($status, ['message' => 'a reason PostBox gave']));

    expect(fn () => postBox($this->client)->publish('app_01test', 'invoice.paid', ['total' => 4200]))
        ->toThrow($exceptionClass, 'a reason PostBox gave');

    expect($this->client->requests)->toHaveCount(1);
})->with([
    [401, AuthenticationFailed::class],
    [402, QuotaExhausted::class],
    [404, NotFound::class],
    [409, IdempotencyConflict::class],
]);

it('carries the field errors from a 422 onto ValidationFailed', function (): void {
    $this->client->queueResponse(jsonResponse(422, [
        'message' => 'The given data was invalid.',
        'errors' => ['payload' => ['The payload field is required.']],
    ]));

    try {
        postBox($this->client)->publish('app_01test', 'invoice.paid', []);
        $this->fail('Expected ValidationFailed to be thrown.');
    } catch (ValidationFailed $exception) {
        expect($exception->errors)->toBe(['payload' => ['The payload field is required.']]);
    }
});

it('retries a 5xx up to the retry policy, then throws ServerError', function (): void {
    $this->client->queueResponse(jsonResponse(500, ['message' => 'internal error']));
    $this->client->queueResponse(jsonResponse(502, ['message' => 'bad gateway']));
    $this->client->queueResponse(jsonResponse(503, ['message' => 'unavailable']));

    // Built outside the expect(fn () => ...) below on purpose: an arrow
    // function auto-captures its parent scope by value, so a closure nested
    // inside one that declares use (&$sleeps) binds to that by-value copy,
    // not to this variable — the object handle passed into postBox() here
    // keeps its own &$sleeps binding regardless of how it is later passed
    // around.
    $sleeps = [];
    $recordSleep = function (int $ms) use (&$sleeps): void {
        $sleeps[] = $ms;
    };
    $policy = new RetryPolicy(maxAttempts: 3, baseDelayMs: 10, factor: 2, ceilingMs: 100);

    expect(fn () => postBox($this->client, $policy, $recordSleep)
        ->publish('app_01test', 'invoice.paid', ['total' => 4200]))
        ->toThrow(ServerError::class, 'unavailable');

    expect($this->client->requests)->toHaveCount(3)
        ->and($sleeps)->toBe([10, 20]);
});

it('succeeds once a retried 5xx is followed by a 201', function (): void {
    $this->client->queueResponse(jsonResponse(500, ['message' => 'internal error']));
    $this->client->queueResponse(jsonResponse(201, [
        'id' => 'msg_01test', 'event_type' => 'invoice.paid', 'source' => 'api', 'created_at' => '2026-09-23T10:00:00Z',
    ]));

    $policy = new RetryPolicy(maxAttempts: 3, baseDelayMs: 1, factor: 2, ceilingMs: 10);
    $message = postBox($this->client, $policy, function (int $ms): void {})
        ->publish('app_01test', 'invoice.paid', ['total' => 4200]);

    expect($message->id)->toBe('msg_01test')
        ->and($this->client->requests)->toHaveCount(2);
});

it('retries with the same idempotency key on every attempt', function (): void {
    $this->client->queueResponse(jsonResponse(500, ['message' => 'internal error']));
    $this->client->queueResponse(jsonResponse(201, [
        'id' => 'msg_01test', 'event_type' => 'invoice.paid', 'source' => 'api', 'created_at' => '2026-09-23T10:00:00Z',
    ]));

    $policy = new RetryPolicy(maxAttempts: 3, baseDelayMs: 1, factor: 2, ceilingMs: 10);
    postBox($this->client, $policy, function (int $ms): void {})
        ->publish('app_01test', 'invoice.paid', ['total' => 4200]);

    $keys = array_map(
        fn ($request) => $request->getHeaderLine('Idempotency-Key'),
        $this->client->requests,
    );

    expect($keys[0])->toBe($keys[1])->and($keys[0])->not->toBeEmpty();
});

it('retries a transport failure, then throws TransportFailed once every attempt fails', function (): void {
    $this->client->queueException(new FakeTransportException('connection refused'));
    $this->client->queueException(new FakeTransportException('connection refused'));
    $this->client->queueException(new FakeTransportException('connection refused'));

    $policy = new RetryPolicy(maxAttempts: 3, baseDelayMs: 1, factor: 2, ceilingMs: 10);

    expect(fn () => postBox($this->client, $policy, function (int $ms): void {})
        ->publish('app_01test', 'invoice.paid', ['total' => 4200]))
        ->toThrow(TransportFailed::class, 'connection refused');

    expect($this->client->requests)->toHaveCount(3);
});

it('retries a 429 up to the retry policy honouring Retry-After, then throws RateLimited', function (): void {
    $this->client->queueResponse(jsonResponse(429, ['message' => 'rate limited'], ['Retry-After' => '2']));
    $this->client->queueResponse(jsonResponse(429, ['message' => 'rate limited'], ['Retry-After' => '3']));
    $this->client->queueResponse(jsonResponse(429, ['message' => 'rate limited'], ['Retry-After' => '4']));

    $sleeps = [];
    $policy = new RetryPolicy(maxAttempts: 3, baseDelayMs: 10, factor: 2, ceilingMs: 100);

    try {
        postBox($this->client, $policy, function (int $ms) use (&$sleeps): void {
            $sleeps[] = $ms;
        })->publish('app_01test', 'invoice.paid', ['total' => 4200]);
        $this->fail('Expected RateLimited to be thrown.');
    } catch (RateLimited $exception) {
        expect($exception->retryAfter)->toBe(4);
    }

    // Retry-After overrides the computed delay outright on every attempt.
    expect($sleeps)->toBe([2_000, 3_000]);
});

it('never retries a 4xx that is not documented as a rate limit', function (int $status): void {
    $this->client->queueResponse(jsonResponse($status, ['message' => 'refused']));

    expect(fn () => postBox($this->client)->publish('app_01test', 'invoice.paid', ['total' => 4200]))
        ->toThrow(PostBoxException::class);

    expect($this->client->requests)->toHaveCount(1);
})->with([401, 402, 404, 409, 422]);
