<?php

declare(strict_types=1);

namespace App\Support\Recovery;

use App\Models\Replay;

/**
 * A recovery request and whether answering it wrote anything.
 *
 * The distinction is the caller's to present, not the action's — the same
 * split PublishedMessage draws for a fresh publish against a replayed one.
 */
final readonly class ReplayResult
{
    public function __construct(public Replay $replay, public bool $duplicate) {}
}
