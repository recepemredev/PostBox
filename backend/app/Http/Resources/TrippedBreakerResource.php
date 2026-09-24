<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\EndpointCircuitBreaker;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One tripped breaker's row in the operations screen's response. See
 * QueueWorkloadResource for why this is its own resource rather than an
 * array built inline.
 *
 * @property-read EndpointCircuitBreaker $resource
 */
final class TrippedBreakerResource extends JsonResource
{
    /**
     * @return array{endpoint_id: string, endpoint_name: string, state: string, state_changed_at: string}
     */
    public function toArray(Request $request): array
    {
        return [
            'endpoint_id' => $this->resource->endpoint->public_id,
            'endpoint_name' => $this->resource->endpoint->name,
            'state' => $this->resource->state->value,
            'state_changed_at' => $this->resource->state_changed_at->toIso8601ZuluString(),
        ];
    }
}
