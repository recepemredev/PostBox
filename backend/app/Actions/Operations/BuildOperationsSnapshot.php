<?php

declare(strict_types=1);

namespace App\Actions\Operations;

use App\Enums\BreakerState;
use App\Models\Endpoint;
use App\Models\EndpointCircuitBreaker;
use App\Support\Operations\BreakerCounts;
use App\Support\Operations\OperationsSnapshot;
use App\Support\Operations\QueueWorkload;
use Illuminate\Support\Facades\Config;
use Laravel\Horizon\Contracts\MetricsRepository;
use Laravel\Horizon\Contracts\WorkloadRepository;

/**
 * The operations screen's one read, assembled from two independent sources:
 * Horizon's own repositories for the three delivery queues (shared
 * infrastructure, scoped to no tenant), and this tenant's own breakers for
 * the rest. Nothing here writes anything — every dependency is a read-only
 * repository, the same shape CheckSystemHealth's own probes take.
 *
 * An operator dashboard, not the incident timeline: at most 50 tripped
 * breakers, most recently changed first — the same default BreakerCommand's
 * own `--limit` option takes for the same shell-sized audience.
 */
final readonly class BuildOperationsSnapshot
{
    private const int TRIPPED_LIMIT = 50;

    public function __construct(
        private WorkloadRepository $workload,
        private MetricsRepository $metrics,
    ) {}

    public function handle(): OperationsSnapshot
    {
        return new OperationsSnapshot(
            queues: $this->queues(),
            breakerCounts: $this->breakerCounts(),
            trippedBreakers: EndpointCircuitBreaker::query()->tripped()->with('endpoint')->limit(self::TRIPPED_LIMIT)->get(),
        );
    }

    /**
     * @return list<QueueWorkload>
     */
    private function queues(): array
    {
        $byName = [];

        foreach ($this->workload->get() as $row) {
            $byName[$row['name']] = $row;
        }

        return array_values(array_map(
            fn (string $name): QueueWorkload => $this->workloadFor($name, $byName[$name] ?? null),
            $this->queueNames(),
        ));
    }

    /**
     * @return list<string>
     */
    private function queueNames(): array
    {
        return [
            Config::string('postbox.delivery.queue'),
            Config::string('postbox.delivery.retry_queue'),
            // Provisioned in config/horizon.php but carries no traffic before
            // Step 8 wired anything to push to it — a plain literal here,
            // the same way horizon.php's own supervisors name it, rather
            // than a config key invented for a value nothing else reads.
            'maintenance',
        ];
    }

    /**
     * @param  array{name: string, length: int, wait: float, processes: int, split_queues: mixed}|null  $row
     */
    private function workloadFor(string $name, ?array $row): QueueWorkload
    {
        return new QueueWorkload(
            name: $name,
            length: $row['length'] ?? 0,
            // WorkloadRepository reports wait in seconds, as the float its
            // own round() returns; CLAUDE.md's "durations are integer
            // milliseconds" applies to what this application hands back
            // over HTTP, not to Horizon's own unit.
            waitMs: (int) round(($row['wait'] ?? 0.0) * 1000),
            processes: $row['processes'] ?? 0,
            runtimeMs: (int) round($this->metrics->runtimeForQueue($name)),
            throughput: $this->metrics->throughputForQueue($name),
        );
    }

    private function breakerCounts(): BreakerCounts
    {
        $total = Endpoint::query()->count();
        $open = EndpointCircuitBreaker::query()->where('state', BreakerState::Open)->count();
        $halfOpen = EndpointCircuitBreaker::query()->where('state', BreakerState::HalfOpen)->count();

        return new BreakerCounts(closed: $total - $open - $halfOpen, open: $open, halfOpen: $halfOpen);
    }
}
