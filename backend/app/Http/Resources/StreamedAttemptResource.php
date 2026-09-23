<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsTimestamps;
use App\Models\DeliveryAttempt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the live SSE feed. A live-feed row is a different shape from
 * the attempt inspector's own DeliveryAttemptResource — the same reasoning
 * D120 used to give Ledger both a MessageSummaryResource and a
 * MessageDetailResource. No request/response bodies and no headers of any
 * kind: DeliveryAttemptResource caps each at 256 KiB, and pushing that down
 * a live feed for every attempt would be indefensible. An operator who
 * wants those clicks through to the message detail Step 14 already built.
 *
 * @property-read DeliveryAttempt $resource
 */
final class StreamedAttemptResource extends JsonResource
{
    use FormatsTimestamps;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->public_id,
            'delivery_id' => $this->resource->delivery->public_id,
            'message_id' => $this->resource->delivery->message->public_id,
            'endpoint_id' => $this->resource->endpoint->public_id,
            'endpoint_name' => $this->resource->endpoint->name,
            'event_type' => $this->resource->delivery->message->eventType->name,
            'attempt_number' => $this->resource->attempt_number,
            'outcome' => $this->resource->outcome->value,
            'response_status' => $this->resource->response_status,
            'duration_ms' => $this->resource->duration_ms,
            'delivery_status' => $this->resource->delivery->status->value,
            'created_at' => self::utc($this->resource->created_at),
        ];
    }
}
