<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsTimestamps;
use App\Support\Recovery\ReplayResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The receipt for a recovery request: what was asked for, and how many
 * deliveries it opened — never the deliveries themselves, which belong to
 * the same message and endpoint detail views every other delivery does.
 *
 * Wraps the whole ReplayResult rather than the Replay row alone, because
 * next_cursor belongs to this one response, not to anything persisted.
 * JsonResource::additional() was tried and rejected: with withoutWrapping()
 * active application-wide, adding any top-level sibling key forces the
 * resource itself back under a "data" envelope to avoid a collision, which
 * is worse than the coupling this class takes on instead.
 *
 * @property-read ReplayResult $resource
 */
final class ReplayResource extends JsonResource
{
    use FormatsTimestamps;

    /**
     * @return array<string, string|int|null>
     */
    public function toArray(Request $request): array
    {
        $replay = $this->resource->replay;

        return [
            'id' => $replay->public_id,
            'message_id' => $replay->message?->public_id,
            'endpoint_id' => $replay->endpoint?->public_id,
            'range_from' => self::utc($replay->range_from),
            'range_to' => self::utc($replay->range_to),
            'delivery_count' => $replay->delivery_count,
            'created_at' => self::utc($replay->created_at),

            // Present, sometimes null, only for a range scope — a message
            // scope is never paginated, so the field would mean nothing
            // there and this class leaves it out entirely rather than
            // stand for "there is nothing more" and "this was never a
            // question" with the same null.
            ...($replay->range_from !== null ? ['next_cursor' => $this->resource->nextCursor] : []),
        ];
    }
}
