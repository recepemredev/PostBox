<?php

declare(strict_types=1);

namespace PostBox\Exception;

/**
 * 5xx: PostBox itself failed to process the request. publish() retries this
 * one; it only reaches a caller once every retry attempt has also failed.
 */
final class ServerError extends PostBoxException
{
    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(string $message, int $status, array $headers = [])
    {
        parent::__construct($message, $status, $headers);
    }
}
