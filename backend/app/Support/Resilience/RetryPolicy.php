<?php

declare(strict_types=1);

namespace App\Support\Resilience;

use App\Enums\AttemptOutcome;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;

/**
 * The retry schedule, in one place: attempt count, base delay, growth factor,
 * jitter and ceiling, plus the one classification that decides whether a
 * failure is worth trying again at all. A worker asks this what to do next; it
 * never works out a delay for itself (modules.md).
 *
 * "One place" is the whole point of the class. Both halves of the question —
 * *should* this be retried, and *when* — are answered here, because splitting
 * them would let a caller retry something terminal, or give a terminal failure
 * a delay nobody will ever wait out.
 */
final readonly class RetryPolicy
{
    public function __construct(private JitterSource $jitter) {}

    /**
     * @param  int  $attemptNumber  the attempt that just concluded, counting from 1
     */
    public function decide(
        AttemptOutcome $outcome,
        ?int $responseStatus,
        int $attemptNumber,
        CarbonImmutable $now,
    ): RetryDecision {
        // A success never reaches here: the caller settles it before asking,
        // and a policy that had an opinion about one would be a second place
        // where "2xx means delivered" is written down.
        assert($outcome !== AttemptOutcome::Succeeded);

        if (! self::isRetryable($outcome, $responseStatus)) {
            return RetryDecision::stop(self::terminalReason($responseStatus));
        }

        if ($attemptNumber >= Config::integer('postbox.retry.max_attempts')) {
            return RetryDecision::stop(self::exhaustedReason($outcome, $attemptNumber));
        }

        return RetryDecision::retryAt($now->addMilliseconds($this->delayMs($attemptNumber)));
    }

    /**
     * Whether trying the same request again could plausibly end differently.
     *
     * A response the endpoint actually produced is the only outcome that can be
     * terminal: 4xx means the endpoint understood the request and rejected it,
     * and sending the identical bytes seven more times will not change its mind.
     * 408 and 429 are the two exceptions — both are the endpoint asking for the
     * same request later — and anything at or above 500 is the endpoint saying
     * the failure was its own. A 1xx or a 3xx reaching this point is an endpoint
     * that is not a webhook receiver at all, which no amount of waiting fixes.
     *
     * Everything else is retryable. A timeout, a DNS failure, a TLS failure and
     * a refused connection are all facts about a network at one instant. So, for
     * a less obvious reason, is Blocked: PostBox refused to send because the
     * endpoint had no active secret or resolved into a disallowed range, and
     * both are things an operator can fix while the delivery is still in flight.
     * It is counted like any other failure, so a misconfiguration that is never
     * fixed dead-letters instead of retrying forever.
     */
    private static function isRetryable(AttemptOutcome $outcome, ?int $responseStatus): bool
    {
        if ($outcome !== AttemptOutcome::Failed) {
            return true;
        }

        return $responseStatus === 408
            || $responseStatus === 429
            || ($responseStatus !== null && $responseStatus >= 500);
    }

    /**
     * Equal jitter over an exponential base: half the delay is fixed, half is
     * spread. The exponent is applied by multiplying and clamping on each step
     * rather than with `**`, so the arithmetic stays in range however high the
     * attempt number climbs — the clamp is what bounds it, not the exponent.
     */
    private function delayMs(int $attemptNumber): int
    {
        $ceiling = Config::integer('postbox.retry.ceiling_ms');
        $factor = Config::integer('postbox.retry.growth_factor');

        $delay = min(Config::integer('postbox.retry.base_delay_ms'), $ceiling);

        for ($step = 1; $step < $attemptNumber && $delay < $ceiling; $step++) {
            $delay = min($delay * $factor, $ceiling);
        }

        $fixed = intdiv($delay, 2);

        return $fixed + (int) round($this->jitter->fraction() * $fixed);
    }

    private static function terminalReason(?int $responseStatus): string
    {
        return 'terminal response: '.($responseStatus ?? 'none');
    }

    private static function exhaustedReason(AttemptOutcome $outcome, int $attempts): string
    {
        return "exhausted after {$attempts} attempts; last outcome: {$outcome->value}";
    }
}
