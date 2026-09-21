<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsTimestamps;
use App\Models\Delivery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One endpoint's own standing obligation against a message — embedded in
 * MessageDetailResource rather than exposed at its own route, since a
 * delivery is never read except in the context of the message it belongs
 * to (its own attempt history is the one exception, at
 * GET /v1/deliveries/{delivery}/attempts).
 *
 * replay_id is null for the fan-out's own row and a receipt's public id for
 * one Recovery opened instead (D78) — a message can carry more than one
 * delivery to the same endpoint for exactly this reason, and this is what
 * lets the inspector tell them apart.
 *
 * @property-read Delivery $resource
 */
final class DeliveryResource extends JsonResource
{
    use FormatsTimestamps;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->public_id,
            'endpoint_id' => $this->resource->endpoint->public_id,
            'endpoint_name' => $this->resource->endpoint->name,
            'replay_id' => $this->resource->replay?->public_id,
            'status' => $this->resource->status->value,
            'attempt_count' => $this->resource->attempt_count,
            'next_attempt_at' => self::utc($this->resource->next_attempt_at),
            'last_attempted_at' => self::utc($this->resource->last_attempted_at),
            'exhausted_at' => self::utc($this->resource->exhausted_at),
            'failure_reason' => $this->resource->failure_reason,
            'created_at' => self::utc($this->resource->created_at),
        ];
    }
}
