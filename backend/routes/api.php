<?php

declare(strict_types=1);

use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

/*
 * Unauthenticated on purpose: orchestrators and load balancers call it before any
 * credential exists. It exposes booleans and durations only — never internal detail.
 */
Route::get('/health', HealthController::class)->name('health');
