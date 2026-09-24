<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * One id per HTTP request, carried through every log line it produces
 * (Laravel's own Context is attached to every log record automatically) and
 * echoed back so a caller can quote it in a support request. A caller's own
 * id is honoured — a request arriving through a proxy or a client SDK often
 * already carries one — but only if it looks like an id: an unbounded or
 * malformed string is exactly what a request id exists to distinguish
 * requests from, not to become one.
 *
 * Global and first in the stack (bootstrap/app.php), because everything
 * downstream — tenant resolution, API key authentication, the route itself —
 * is worth being able to correlate against the same id. This is a request's
 * own correlation id, not the delivery path's: a queued job outlives the
 * request that enqueued it, so SendDelivery carries the public ids
 * (message/delivery/endpoint) instead, not this one.
 */
final readonly class AssignRequestId
{
    private const string HEADER = 'X-Request-Id';

    private const string PATTERN = '/^[A-Za-z0-9._-]{1,128}$/';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $incoming = $request->header(self::HEADER);

        $requestId = is_string($incoming) && preg_match(self::PATTERN, $incoming) === 1
            ? $incoming
            : (string) Str::ulid();

        Context::add('request_id', $requestId);

        $response = $next($request);
        $response->headers->set(self::HEADER, $requestId);

        return $response;
    }
}
