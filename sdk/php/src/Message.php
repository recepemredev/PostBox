<?php

declare(strict_types=1);

namespace PostBox;

use JsonException;
use RuntimeException;

/**
 * MessageResource's own shape: `{id, event_type, source, created_at}`. The
 * payload and any delivery are deliberately not part of it — the ingest
 * response never carries them either.
 */
final readonly class Message
{
    public function __construct(
        public string $id,
        public string $eventType,
        public string $source,
        public string $createdAt,
        public bool $replayed,
    ) {}

    /**
     * @throws JsonException
     */
    public static function fromResponseBody(string $body, bool $replayed): self
    {
        /** @var mixed $decoded */
        $decoded = json_decode($body, associative: true, flags: JSON_THROW_ON_ERROR);

        if (
            ! is_array($decoded)
            || ! is_string($decoded['id'] ?? null)
            || ! is_string($decoded['event_type'] ?? null)
            || ! is_string($decoded['source'] ?? null)
            || ! is_string($decoded['created_at'] ?? null)
        ) {
            throw new RuntimeException('PostBox answered 200/201 with a body that is not a message resource.');
        }

        return new self($decoded['id'], $decoded['event_type'], $decoded['source'], $decoded['created_at'], $replayed);
    }
}
