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
| target_url is where the seeded endpoint points. Unset by default, which
| BenchSeeder reads as "the sink, configured clean" — delay=0, fail_rate=0 —
| so Phase 1's ceiling number is never contaminated by the endpoint under
| test throttling or failing itself. Overriding it points a benchmark run at
| something other than the sink entirely, which no phase of
| benchmarking.md currently needs.
|
*/

return [
    'target_url' => env('BENCH_TARGET_URL'),
];
