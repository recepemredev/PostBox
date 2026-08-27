<?php

declare(strict_types=1);

namespace App\Support\Health;

/**
 * The aggregate of every probe in one run.
 *
 * One degraded probe degrades the whole report: the endpoint answers "can this
 * instance serve traffic", and a partly working instance cannot.
 */
final readonly class HealthReport
{
    /**
     * @param  list<CheckResult>  $checks
     */
    public function __construct(
        public array $checks,
        public int $durationMs,
    ) {}

    public function status(): HealthStatus
    {
        foreach ($this->checks as $check) {
            if ($check->status === HealthStatus::Degraded) {
                return HealthStatus::Degraded;
            }
        }

        return HealthStatus::Ok;
    }
}
