<?php

declare(strict_types=1);

namespace PostBox\Exception;

use RuntimeException;
use Throwable;

/**
 * Every error this SDK throws carries the HTTP status PostBox answered with
 * (0 for a transport-level failure that never reached a response) and the
 * response headers, so a caller can inspect either without parsing the
 * message string. One subclass per status the ingest endpoint is documented
 * to return.
 */
abstract class PostBoxException extends RuntimeException
{
    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        string $message,
        public readonly int $status,
        public readonly array $headers = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }
}
