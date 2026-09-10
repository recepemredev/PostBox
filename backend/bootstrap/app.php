<?php

declare(strict_types=1);

use App\Http\Middleware\EstablishTenant;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        // The framework's shallow `/up` route is deliberately not registered:
        // `GET /api/health` is the only health surface, and it probes dependencies.
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
         * The dashboard is a browser client on the same origin as the API, so its
         * credential is a session cookie and CSRF applies to it. Laravel's own
         * `web` group is not used: it also brings view error sharing and the
         * routing conveniences of a server-rendered application, none of which
         * exist here. Binding substitution is left to the `api` group, which
         * every one of these routes is already inside.
         */
        $middleware->group('dashboard', [
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            ValidateCsrfToken::class,
        ]);

        $middleware->alias([
            'tenant' => EstablishTenant::class,
        ]);

        /*
         * Order is not cosmetic here. Route model binding resolves a model through
         * the tenant scope, so the tenant has to be established before it runs —
         * otherwise a tenant's own resource is answered with 404 and the bug looks
         * like a routing mistake rather than an ordering one. It is declared
         * relative to the framework's list rather than by restating it, so a
         * framework release that adds a middleware does not silently lose it.
         */
        $middleware->prependToPriorityList(SubstituteBindings::class, EstablishTenant::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /*
         * Everything this application serves is an API. An unauthenticated request
         * has to come back as 401 JSON; the framework's default is a redirect to a
         * login route, which here would be a redirect to a route that does not
         * exist.
         */
        $exceptions->shouldRenderJsonWhen(static fn (): bool => true);
    })->create();
