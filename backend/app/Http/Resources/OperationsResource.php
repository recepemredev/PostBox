<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\EndpointCircuitBreaker;
use App\Support\Operations\OperationsSnapshot;
use App\Support\Operations\QueueWorkload;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read OperationsSnapshot $resource
 */
final class OperationsResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'queues' => array_map($this->presentQueue(...), $this->resource->queues),
            'breakers' => [
                'closed' => $this->resource->breakerCounts->closed,
                'open' => $this->resource->breakerCounts->open,
                'half_open' => $this->resource->breakerCounts->halfOpen,
                'tripped' => $this->resource->trippedBreakers->map($this->presentBreaker(...))->all(),
            ],
        ];
    }

    /**
     * @return array<string, int|string>
     */
    private function presentQueue(QueueWorkload $queue): array
    {
        return [
            'name' => $queue->name,
            'length' => $queue->length,
            'wait_ms' => $queue->waitMs,
            'processes' => $queue->processes,
            'runtime_ms' => $queue->runtimeMs,
            'throughput' => $queue->throughput,
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private function presentBreaker(EndpointCircuitBreaker $breaker): array
    {
        return [
            'endpoint_id' => $breaker->endpoint->public_id,
            'endpoint_name' => $breaker->endpoint->name,
            'state' => $breaker->state->value,
            'state_changed_at' => $breaker->state_changed_at->toIso8601ZuluString(),
        ];
    }
}
