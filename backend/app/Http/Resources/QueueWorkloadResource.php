<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Support\Operations\QueueWorkload;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One queue's row in the operations screen's response. A resource of its
 * own, rather than an array built inline in OperationsResource, because
 * Scramble resolves a nested resource's own shape through `::collection()`
 * — the same way every other list in this codebase is typed — where a
 * plain `array_map()` gives up and describes the list as unknown items
 * (HealthResource's own "checks" already carries that gap).
 *
 * @property-read QueueWorkload $resource
 */
final class QueueWorkloadResource extends JsonResource
{
    /**
     * @return array{name: string, length: int, wait_ms: int, processes: int, runtime_ms: int, throughput: int}
     */
    public function toArray(Request $request): array
    {
        return [
            'name' => $this->resource->name,
            'length' => $this->resource->length,
            'wait_ms' => $this->resource->waitMs,
            'processes' => $this->resource->processes,
            'runtime_ms' => $this->resource->runtimeMs,
            'throughput' => $this->resource->throughput,
        ];
    }
}
