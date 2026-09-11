<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * The SSRF guard refused a target before a single byte left the process.
 *
 * This is thrown inside a queue worker, not a request, so it is a plain
 * domain exception rather than an HTTP one — AttemptDelivery (Step 6) catches
 * it and records AttemptOutcome::Blocked with this message as the attempt's
 * error_message, the same way a real network failure is recorded rather than
 * left to surface as an unhandled job failure.
 */
final class BlockedTarget extends RuntimeException
{
    public static function invalidUrl(string $url): self
    {
        return new self("[{$url}] is not an http or https URL with a host.");
    }

    public static function unresolvable(string $host): self
    {
        return new self("The address for [{$host}] could not be resolved.");
    }

    public static function disallowedRange(string $host, string $address): self
    {
        return new self("The address for [{$host}] ({$address}) resolves to a disallowed range.");
    }
}
