<?php

declare(strict_types=1);

namespace App\Support\Delivery;

use App\Enums\AttemptOutcome;

/**
 * What the transport actually observed, and nothing it did not. Whether a
 * received status counts as a success is a business rule (2xx, per
 * CLAUDE.md) that belongs to AttemptDelivery, not here — this only reports
 * that a response arrived, or that one never did and why.
 */
final readonly class TransportResult
{
    /**
     * @param  array<string, string>|null  $headers
     * @param  AttemptOutcome::Timeout|AttemptOutcome::DnsError|AttemptOutcome::TlsError|AttemptOutcome::ConnectionError|null  $failure
     */
    private function __construct(
        public bool $responded,
        public int $durationMs,
        public ?int $status = null,
        public ?array $headers = null,
        public ?string $body = null,
        public ?AttemptOutcome $failure = null,
        public ?string $errorMessage = null,
    ) {}

    /**
     * @param  array<string, string>  $headers
     */
    public static function responded(int $status, array $headers, string $body, int $durationMs): self
    {
        return new self(responded: true, durationMs: $durationMs, status: $status, headers: $headers, body: $body);
    }

    /**
     * @param  AttemptOutcome::Timeout|AttemptOutcome::DnsError|AttemptOutcome::TlsError|AttemptOutcome::ConnectionError  $failure
     */
    public static function failed(AttemptOutcome $failure, string $errorMessage, int $durationMs): self
    {
        return new self(responded: false, durationMs: $durationMs, failure: $failure, errorMessage: $errorMessage);
    }
}
