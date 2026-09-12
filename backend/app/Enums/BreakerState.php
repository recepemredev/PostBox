<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where one endpoint's circuit breaker stands, and the one place that says
 * which moves between those states are legal. Closed is traffic as normal.
 * Open is a refusal to send at all, for a duration the config names. Half-open
 * is the probe: exactly one request is let through to ask whether the
 * endpoint has recovered, and its outcome is the only thing that can close the
 * breaker again — CLAUDE.md's "re-enabled only through the half-open probe
 * path" is this enum plus that rule, nothing more.
 *
 * The table is deliberately small: a breaker never jumps straight from open to
 * closed, and closed never goes anywhere except open. Anything not listed
 * here — including a state transitioning to itself — is rejected by
 * TransitionBreaker rather than silently accepted.
 */
enum BreakerState: string
{
    case Closed = 'closed';

    case Open = 'open';

    case HalfOpen = 'half_open';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Closed => [self::Open],
            self::Open => [self::HalfOpen],
            self::HalfOpen => [self::Closed, self::Open],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), strict: true);
    }
}
