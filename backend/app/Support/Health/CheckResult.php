<?php

declare(strict_types=1);

namespace App\Support\Health;

/**
 * The outcome of a single dependency probe.
 *
 * `$detail` carries the failure reason and is never rendered outside debug mode —
 * a connection string or driver error is internal detail.
 */
final readonly class CheckResult
{
    public function __construct(
        public string $name,
        public HealthStatus $status,
        public int $durationMs,
        public ?string $detail = null,
    ) {}
}
