<?php

declare(strict_types=1);

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * The same idempotency key, a different request.
 *
 * This is the one case where a key cannot be honoured: returning the original
 * message would answer a question the producer did not ask, and publishing the
 * new one would break the promise the key was given for. The key itself is not
 * echoed back — it is client-supplied text, and the producer already knows it.
 */
final class IdempotencyConflict extends ConflictHttpException
{
    public function __construct()
    {
        parent::__construct('This Idempotency-Key was already used for a request with a different body.');
    }
}
