<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Bench
|--------------------------------------------------------------------------
|
| BenchSeeder's own configuration, in its own file rather than postbox.php:
| modules.md draws Bench depends on Delivery, Delivery never depends on
| Bench, and a bench key inside Delivery's own config file would be the
| dependency pointing backwards.
|
| target_urls is where the seeded endpoint(s) point — a single URL, or several
| separated by commas, each becoming its own endpoint subscribed to the same
| event type. Unset by default, which BenchSeeder reads as "one endpoint, the
| sink, configured clean" — delay=0, fail_rate=0 — so Phase 1's ceiling number
| is never contaminated by the endpoint under test throttling or failing
| itself. A comma-separated pair (a clean sink alongside a degraded one, say
| fail_rate=0.3) is what Phase 5's degraded-receiver protocol seeds: one
| publish fans out to both, so the comparison sits inside a single run.
|
*/

return [
    'target_urls' => env('BENCH_TARGET_URLS'),
];
