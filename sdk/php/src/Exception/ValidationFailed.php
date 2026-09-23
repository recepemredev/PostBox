<?php

declare(strict_types=1);

namespace PostBox\Exception;

/**
 * 422: event_type is not registered for this tenant, payload is missing or
 * empty, payload exceeds the size ceiling, or the Idempotency-Key header
 * carries a control character. $errors mirrors the field => messages shape
 * Laravel's validator renders.
 */
final class ValidationFailed extends PostBoxException
{
    /**
     * @param  array<string, list<string>>  $errors
     * @param  array<string, string>  $headers
     */
    public function __construct(
        string $message,
        public readonly array $errors,
        array $headers = [],
    ) {
        parent::__construct($message, 422, $headers);
    }
}
