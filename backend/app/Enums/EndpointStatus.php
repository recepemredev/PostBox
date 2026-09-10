<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Whether a tenant wants deliveries sent to an endpoint at all.
 *
 * This is the operator's switch, not the circuit breaker's. The breaker (Step 8)
 * holds its own state and pauses an endpoint that keeps failing; this column
 * records a deliberate choice a person made. An endpoint can be enabled here and
 * still not receiving traffic because the breaker is open.
 */
enum EndpointStatus: string
{
    case Enabled = 'enabled';

    case Disabled = 'disabled';
}
