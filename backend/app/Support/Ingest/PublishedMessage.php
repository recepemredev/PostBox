<?php

declare(strict_types=1);

namespace App\Support\Ingest;

use App\Models\Message;

/**
 * An accepted event and whether accepting it wrote anything.
 *
 * The distinction is the caller's to present, not the action's: a replay and a
 * first publish return the same message, and only the HTTP layer cares that one
 * of them is a 200 with a replay header rather than a 201.
 */
final readonly class PublishedMessage
{
    public function __construct(public Message $message, public bool $replayed) {}
}
