<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\RequestSequence;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        /*
         * A singleton, and under Octane that means one per worker for the life
         * of the worker. The failure schedule is a function of how many
         * requests this worker has already answered, so the binding's lifetime
         * is the mechanism, not an optimisation.
         */
        $this->app->singleton(RequestSequence::class);
    }
}
