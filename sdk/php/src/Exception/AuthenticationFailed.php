<?php

declare(strict_types=1);

namespace PostBox\Exception;

/**
 * 401: the API key is missing, malformed, revoked, expired or belongs to
 * another tenant. PostBox answers all five the same way on purpose, so this
 * exception carries whatever message it was given rather than guessing why.
 */
final class AuthenticationFailed extends PostBoxException
{
    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(string $message, array $headers = [])
    {
        parent::__construct($message, 401, $headers);
    }
}
