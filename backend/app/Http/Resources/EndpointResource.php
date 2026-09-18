<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsTimestamps;
use App\Models\Endpoint;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Closes D70's own deferral: circuit breaker state changes are surfaced here
 * rather than only through `postbox:breakers`, now that an endpoint resource
 * exists for them to be surfaced on. A breaker that has never tripped is
 * null — the same absent-row-means-closed shape the model itself takes,
 * rather than a synthesized "closed" object this resource would have to
 * invent.
 *
 * @property-read Endpoint $resource
 */
final class EndpointResource extends JsonResource
{
    use FormatsTimestamps;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->public_id,
            'application_id' => $this->resource->application->public_id,
            'name' => $this->resource->name,
            'url' => $this->resource->url,
            'status' => $this->resource->status->value,
            'subscriptions' => $this->resource->subscriptions
                ->map(fn ($subscription): string => $subscription->eventType->name)
                ->values(),
            'breaker' => $this->resource->breaker !== null ? [
                'state' => $this->resource->breaker->state->value,
                'state_changed_at' => self::utc($this->resource->breaker->state_changed_at),
            ] : null,
            'active_secret_count' => $this->resource->active_secrets_count,
            'created_at' => self::utc($this->resource->created_at),
        ];
    }
}
