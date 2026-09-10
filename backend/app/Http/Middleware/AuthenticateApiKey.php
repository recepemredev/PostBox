<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Actions\Identity\ResolveApiKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The ingest surface's credential check. There is no session and no user here —
 * a producer is a system — so what the request comes away with is a tenant, not
 * a person.
 */
final readonly class AuthenticateApiKey
{
    public function __construct(private ResolveApiKey $resolve) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $this->resolve->handle($request->bearerToken());

        return $next($request);
    }
}
