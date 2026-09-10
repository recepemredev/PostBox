<?php

declare(strict_types=1);

use App\Http\Controllers\ApiKeyController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\MeController;
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
    });
});
