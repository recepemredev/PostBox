<?php

declare(strict_types=1);

namespace App\Support\Contract;

use Dedoc\Scramble\Contracts\OperationTransformer;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\SecurityRequirement;
use Dedoc\Scramble\Support\RouteInfo;

/**
 * Narrows RegisterApiSecuritySchemes' global "either credential" default down to
 * exactly one, read off the route's own middleware rather than guessed from its URI —
 * the same signal AuthenticateApiKey and the `auth` guard actually authenticate against
 * (D26: a producer holds an API key, an operator holds a session; never both). A route
 * with neither middleware is genuinely public (health, csrf-cookie, login).
 */
final class RestrictOperationSecurity implements OperationTransformer
{
    public function handle(Operation $operation, RouteInfo $routeInfo): void
    {
        $middleware = $routeInfo->route->gatherMiddleware();

        $operation->security = match (true) {
            in_array('api-key', $middleware, true) => [
                new SecurityRequirement([ApiSecuritySchemes::BEARER_API_KEY => []]),
            ],
            in_array('auth', $middleware, true) => [
                new SecurityRequirement([ApiSecuritySchemes::SESSION_COOKIE => []]),
            ],
            default => [],
        };
    }
}
