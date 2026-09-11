<?php

declare(strict_types=1);

namespace App\Support\Delivery;

/**
 * Everything one delivery attempt needs from the transport: where to connect
 * (target — the address AddressGuard already checked, not a hostname to
 * resolve again), what to send, and how long to wait. A delivery is always a
 * POST; nothing in this project's outbound model has ever needed a second
 * verb, so it is not a field here to configure.
 */
final readonly class OutboundRequest
{
    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public string $url,
        public GuardedTarget $target,
        public array $headers,
        public string $body,
        public int $connectTimeoutMs,
        public int $timeoutMs,
    ) {}
}
