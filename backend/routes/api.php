<?php

declare(strict_types=1);

use App\Http\Controllers\ApiKeyController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\MeController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\ReplayController;
use App\Http\Controllers\SessionController;
use App\Models\ApiKey;
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
         * Recovery. An operator's own action, so it rides the session
         * credential rather than the ingest surface's API key — the same
         * distinction Identity draws between a person and a system (D27) —
         * and `governor` still applies: a replay opens real deliveries
         * through the same pipeline a fresh publish does, and modules.md
         * already commits Governor to sitting in front of both.
         */
        Route::middleware('governor')->group(function (): void {
            Route::post('messages/{message}/replay', [ReplayController::class, 'message'])
                ->name('replays.message');
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
