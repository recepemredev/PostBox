<?php

declare(strict_types=1);

namespace PostBox\Exception;

/**
 * A status the ingest endpoint's contract does not document. Never retried:
 * an unrecognised status is treated the same as a terminal one, on the
 * principle that a status this SDK cannot classify is not one it can decide
 * is safe to repeat.
 */
final class UnexpectedResponse extends PostBoxException
{
    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(string $message, int $status, array $headers = [])
    {
        parent::__construct($message, $status, $headers);
    }
}
