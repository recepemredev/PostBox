<?php

declare(strict_types=1);

namespace App\Support\Health;

/**
 * The two states a probe — or the system as a whole — can be in.
 *
 * There is no "warning" state on purpose: a health endpoint that can be partly
 * green gives an orchestrator nothing to act on.
 */
enum HealthStatus: string
{
    case Ok = 'ok';
    case Degraded = 'degraded';
}
