<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Message;
use App\Support\Pagination\CursorPage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The message list's own envelope: a page of summaries plus a cursor for
 * the next one. next_cursor is always present and only sometimes null —
 * never conditionally spread the way ReplayResource's own field had to be
 * (D83), which is exactly the shape DescribeReplayResource exists to work
 * around. A field Scramble can see is always there needs no such transformer.
 *
 * @property-read CursorPage<Message> $resource
 */
final class MessageCollection extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'data' => MessageSummaryResource::collection($this->resource->items),
            'meta' => ['next_cursor' => $this->resource->next?->encode()],
        ];
    }
}
