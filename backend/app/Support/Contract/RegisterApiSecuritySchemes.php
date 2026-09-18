<?php

declare(strict_types=1);

namespace App\Support\Contract;

use Dedoc\Scramble\Contracts\DocumentTransformer;
use Dedoc\Scramble\OpenApiContext;
use Dedoc\Scramble\Support\Generator\OpenApi;

/**
 * Declares both security schemes on the document. This alone makes every operation
 * accept either credential by default; RestrictOperationSecurity narrows that down to
 * exactly what each route actually requires — the same two-pass shape Scramble's own
 * built-in MiddlewareAuthSecurityStrategy uses for its one scheme.
 */
final class RegisterApiSecuritySchemes implements DocumentTransformer
{
    public function handle(OpenApi $document, OpenApiContext $context): void
    {
        $document->secure(ApiSecuritySchemes::bearerApiKey());
        $document->secure(ApiSecuritySchemes::sessionCookie());
    }
}
