<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsTimestamps;
use App\Models\DeliveryAttempt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the retry timeline and the payload inspector (design.md) —
 * request and response exactly as AttemptRecord stored them: size-capped
 * and with every scrubbed header redacted rather than removed, so this
 * resource has nothing left to scrub a second time on the way out.
 *
 * @property-read DeliveryAttempt $resource
 */
final class DeliveryAttemptResource extends JsonResource
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
            'endpoint_id' => $this->resource->endpoint->public_id,
            'attempt_number' => $this->resource->attempt_number,
            'outcome' => $this->resource->outcome->value,
            'request_headers' => $this->resource->request_headers,
            'request_body' => $this->resource->request_body,
            'response_status' => $this->resource->response_status,
            'response_headers' => $this->resource->response_headers,
            'response_body' => $this->resource->response_body,
            'error_message' => $this->resource->error_message,
            'duration_ms' => $this->resource->duration_ms,
            'created_at' => self::utc($this->resource->created_at),
        ];
    }
}
