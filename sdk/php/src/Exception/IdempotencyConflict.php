<?php

declare(strict_types=1);

namespace PostBox\Exception;

/**
 * 409: this Idempotency-Key was already used for a request with a different
 * body. Reached only when a caller reuses a key by hand across two distinct
 * publish() calls — PostBox::publish() never repeats one across attempts
 * with a different body, since the body is fixed before the first attempt.
 */
final class IdempotencyConflict extends PostBoxException
{
    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(string $message, array $headers = [])
    {
        parent::__construct($message, 409, $headers);
    }
}
