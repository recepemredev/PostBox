<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The sink's position in its own failure schedule.
 *
 * Registered as a container singleton, which under Octane means it survives
 * between requests and advances across them — that persistence is the whole
 * reason the schedule can be deterministic rather than random. It is also
 * per-worker: with N Octane workers there are N independent sequences, so a
 * configured rate is exact per worker and therefore exact in aggregate, but
 * the interleaving of which request fails is not fixed across workers. Nothing
 * in the protocol depends on that ordering; the per-period count is what
 * Phase 5 reads.
 *
 * Not shared through Redis. The sink is the thing every PostBox figure is
 * measured against, and a network round trip per request would make it measure
 * Redis.
 */
final class RequestSequence
{
    private int $position = 0;

    /**
     * The current position, then advance. The first request of a worker's life
     * is sequence 0, which is the first failure of any non-zero rate — a load
     * run therefore starts failing immediately rather than after a warm-up.
     */
    public function next(): int
    {
        return $this->position++;
    }
}
