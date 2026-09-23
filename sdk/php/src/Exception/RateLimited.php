<?php

declare(strict_types=1);

namespace PostBox\Exception;

/**
 * 429: the token bucket for this tenant is empty. PostBox::publish() retries
 * this one itself, honouring Retry-After — it only reaches a caller once
 * every retry attempt has also been rate limited.
 */
final class RateLimited extends PostBoxException
{
    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        string $message,
        public readonly ?int $retryAfter,
        array $headers = [],
    ) {
        parent::__construct($message, 429, $headers);
    }
}
