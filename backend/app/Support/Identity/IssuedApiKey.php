<?php

declare(strict_types=1);

namespace App\Support\Identity;

use App\Models\ApiKey;

/**
 * A key and the one moment its secret exists outside the caller's hands. The pair
 * is deliberately not a model attribute: nothing that is written to the database
 * should be able to carry the plaintext by accident.
 */
final readonly class IssuedApiKey
{
    public function __construct(public ApiKey $key, public string $token) {}
}
