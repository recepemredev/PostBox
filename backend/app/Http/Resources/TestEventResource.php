<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Support\Catalog\TestEventResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read TestEventResult $resource
 */
final class TestEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'message' => MessageResource::make($this->resource->message)->toArray($request),
            'delivery_id' => $this->resource->delivery->public_id,
            'delivery_status' => $this->resource->delivery->status->value,
        ];
    }
}
