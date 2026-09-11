<?php

declare(strict_types=1);

namespace App\Support\Ingest;

use App\Models\Application;

/**
 * What "the same request" means for an idempotency key.
 *
 * A key is only a promise about a request that does not change, so the digest
 * covers everything that decides what is published: the application it is
 * published into, the event type, and the payload. The payload is re-encoded
 * from the parsed request rather than hashed as it arrived, so formatting — a
 * pretty-printed retry of a compact original — is not mistaken for a different
 * request. Key order within the payload is not normalised: two objects with the
 * same pairs in a different order are a conflict, which is the safe answer, and
 * every JSON encoder a producer is likely to use is stable between runs.
 */
final readonly class RequestFingerprint
{
    private function __construct(private string $digest) {}

    /**
     * @param  array<array-key, mixed>  $payload
     */
    public static function of(Application $application, string $eventType, array $payload): self
    {
        return new self(hash('sha256', implode("\n", [
            $application->public_id,
            $eventType,
            (string) json_encode($payload),
        ])));
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
