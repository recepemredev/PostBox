<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\HorizonApplicationServiceProvider;

/**
 * Horizon is installed here as a queue supervisor and metrics collector, not as
 * an operator surface — the dashboard (Step 17) is what exposes queue depth and
 * breaker state, and it will read Horizon's own tables to do it rather than
 * iframe the package's UI. The gate below is therefore closed everywhere, on
 * purpose: nginx never forwards a `/horizon` path to this application in the
 * first place (only `/api` and `/v1` reach PHP-FPM), so the UI is unreachable
 * regardless, and the gate is the second reason it stays that way.
 */
final class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    public function boot(): void
    {
        parent::boot();
    }

    protected function gate(): void
    {
        Gate::define('viewHorizon', static fn (): bool => false);
    }
}
