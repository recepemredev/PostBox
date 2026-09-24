<?php

declare(strict_types=1);

use App\Http\Middleware\AssignRequestId;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Response;

/*
 * One id per request: generated when the caller sends none, honoured when the
 * caller's own looks like an id, replaced — never echoed back — when it does
 * not. What Laravel does with Context once it holds this value (attaching it
 * to a log record) is framework behaviour and is not re-tested here;
 * everything in this file exercises AssignRequestId's own decision.
 */

const REQUEST_ID_HEADER = 'X-Request-Id';

it('generates a request id when the caller sends none', function (): void {
    $response = $this->getJson('/api/health');

    $requestId = $response->headers->get(REQUEST_ID_HEADER);

    expect($requestId)->not->toBeNull()
        ->and(preg_match('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/', (string) $requestId))->toBe(1);
});

it('generates a different id for every request', function (): void {
    $first = $this->getJson('/api/health')->headers->get(REQUEST_ID_HEADER);
    $second = $this->getJson('/api/health')->headers->get(REQUEST_ID_HEADER);

    expect($first)->not->toBe($second);
});

it("echoes back the caller's own request id when it looks like one", function (): void {
    $response = $this->getJson('/api/health', [REQUEST_ID_HEADER => 'client-supplied-id-123']);

    expect($response->headers->get(REQUEST_ID_HEADER))->toBe('client-supplied-id-123');
});

it('replaces a malformed caller-supplied request id rather than echoing it back', function (string $malformed): void {
    $response = $this->getJson('/api/health', [REQUEST_ID_HEADER => $malformed]);

    expect($response->headers->get(REQUEST_ID_HEADER))
        ->not->toBe($malformed)
        ->not->toBeNull();
})->with([
    'contains a space' => 'has a space',
    'contains a disallowed character' => 'id/with/slash',
    'over the length ceiling' => str_repeat('a', 129),
    'empty string' => '',
]);

it('puts the same id it echoes into the log context', function (): void {
    $middleware = app(AssignRequestId::class);
    $request = Request::create('/api/health');

    $response = $middleware->handle($request, fn (): Response => new Response);

    expect(Context::get('request_id'))->toBe($response->headers->get(REQUEST_ID_HEADER));
});
