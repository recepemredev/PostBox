<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsTimestamps;
use App\Models\Replay;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The receipt for a recovery request: what was asked for, and how many
 * deliveries it opened — never the deliveries themselves, which belong to
 * the same message and endpoint detail views every other delivery does.
 *
 * @property-read Replay $resource
 */
final class ReplayResource extends JsonResource
{
    use FormatsTimestamps;

    /**
     * @return array<string, string|int|null>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->public_id,
            'message_id' => $this->resource->message?->public_id,
            'endpoint_id' => $this->resource->endpoint?->public_id,
            'delivery_count' => $this->resource->delivery_count,
            'created_at' => self::utc($this->resource->created_at),
        ];
    }
}
