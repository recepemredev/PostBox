<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Which requests in a sequence the sink answers with a failure.
 *
 * The obvious implementation is a random draw against `fail_rate`, and it is
 * the wrong one twice over. A load run stops being reproducible — two runs of
 * the same committed script produce different retry queue depths and different
 * breaker activations, which is precisely what Phase 5 of benchmarking.md
 * exists to measure. And "the sink behaves exactly as configured" degrades
 * from an assertion into a statistical one: a test can only say the ratio is
 * near 0.3, never that it is 0.3.
 *
 * So the decision is arithmetic rather than random. Over any window of
 * `Period` consecutive requests exactly `$failuresPerPeriod` of them fail, and
 * which ones is fixed by the sequence number alone.
 *
 * The multiples of $failuresPerPeriod modulo 100 land on multiples of
 * gcd($failuresPerPeriod, 100), each hit exactly gcd times per period, and the
 * ones below $failuresPerPeriod are exactly $failuresPerPeriod/gcd of them —
 * so the count per period is exact for every rate, not only for rates that
 * divide 100. Multiplying before the modulo is also what spreads the failures
 * through the window instead of grouping them in a block at the start, which a
 * plain `$sequence % Period < $failuresPerPeriod` would do.
 */
final readonly class FailureSchedule
{
    /**
     * The window a rate is exact over. 100 is also what makes the wire format
     * a percentage: `fail_rate=0.3` is 30 failures per 100 requests, and no
     * rate finer than a whole percent is expressible — a deliberate limit, so
     * the number in a committed k6 script and the number the sink applies are
     * the same number.
     */
    public const int Period = 100;

    public static function fails(int $sequence, int $failuresPerPeriod): bool
    {
        if ($failuresPerPeriod <= 0) {
            return false;
        }

        if ($failuresPerPeriod >= self::Period) {
            return true;
        }

        return ($sequence * $failuresPerPeriod) % self::Period < $failuresPerPeriod;
    }

    /**
     * A `fail_rate` on the wire is a fraction; the schedule counts whole
     * requests. Rounding happens here, once, so nothing downstream carries a
     * float.
     */
    public static function failuresPerPeriod(float $failRate): int
    {
        return (int) round($failRate * self::Period);
    }
}
