<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\Delivery\GuzzleTransport;
use App\Support\Delivery\HttpTransport;
use App\Support\Identity\Permissions;
use App\Support\Tenancy\TenantContext;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        /*
         * Both hold state for the length of one request, job or command. A second
         * instance of either would be a second answer to "which tenant is this?",
         * which is the one question this application cannot afford two answers to.
         */
        $this->app->singleton(TenantContext::class);
        $this->app->singleton(Permissions::class);

        /*
         * The dashboard guard, named once. Actions depend on the contract rather
         * than on a guard name string repeated across the identity layer.
         */
        $this->app->bind(
            StatefulGuard::class,
            static function (Application $app): StatefulGuard {
                $guard = $app->make(AuthFactory::class)->guard('web');

                assert($guard instanceof StatefulGuard);

                return $guard;
            },
        );

        /*
         * The outbound transport, one of the two pre-approved boundary
         * interfaces. GuzzleTransport is curl-specific — CURLOPT_RESOLVE is
         * what lets AddressGuard's pinned address actually get used — so its
         * own collaborator is Guzzle's client contract, not a second layer of
         * indirection over it.
         */
        $this->app->singleton(ClientInterface::class, static fn (): ClientInterface => new Client);
        $this->app->bind(HttpTransport::class, GuzzleTransport::class);
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

        $this->configureRateLimiting();
    }

    /**
     * Authentication is rate limited on two keys at once: the address being tried,
     * so one account cannot be ground down from many hosts, and the host trying,
     * so one host cannot sweep many accounts. Ingest gets a token bucket of its
     * own in Step 5 — a different mechanism for a different problem.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('login', static fn (Request $request): array => [
            Limit::perMinute(5)->by(Str::lower($request->string('email')->value()).'|'.$request->ip()),
            Limit::perMinute(20)->by((string) $request->ip()),
        ]);
    }
}
