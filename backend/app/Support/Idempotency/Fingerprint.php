<?php

declare(strict_types=1);

namespace App\Support\Idempotency;

/**
 * What "the same request" means for anything reserved behind a client-supplied
 * key: a digest over whatever facts decide the outcome, and a constant-time
 * comparison against one stored earlier. Which facts belong in the digest is
 * the caller's question, not this class's — Ingest's publish reservation and
 * Recovery's replay reservation each assemble their own parts, and neither's
 * parts mean anything to the other (D75).
 */
final readonly class Fingerprint
{
    private function __construct(private string $digest) {}

    public static function of(string ...$parts): self
    {
        return new self(hash('sha256', implode("\n", $parts)));
    }

    public function matches(string $stored): bool
    {
        return hash_equals($this->digest, $stored);
    }

    public function toString(): string
    {
        return $this->digest;
    }
}
