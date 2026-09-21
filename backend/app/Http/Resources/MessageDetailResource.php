<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsTimestamps;
use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The message detail screen: the payload as stored (D107 — a producer
 * already has the payload it sent; this is where every other reader gets
 * it), and every delivery the message ever opened, original and replayed
 * alike (D78 — a replay is a second delivery row, never a change to the
 * first, so an endpoint can appear here more than once).
 *
 * Deliveries are embedded unpaginated: the count is bounded by the
 * message's own subscriber count and however many times it has been
 * replayed, not an ever-growing log the way delivery_attempts is — the
 * bound conventions.md's "no endpoint returns an unbounded result set"
 * asks for is structural here, not enforced by a cursor.
 *
 * @property-read Message $resource
 */
final class MessageDetailResource extends JsonResource
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
            'application_name' => $this->resource->application->name,
            'event_type' => $this->resource->eventType->name,
            'source' => $this->resource->source->value,
            'payload' => $this->resource->payload,
            'deliveries' => DeliveryResource::collection($this->resource->deliveries),
            'created_at' => self::utc($this->resource->created_at),
        ];
    }
}
