<?php

declare(strict_types=1);

namespace App\Support\Governor;

/**
 * The one shape both Governor mechanisms answer in. A token bucket and a period
 * quota are different storage and different arithmetic, but a middleware that
 * has to enforce both and header both should not have to know that — it asks
 * each for a decision and gets the same four facts back.
 */
final readonly class LimitDecision
{
    public function __construct(
        public bool $allowed,
        public int $limit,
        public int $remaining,
        public int $resetSeconds,
        public ?int $retryAfterSeconds = null,
    ) {}

    /**
     * @return array<string, string>
     */
    public function headers(string $prefix): array
    {
        return [
            "{$prefix}-Limit" => (string) $this->limit,
            "{$prefix}-Remaining" => (string) $this->remaining,
            "{$prefix}-Reset" => (string) $this->resetSeconds,
        ];
    }
}
