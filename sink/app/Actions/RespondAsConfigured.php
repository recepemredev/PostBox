<?php

declare(strict_types=1);

namespace App\Actions;

use App\Support\FailureSchedule;
use App\Support\RequestSequence;
use App\Support\SinkVerdict;

/**
 * One request, answered the way the URL asked for: after the configured delay,
 * with either success or the configured failure status.
 *
 * The delay is a blocking sleep and is meant to be. A slow receiver occupies a
 * worker for as long as it is slow, and Phase 5 of benchmarking.md measures
 * what that does to PostBox — whether a slow endpoint starves a healthy one.
 * A sleep that yielded the worker would simulate a receiver nobody has.
 */
final readonly class RespondAsConfigured
{
    /**
     * What a request that is not scheduled to fail is answered with. Fixed
     * rather than configurable: `status` configures failure, and a sink that
     * could be told to answer success with 201 would only add a dimension no
     * phase of the protocol varies.
     */
    public const int SuccessStatus = 200;

    public function __construct(private RequestSequence $sequence) {}

    public function handle(int $delayMilliseconds, int $failuresPerPeriod, int $failureStatus): SinkVerdict
    {
        $this->pause($delayMilliseconds);

        $position = $this->sequence->next();

        return new SinkVerdict(
            status: FailureSchedule::fails($position, $failuresPerPeriod) ? $failureStatus : self::SuccessStatus,
            sequence: $position,
        );
    }

    private function pause(int $milliseconds): void
    {
        if ($milliseconds > 0) {
            usleep($milliseconds * 1000);
        }
    }
}
