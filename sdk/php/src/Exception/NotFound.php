<?php

declare(strict_types=1);

namespace PostBox\Exception;

/**
 * 404: no such application for this tenant. Retrying with the same
 * application id would only repeat the same 404.
 */
final class NotFound extends PostBoxException
{
    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(string $message, array $headers = [])
    {
        parent::__construct($message, 404, $headers);
    }
}
