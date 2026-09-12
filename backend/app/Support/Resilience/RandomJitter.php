<?php

declare(strict_types=1);

namespace App\Support\Resilience;

use Random\Randomizer;

/**
 * The shipped jitter source. A retry delay is not a secret and nothing about
 * it needs to resist prediction, but PHP 8.3's Randomizer is the current way
 * to ask for a random float in a range at all — `getFloat()` is uniform across
 * the closed interval without the modulo bias hand-rolled arithmetic over an
 * integer generator invites.
 */
final readonly class RandomJitter implements JitterSource
{
    public function __construct(private Randomizer $randomizer = new Randomizer) {}

    public function fraction(): float
    {
        return $this->randomizer->getFloat(0.0, 1.0);
    }
}
