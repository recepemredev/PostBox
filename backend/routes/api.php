<?php

declare(strict_types=1);

use App\Http\Controllers\ApiKeyController;
use App\Http\Controllers\ApplicationController;
use App\Http\Controllers\DeliveryAttemptController;
use App\Http\Controllers\EndpointController;
use App\Http\Controllers\EndpointSecretController;
use App\Http\Controllers\EventTypeController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\MeController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\OperationsController;
use App\Http\Controllers\ReplayController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\StreamController;
use App\Models\ApiKey;
use App\Models\Application;
use App\Models\Endpoint;
use App\Models\EndpointCircuitBreaker;
use App\Models\EndpointSecret;
use App\Models\EventType;
use Illuminate\Support\Facades\Route;

/*
 * Unauthenticated on purpose: orchestrators and load balancers call it before any
 * credential exists. It exposes booleans and durations only — never internal detail.
 */
Route::get('/health', HealthController::class)->name('health');

/*
 * The dashboard surface. It is served from the same origin as the dashboard
 * itself, through nginx, so the credential is a session cookie and CSRF applies —
 * which is why these routes carry the `dashboard` middleware group and the public
 * API in Step 4 will not.
 */
Route::prefix('v1')->middleware('dashboard')->group(function (): void {
    /*
     * Issues the XSRF-TOKEN cookie the dashboard reads before its first mutation.
     * The cookie is written by the CSRF middleware on any response through this
     * group; this route exists so the client can ask for one without side effects.
     */
    Route::get('csrf-cookie', static fn () => response()->noContent())->name('csrf-cookie');

    Route::post('login', [SessionController::class, 'store'])
        ->middleware('throttle:login')
        ->name('login');

    Route::middleware(['auth', 'tenant'])->group(function (): void {
        Route::post('logout', [SessionController::class, 'destroy'])->name('logout');
        Route::get('me', MeController::class)->name('me');

        Route::get('api-keys', [ApiKeyController::class, 'index'])
            ->can('viewAny', ApiKey::class)
            ->name('api-keys.index');

        // Authorization for this one lives in the form request, next to the rules
        // it validates; the two reads without a body declare it on the route.
        Route::post('api-keys', [ApiKeyController::class, 'store'])->name('api-keys.store');

        Route::delete('api-keys/{apiKey}', [ApiKeyController::class, 'destroy'])
            ->can('delete', 'apiKey')
            ->name('api-keys.destroy');

        /*
         * Catalog. Applications, endpoints, event types, subscriptions and
         * signing secrets — every mutation here is written to the audit
         * log by the action behind it (endpoint and secret changes only;
         * CLAUDE.md names those two, not applications).
         */
        Route::get('applications', [ApplicationController::class, 'index'])
            ->can('viewAny', Application::class)
            ->name('applications.index');

        Route::post('applications', [ApplicationController::class, 'store'])->name('applications.store');

        Route::get('applications/{application}', [ApplicationController::class, 'show'])
            ->can('view', 'application')
            ->name('applications.show');

        Route::patch('applications/{application}', [ApplicationController::class, 'update'])
            ->name('applications.update');

        Route::get('applications/{application}/endpoints', [EndpointController::class, 'index'])
            ->can('viewAny', Endpoint::class)
            ->name('applications.endpoints.index');

        Route::post('applications/{application}/endpoints', [EndpointController::class, 'store'])
            ->name('applications.endpoints.store');

        Route::get('endpoints/{endpoint}', [EndpointController::class, 'show'])
            ->can('view', 'endpoint')
            ->name('endpoints.show');

        Route::patch('endpoints/{endpoint}', [EndpointController::class, 'update'])
            ->name('endpoints.update');

        Route::put('endpoints/{endpoint}/subscriptions', [EndpointController::class, 'syncSubscriptions'])
            ->name('endpoints.subscriptions.sync');

        Route::get('event-types', [EventTypeController::class, 'index'])
            ->can('viewAny', EventType::class)
            ->name('event-types.index');

        Route::post('event-types', [EventTypeController::class, 'store'])->name('event-types.store');

        Route::get('endpoints/{endpoint}/secrets', [EndpointSecretController::class, 'index'])
            ->can('viewAny', EndpointSecret::class)
            ->name('endpoints.secrets.index');

        Route::post('endpoints/{endpoint}/secrets', [EndpointSecretController::class, 'store'])
            ->name('endpoints.secrets.store');

        Route::delete('endpoints/{endpoint}/secrets/{secret}', [EndpointSecretController::class, 'destroy'])
            ->can('delete', 'secret')
            ->name('endpoints.secrets.destroy');

        /*
         * Ledger's own read surface (Step 14): the message list and its
         * attempt inspector. No `governor` — these are reads, and Governor
         * exists to bound the ingest surface's write volume, not the
         * dashboard's own traffic. Authorization for the filtered list and
         * the attempt list lives in their own Form Requests, next to the
         * rules they validate, the same split every other Form-Request-
         * carrying route in this file already takes; a plain read with no
         * body (messages.show) declares it on the route instead.
         */
        Route::get('messages', [MessageController::class, 'index'])->name('messages.index');

        Route::get('messages/{message}', [MessageController::class, 'show'])
            ->can('view', 'message')
            ->name('messages.show');

        Route::get('deliveries/{delivery}/attempts', [DeliveryAttemptController::class, 'index'])
            ->name('deliveries.attempts.index');

        /*
         * The live stream (Step 15): the operator's own feed of delivery
         * attempts as they happen. A read, like the two routes above it, so
         * `throttle:stream` — a named limiter (AppServiceProvider), not
         * `governor` — is what bounds it: a dashboard tab reconnecting must
         * never spend the message quota Governor exists to protect (D130).
         */
        Route::get('stream', StreamController::class)
            ->middleware('throttle:stream')
            ->name('stream');

        /*
         * The operations screen (Step 17): queue depth and this tenant's
         * breakers, for an operator asking "is the system healthy right
         * now" rather than "what happened to this delivery" (the ledger's
         * own question). No request body, so authorization is declared on
         * the route like every other unfiltered read above it.
         */
        Route::get('operations', OperationsController::class)
            ->can('viewAny', EndpointCircuitBreaker::class)
            ->name('operations');

        /*
         * Recovery. An operator's own action, so it rides the session
         * credential rather than the ingest surface's API key — the same
         * distinction Identity draws between a person and a system (D37) —
         * and `governor` still applies: a replay opens real deliveries
         * through the same pipeline a fresh publish does, and modules.md
         * already commits Governor to sitting in front of both.
         */
        Route::middleware('governor')->group(function (): void {
            Route::post('messages/{message}/replay', [ReplayController::class, 'message'])
                ->name('replays.message');

            Route::post('endpoints/{endpoint}/replays', [ReplayController::class, 'range'])
                ->name('replays.range');

            /*
             * D76's test event: the ordinary ingest path, so it sits behind
             * `governor` exactly like a producer's own publish — one
             * rate-limit token, one quota unit, regardless of who is asking.
             */
            Route::post('endpoints/{endpoint}/test-events', [EndpointController::class, 'sendTestEvent'])
                ->name('endpoints.test-events.store');
        });
    });
});

/*
 * The ingest surface. A producer is a system rather than a person, so the
 * credential is an API key and there is no session, no CSRF and no cookie —
 * nothing here is reachable from a browser form. The tenant comes out of the
 * token itself, which is why `api-key` sits ahead of SubstituteBindings in the
 * priority list: {application} resolves through the tenant scope, so another
 * tenant's application is a 404 rather than a 403.
 *
 * `governor` follows `api-key` for the tenant it needs, and precedes route
 * model binding for the same reason `api-key` does: a request Governor is
 * about to reject should not pay for a lookup it will never use.
 */
Route::prefix('v1')->middleware(['api-key', 'governor'])->group(function (): void {
    Route::post('apps/{application}/messages', [MessageController::class, 'store'])
        ->name('messages.store');
});
