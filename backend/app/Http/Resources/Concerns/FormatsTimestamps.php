<?php

declare(strict_types=1);

namespace App\Http\Resources\Concerns;

use Carbon\CarbonInterface;

/**
 * Every timestamp this API emits is UTC and ISO-8601, with the Z spelled out.
 * The convention is one line long, which is exactly why it lives in one place: a
 * resource that spelled it out for itself would be where it eventually drifts.
 */
trait FormatsTimestamps
{
    protected static function utc(?CarbonInterface $moment): ?string
    {
        return $moment?->utc()->toIso8601ZuluString();
    }
}
