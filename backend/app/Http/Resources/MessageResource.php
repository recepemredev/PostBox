<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsTimestamps;
use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What a producer gets back for an accepted event.
 *
 * It carries nothing about the deliveries the message opened. How many endpoints
 * were subscribed at that moment is an operational fact that belongs to the
 * dashboard, and a producer that branched on it would be coupling itself to
 * another tenant user's subscription choices. This shape is also what a replay
 * has to reproduce byte for byte, so everything in it is immutable.
 *
 * @property-read Message $resource
 */
final class MessageResource extends JsonResource
{
    use FormatsTimestamps;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->public_id,
            'event_type' => $this->resource->eventType->name,
            'payload' => $this->resource->payload,
            'created_at' => self::utc($this->resource->created_at),
        ];
    }
}
