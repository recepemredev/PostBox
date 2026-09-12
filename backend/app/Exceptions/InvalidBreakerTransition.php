<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\BreakerState;
use RuntimeException;

/**
 * Something asked TransitionBreaker for a move BreakerState does not allow —
 * closed straight to half-open, open straight to closed, either state to
 * itself. This is a defect in the caller, not an operational condition: the
 * legal graph is small and every real caller in this codebase only ever asks
 * for one of its edges, so reaching here means a future caller got the
 * direction wrong.
 */
final class InvalidBreakerTransition extends RuntimeException
{
    public static function from(BreakerState $from, BreakerState $to): self
    {
        return new self("Cannot transition a breaker from [{$from->value}] to [{$to->value}].");
    }
}
