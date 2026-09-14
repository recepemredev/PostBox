<?php

declare(strict_types=1);

namespace App\Support\Recovery;

use App\Models\Replay;

/**
 * A recovery request and whether answering it wrote anything.
 *
 * The distinction is the caller's to present, not the action's — the same
 * split PublishedMessage draws for a fresh publish against a replayed one.
 *
 * nextCursor is null whenever there is nothing left to page through, and
 * always null for a duplicate: the cursor belongs to the fresh write that
 * computed it, not to the receipt a repeated idempotent call hands back —
 * that receipt's own deliveries no longer match a fresh query for the same
 * scope, since every one of them already carries this replay's own id.
 */
final readonly class ReplayResult
{
    public function __construct(public Replay $replay, public bool $duplicate, public ?string $nextCursor = null) {}
}
