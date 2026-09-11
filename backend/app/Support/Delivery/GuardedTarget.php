<?php

declare(strict_types=1);

namespace App\Support\Delivery;

/**
 * The one address AddressGuard actually checked, and the port a request
 * should reach it on. The outbound transport (Step 6) pins its connection to
 * this address rather than letting the HTTP client resolve the host a second
 * time — a second resolution is exactly the window a DNS-rebinding attack
 * needs, and this type is what makes "resolve once, connect to what was
 * checked" a fact the transport cannot get wrong by accident.
 */
final readonly class GuardedTarget
{
    public function __construct(
        public string $host,
        public int $port,
        public string $address,
    ) {}
}
