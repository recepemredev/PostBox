<?php

declare(strict_types=1);

use App\Http\Controllers\SinkController;
use Illuminate\Support\Facades\Route;

/*
 * Two routes and no middleware. Deliveries arrive as POSTs carrying PostBox's
 * signature headers, which the sink deliberately does not verify: it is a
 * receiver under load, not a conformance vector. Signature verification is
 * proven against the SDKs in Step 16.
 */
Route::post('/sink', SinkController::class);

Route::get('/health', static fn () => response('', 204));
