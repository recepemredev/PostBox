<?php

declare(strict_types=1);

namespace App\Support\Catalog;

use App\Models\EndpointSecret;

/**
 * A secret and the one moment its plaintext is handed to a caller rather than
 * read back out of storage. Unlike IssuedApiKey the row itself *can* produce
 * the plaintext again later — `secret` is an `encrypted` cast, not a hash,
 * because signing needs it back — so this pair exists to keep that
 * possibility out of every response but the one that creates it, not because
 * the database could not otherwise reproduce it.
 */
final readonly class IssuedEndpointSecret
{
    public function __construct(public EndpointSecret $secret, public string $plaintext) {}
}
