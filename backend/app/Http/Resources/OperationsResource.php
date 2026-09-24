<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Support\Operations\OperationsSnapshot;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
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
            'queues' => QueueWorkloadResource::collection($this->resource->queues),
            'breakers' => [
                'closed' => $this->resource->breakerCounts->closed,
                'open' => $this->resource->breakerCounts->open,
                'half_open' => $this->resource->breakerCounts->halfOpen,
                'tripped' => $this->trippedBreakers(),
            ],
        ];
    }

    private function trippedBreakers(): AnonymousResourceCollection
    {
        return TrippedBreakerResource::collection($this->resource->trippedBreakers);
    }
}
