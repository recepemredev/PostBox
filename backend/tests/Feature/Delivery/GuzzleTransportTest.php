<?php

declare(strict_types=1);

use App\Enums\AttemptOutcome;
use App\Support\Delivery\GuardedTarget;
use App\Support\Delivery\GuzzleTransport;
use App\Support\Delivery\OutboundRequest;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request as Psr7Request;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/*
 * GuzzleTransport is the one implementation of the outbound transport
 * boundary. A MockHandler stands in for the network here, so these tests
 * assert two things without ever making a real connection: the request
 * actually built — headers, body, the pinned CURLOPT_RESOLVE option, the
 * configured timeouts — and the mapping from a curl failure's errno to
 * AttemptOutcome.
 */

function outboundRequest(
    string $url = 'https://example.test/webhook',
    ?GuardedTarget $target = null,
    int $connectTimeoutMs = 5000,
    int $timeoutMs = 15000,
): OutboundRequest {
    return new OutboundRequest(
        url: $url,
        target: $target ?? new GuardedTarget('example.test', 443, '93.184.216.34'),
        headers: ['Content-Type' => 'application/json'],
        body: '{"example":true}',
        connectTimeoutMs: $connectTimeoutMs,
        timeoutMs: $timeoutMs,
    );
}

/**
 * @param  array<int, array{request: RequestInterface, options: array<string, mixed>}>  $history
 */
function transportWith(MockHandler $mock, array &$history = []): GuzzleTransport
{
    $stack = HandlerStack::create($mock);
    $stack->push(Middleware::history($history));

    return new GuzzleTransport(new Client(['handler' => $stack]));
}

function curlFailure(int $errno): AttemptOutcome
{
    $request = new Psr7Request('POST', 'https://example.test/webhook');
    $mock = new MockHandler([
        new RequestException('cURL error', $request, null, null, ['errno' => $errno]),
    ]);

    $result = transportWith($mock)->send(outboundRequest());

    expect($result->responded)->toBeFalse();

    /** @var AttemptOutcome $failure */
    $failure = $result->failure;

    return $failure;
}

it('sends the method, headers and body given', function (): void {
    $mock = new MockHandler([new Response(200, ['Content-Type' => 'application/json'], '{"ok":true}')]);
    $history = [];

    transportWith($mock, $history)->send(outboundRequest());

    $sent = $history[0]['request'];

    expect($sent->getMethod())->toBe('POST')
        ->and($sent->getHeaderLine('Content-Type'))->toBe('application/json')
        ->and((string) $sent->getBody())->toBe('{"example":true}');
});

it('pins the connection to the guarded address rather than the hostname', function (): void {
    $mock = new MockHandler([new Response(200)]);
    $history = [];

    transportWith($mock, $history)->send(
        outboundRequest(target: new GuardedTarget('example.test', 443, '203.0.113.9'))
    );

    expect($history[0]['options']['curl'][CURLOPT_RESOLVE])->toBe(['example.test:443:203.0.113.9']);
});

it('passes the configured timeouts to the client', function (): void {
    $mock = new MockHandler([new Response(200)]);
    $history = [];

    transportWith($mock, $history)->send(outboundRequest(connectTimeoutMs: 2500, timeoutMs: 9000));

    expect($history[0]['options']['connect_timeout'])->toBe(2.5)
        ->and($history[0]['options']['timeout'])->toBe(9.0);
});

it('does not follow a redirect', function (): void {
    $mock = new MockHandler([new Response(200)]);
    $history = [];

    transportWith($mock, $history)->send(outboundRequest());

    expect($history[0]['options']['allow_redirects'])->toBeFalse();
});

it('records a non-2xx response as responded rather than a failure', function (): void {
    $mock = new MockHandler([new Response(500, [], '{"error":"server_error"}')]);

    $result = transportWith($mock)->send(outboundRequest());

    expect($result->responded)->toBeTrue()
        ->and($result->status)->toBe(500)
        ->and($result->body)->toBe('{"error":"server_error"}')
        ->and($result->failure)->toBeNull();
});

it('flattens a multi-value response header', function (): void {
    $response = (new Response(200))->withAddedHeader('X-Multi', 'a')->withAddedHeader('X-Multi', 'b');
    $mock = new MockHandler([$response]);

    $result = transportWith($mock)->send(outboundRequest());

    expect($result->headers['X-Multi'])->toBe('a, b');
});

it('classifies a curl timeout as Timeout', function (): void {
    expect(curlFailure(CURLE_OPERATION_TIMEOUTED))->toBe(AttemptOutcome::Timeout);
});

it('classifies a curl DNS failure as DnsError', function (): void {
    expect(curlFailure(CURLE_COULDNT_RESOLVE_HOST))->toBe(AttemptOutcome::DnsError);
});

it('classifies a curl TLS failure as TlsError', function (): void {
    expect(curlFailure(CURLE_SSL_CACERT))->toBe(AttemptOutcome::TlsError);
});

it('classifies an unrecognized curl failure as ConnectionError', function (): void {
    expect(curlFailure(CURLE_COULDNT_CONNECT))->toBe(AttemptOutcome::ConnectionError);
});

it('classifies a ConnectException the same way as a plain RequestException', function (): void {
    // Guzzle raises ConnectException for a fixed subset of errno values,
    // including this one — it is a sibling of RequestException, not a
    // subtype of it, which is exactly what this test would catch a
    // regression in.
    $request = new Psr7Request('POST', 'https://example.test/webhook');
    $mock = new MockHandler([
        new ConnectException('cURL error 6: could not resolve host', $request, null, ['errno' => CURLE_COULDNT_RESOLVE_HOST]),
    ]);

    $result = transportWith($mock)->send(outboundRequest());

    expect($result->responded)->toBeFalse()
        ->and($result->failure)->toBe(AttemptOutcome::DnsError);
});
