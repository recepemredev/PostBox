<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        /*
         * Outside production, an N+1 query, a silently discarded attribute or a
         * missing attribute is a defect and must throw rather than warn. In
         * production the same mistake degrades a response instead of failing it.
         */
        Model::shouldBeStrict(! $this->app->isProduction());

        /*
         * A top-level "data" envelope adds nothing to a single resource, and an
         * orchestrator reading /api/health should not have to unwrap one.
         * Paginated collections keep their envelope — they need it for the cursor.
         */
        JsonResource::withoutWrapping();
    }
}
