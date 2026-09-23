<?php

declare(strict_types=1);

namespace App\Support\Contract;

use Dedoc\Scramble\Contracts\DocumentTransformer;
use Dedoc\Scramble\OpenApiContext;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Server;
use Illuminate\Support\Facades\Config;

/**
 * Scramble always builds its default server URL through `url()`, so it carries
 * whatever `APP_URL` happens to be in the environment that generated the document —
 * `http://localhost:8080/api` locally, `http://localhost/api` in CI (unset, no port).
 * A relative server entry makes the committed contract describe the API's shape, not
 * where a particular environment happened to serve it from; nothing in this repository
 * (the frontend's generated client, the SDKs) reads `servers` to build a base URL.
 */
final class UseRelativeServerUrl implements DocumentTransformer
{
    public function handle(OpenApi $document, OpenApiContext $context): void
    {
        // Server::make() carries no declared return type, so Larastan sees `mixed`
        // here — narrowed the same way ApiSecuritySchemes narrows SecurityScheme::http().
        $server = Server::make('/'.Config::string('scramble.api_path'));
        assert($server instanceof Server);

        $document->servers = [$server];
    }
}
