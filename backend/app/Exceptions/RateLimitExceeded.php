<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Support\Governor\LimitDecision;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/**
 * The tenant's token bucket was empty. Retry-After and the RateLimit-* headers
 * both come from the decision EnforceLimits already computed — nothing here
 * recomputes anything, it only carries that answer onto the response.
 */
final class RateLimitExceeded extends TooManyRequestsHttpException
{
    public function __construct(LimitDecision $decision)
    {
        parent::__construct(
            retryAfter: $decision->retryAfterSeconds,
            message: 'The rate limit for this tenant has been exceeded.',
            headers: $decision->headers('RateLimit'),
        );
    }
}
