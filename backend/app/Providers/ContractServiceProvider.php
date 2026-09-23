<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\Contract\DescribeDegradedHealth;
use App\Support\Contract\DescribeEventStream;
use App\Support\Contract\DescribeIdempotentWrites;
use App\Support\Contract\DescribeReplayResource;
use App\Support\Contract\RegisterApiSecuritySchemes;
use App\Support\Contract\RestrictOperationSecurity;
use Dedoc\Scramble\Scramble;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the OpenAPI document generator (dedoc/scramble, a development dependency —
 * absent from every production image, per assert-production-images.sh). No UI or JSON
 * route is registered: nginx never forwards /docs, and D18's "what remains is what the
 * project uses" discipline says a surface nothing can reach is closed, not left
 * dangling — the same choice already made for Horizon's own gate.
 *
 * This provider is registered unconditionally (bootstrap/providers.php is a plain
 * array, not an environment-aware one), so every method here guards on the package
 * actually being present. Without the guard, `composer install --no-dev` still
 * triggers `package:discover` during the production build, and that already-running
 * application boots this provider and finds no Scramble class to call.
 */
class ContractServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if (! class_exists(Scramble::class)) {
            return;
        }

        Scramble::ignoreDefaultRoutes();
    }

    public function boot(): void
    {
        if (! class_exists(Scramble::class)) {
            return;
        }

        Scramble::configure()
            ->withDocumentTransformers(RegisterApiSecuritySchemes::class)
            ->withDocumentTransformers(DescribeReplayResource::class)
            ->withOperationTransformers(RestrictOperationSecurity::class)
            ->withOperationTransformers(DescribeIdempotentWrites::class)
            ->withOperationTransformers(DescribeDegradedHealth::class)
            ->withOperationTransformers(DescribeEventStream::class);
    }
}
