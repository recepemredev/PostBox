<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsTimestamps;
use App\Models\EventType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * No `id` field: an event type has no public_id (its name is the identifier
 * — see EventType's own docblock), so this resource has nothing to expose
 * that is not already `name`.
 *
 * @property-read EventType $resource
 */
final class EventTypeResource extends JsonResource
{
    use FormatsTimestamps;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'name' => $this->resource->name,
            'subscriber_count' => $this->resource->subscriptions_count,
            'created_at' => self::utc($this->resource->created_at),
        ];
    }
}
