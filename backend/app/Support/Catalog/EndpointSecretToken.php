<?php

declare(strict_types=1);

namespace App\Support\Catalog;

use Illuminate\Support\Str;

/**
 * The plaintext form of an endpoint's signing secret: `whsec_<random>`.
 *
 * Unlike ApiKeyToken this carries no tenant — a signing secret is never
 * presented back to PostBox as a credential to authenticate with, only used
 * locally to sign an outbound request, so there is nothing here for a lookup
 * to establish a tenant from.
 */
final readonly class EndpointSecretToken
{
    private const PREFIX = 'whsec';

    private const SECRET_LENGTH = 40;

    private function __construct(public string $secret) {}

    public static function generate(): self
    {
        return new self(Str::random(self::SECRET_LENGTH));
    }

    public function toString(): string
    {
        return self::PREFIX.'_'.$this->secret;
    }

    public function lastFour(): string
    {
        return substr($this->secret, -4);
    }
}
