<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Support\Governor\LimitDecision;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The tenant's period quota is spent. 402 rather than another 429: the rate
 * limit and the quota are deliberately different mechanisms, and the status
 * code is the one place that difference is visible without reading a header.
 * There is no Retry-After here — a quota does not clear on a countdown, it
 * clears on the calendar, and Quota-Reset already says when.
 *
 * The rate limit decision travels alongside the quota one because, by the time
 * a request reaches here, it has already been judged against both — a token
 * was genuinely spent, and the response reports it rather than dropping it on
 * the floor because a different mechanism is the one that refused.
 */
final class QuotaExhausted extends HttpException
{
    public function __construct(LimitDecision $rate, LimitDecision $quota)
    {
        parent::__construct(
            statusCode: 402,
            message: "This tenant's quota for the current billing period has been exhausted.",
            headers: [...$rate->headers('RateLimit'), ...$quota->headers('Quota')],
        );
    }
}
