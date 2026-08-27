<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Health\CheckSystemHealth;
use App\Support\Health\HealthStatus;
use Illuminate\Console\Command;

/**
 * The container healthcheck for every PHP service.
 *
 * It runs the same action as `GET /api/health`, so a container is only ever
 * reported healthy under exactly the conditions the endpoint reports healthy.
 */
final class HealthCheckCommand extends Command
{
    protected $signature = 'postbox:health';

    protected $description = 'Probe the database, Redis, the queue backend and migration state.';

    public function handle(CheckSystemHealth $checkSystemHealth): int
    {
        $report = $checkSystemHealth->handle();

        foreach ($report->checks as $check) {
            $this->line(sprintf(
                '%-12s %-8s %dms%s',
                $check->name,
                $check->status->value,
                $check->durationMs,
                $check->detail === null ? '' : '  '.$check->detail,
            ));
        }

        return $report->status() === HealthStatus::Ok
            ? self::SUCCESS
            : self::FAILURE;
    }
}
