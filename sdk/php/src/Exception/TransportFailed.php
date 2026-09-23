<?php

declare(strict_types=1);

namespace PostBox\Exception;

use Throwable;

/**
 * The request never produced a response at all — DNS, TLS, a connection
 * refused, a timeout inside the injected PSR-18 client. Status is 0: there
 * was no HTTP status to carry. publish() retries this one; it only reaches
 * a caller once every retry attempt has also failed to reach the server.
 */
final class TransportFailed extends PostBoxException
{
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, [], $previous);
    }
}
