<?php

declare(strict_types=1);

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * The same idempotency key, a different request.
 *
 * This is the one case where a key cannot be honoured: returning the original
 * result would answer a question the caller did not ask, and doing the new
 * thing would break the promise the key was given for. The key itself is not
 * echoed back — it is client-supplied text, and the caller already knows it.
 *
 * Shared between Ingest's publish reservation and Recovery's replay
 * reservation (D77): both need exactly this refusal, worded for what each
 * one's key actually reserves.
 */
final class IdempotencyConflict extends ConflictHttpException
{
    public function __construct(string $message = 'This Idempotency-Key was already used for a request with a different body.')
    {
        parent::__construct($message);
    }
}
