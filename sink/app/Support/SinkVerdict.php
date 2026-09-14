<?php

declare(strict_types=1);

namespace App\Support;

/**
 * What the sink decided about one request: the status to answer with, and the
 * sequence number that decided it.
 *
 * The sequence travels back to the caller in a response header, and PostBox
 * records response headers on every delivery attempt. That makes the schedule
 * auditable from the attempt log itself — a failed delivery in a benchmark run
 * can be tied to the exact position in the sink's sequence that produced it,
 * without the sink keeping any log of its own.
 */
final readonly class SinkVerdict
{
    public function __construct(
        public int $status,
        public int $sequence,
    ) {}
}
