<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\DeliveryAttempt;
use App\Support\Pagination\CursorPage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The retry timeline's own envelope — the same closed {data, meta} shape
 * MessageCollection uses, for the same reason (D83, D99).
 *
 * @property-read CursorPage<DeliveryAttempt> $resource
 */
final class DeliveryAttemptCollection extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'data' => DeliveryAttemptResource::collection($this->resource->items),
            'meta' => ['next_cursor' => $this->resource->next?->encode()],
        ];
    }
}
