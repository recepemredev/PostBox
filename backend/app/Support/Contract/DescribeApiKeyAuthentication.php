<?php

declare(strict_types=1);

namespace App\Support\Contract;

use Dedoc\Scramble\Contracts\OperationTransformer;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\RouteInfo;

/**
 * ResolveApiKey (App\Actions\Identity\ResolveApiKey) throws before any controller
 * action runs — a malformed, unknown, revoked, expired or wrong-tenant key all
 * answer the same 401, on purpose, so a caller cannot use the response to probe
 * which reason applied. That happens in AuthenticateApiKey middleware, which
 * static analysis of the controller cannot see, the same blind spot
 * DescribeIdempotentWrites documents for `governor`.
 */
final class DescribeApiKeyAuthentication implements OperationTransformer
{
    public function handle(Operation $operation, RouteInfo $routeInfo): void
    {
        if (! in_array('api-key', $routeInfo->route->gatherMiddleware(), true)) {
            return;
        }

        $operation->addResponse(OpenApiSchema::errorResponse(
            401,
            'The API key is missing, malformed or no longer valid.',
        ));
    }
}
