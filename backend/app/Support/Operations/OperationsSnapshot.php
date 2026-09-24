<?php

declare(strict_types=1);

namespace App\Support\Operations;

use App\Models\EndpointCircuitBreaker;
use Illuminate\Database\Eloquent\Collection;

/**
 * The whole operations read, in one shot: the three delivery queues'
 * current workload, and the current tenant's breakers by state.
 */
final readonly class OperationsSnapshot
{
    /**
     * @param  list<QueueWorkload>  $queues
     * @param  Collection<int, EndpointCircuitBreaker>  $trippedBreakers
     */
    public function __construct(
        public array $queues,
        public BreakerCounts $breakerCounts,
        public Collection $trippedBreakers,
    ) {}
}
