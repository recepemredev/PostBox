<?php

declare(strict_types=1);

namespace App\Support\Delivery;

/**
 * The outbound transport, one of the two pre-approved boundary interfaces
 * (architecture.md) — it exists so a delivery attempt is deterministic under
 * test, not because a second implementation is coming. GuzzleTransport is the
 * only one that ships; tests substitute a double bound to this contract
 * instead of making a real HTTP call.
 */
interface HttpTransport
{
    public function send(OutboundRequest $request): TransportResult;
}
