<?php

declare(strict_types=1);

namespace PostBox\Exception;

/**
 * 402: this tenant's cumulative quota for the current billing period is
 * exhausted. Never retryable within a run — the period resets on a clock,
 * not on a delay this SDK could wait out.
 */
final class QuotaExhausted extends PostBoxException
{
    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(string $message, array $headers = [])
    {
        parent::__construct($message, 402, $headers);
    }
}
