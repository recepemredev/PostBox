<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

/*
 * Routes are registered with no middleware group at all. The `web` group would
 * add cookies, sessions and CSRF to every request; the sink answers machine
 * traffic from the delivery workers and needs none of it. This is not a
 * micro-optimisation for its own sake — the sink's ceiling is the number every
 * published PostBox figure is measured against (benchmarking.md), so overhead
 * here is overhead charged to PostBox's own numbers.
 */
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        using: static function (): void {
            Route::group([], base_path('routes/sink.php'));
        },
    )
    ->withMiddleware(static function (Middleware $middleware): void {
        //
    })
    ->withExceptions(static function (Exceptions $exceptions): void {
        //
    })->create();
