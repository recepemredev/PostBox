<?php

declare(strict_types=1);

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/**
 * The tenant's concurrent-stream cap (StreamSlots, D129) is already full.
 * A sibling of RateLimitExceeded rather than a reuse of it: that class
 * carries a Governor LimitDecision, which does not fit a stream slot
 * without contortion — different mechanism, different storage, different
 * headers (CLAUDE.md's "rate limit and quota are separate mechanisms"
 * reasoning, applied a third time). Thrown rather than built by hand as a
 * bare response, so it renders through the same exception handler every
 * other 429/402/409 in this application does, with the same {"message":
 * ...} JSON body DescribeEventStream documents for it.
 */
final class StreamSlotExceeded extends TooManyRequestsHttpException
{
    public function __construct(int $retryAfterSeconds)
    {
        parent::__construct(
            retryAfter: $retryAfterSeconds,
            message: 'Too many concurrent live streams for this tenant.',
        );
    }
}
