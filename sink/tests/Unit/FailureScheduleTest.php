<?php

declare(strict_types=1);

use App\Support\FailureSchedule;

/**
 * The schedule is the sink's one real mechanism, and "exactly as configured"
 * is the property Step 10 promises. These are the assertions a random draw
 * could not have supported: an exact count, a fixed set of positions, and the
 * same answer twice.
 */
it('fails exactly the configured number of times in every period', function (): void {
    for ($rate = 0; $rate <= FailureSchedule::Period; $rate++) {
        $failures = 0;

        for ($sequence = 0; $sequence < FailureSchedule::Period; $sequence++) {
            if (FailureSchedule::fails($sequence, $rate)) {
                $failures++;
            }
        }

        expect($failures)->toBe($rate, "rate {$rate} produced {$failures} failures per period");
    }
});

it('holds the exact count in every later period, not only the first', function (): void {
    $failures = 0;

    // Periods 40 through 44 — far enough in that a schedule which only works
    // from a cold start would have drifted.
    for ($sequence = 4000; $sequence < 4500; $sequence++) {
        if (FailureSchedule::fails($sequence, 30)) {
            $failures++;
        }
    }

    expect($failures)->toBe(150);
});

it('spreads failures through the period instead of grouping them at the start', function (): void {
    $failing = array_values(array_filter(
        range(0, 9),
        static fn (int $sequence): bool => FailureSchedule::fails($sequence, 30),
    ));

    // A plain `$sequence % Period < $failuresPerPeriod` would have given
    // [0, 1, 2] here — three failures in a block, then a long clean run.
    expect($failing)->toBe([0, 4, 7]);
});

it('never fails at a rate of zero', function (): void {
    for ($sequence = 0; $sequence < 500; $sequence++) {
        expect(FailureSchedule::fails($sequence, 0))->toBeFalse();
    }
});

it('always fails at a rate of one', function (): void {
    for ($sequence = 0; $sequence < 500; $sequence++) {
        expect(FailureSchedule::fails($sequence, FailureSchedule::Period))->toBeTrue();
    }
});

it('answers the same way for the same sequence number', function (): void {
    foreach ([0, 1, 7, 42, 99, 1_000_003] as $sequence) {
        expect(FailureSchedule::fails($sequence, 30))
            ->toBe(FailureSchedule::fails($sequence, 30));
    }
});

it('converts a wire fail_rate to whole requests per period', function (float $rate, int $expected): void {
    expect(FailureSchedule::failuresPerPeriod($rate))->toBe($expected);
})->with([
    'none' => [0.0, 0],
    'three in a hundred' => [0.03, 3],
    'the protocol\'s degraded rate' => [0.3, 30],
    'half' => [0.5, 50],
    'every request' => [1.0, 100],
    'rounded to the nearest whole percent' => [0.307, 31],
]);
