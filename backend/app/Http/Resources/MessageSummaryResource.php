<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsTimestamps;
use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the message list. Deliberately not MessageResource, which is
 * an ingest receipt — no payload, no delivery information, by its own
 * design (D107) — and deliberately not the full detail view either: the
 * list reads delivery counts (three withCount aliases MessageController's
 * own index() query declares), never the deliveries themselves.
 *
 * There is no MessageStatus. "How this message is doing" is read off the
 * counts below rather than a single status column, because a message with
 * three subscribers can be delivered to two and still pending on the
 * third — collapsing that into one value would have to pick a lie.
 *
 * source distinguishes a producer's own traffic from D76's dashboard test
 * event — the one place that marker has to be visible for the promise it
 * was written for ("the attempt log never lies about where traffic came
 * from") to be kept.
 *
 * @property-read Message $resource
 */
final class MessageSummaryResource extends JsonResource
{
    use FormatsTimestamps;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var int $total */
        $total = $this->resource->deliveries_count;
        /** @var int $succeeded */
        $succeeded = $this->resource->succeeded_deliveries_count;
        /** @var int $exhausted */
        $exhausted = $this->resource->exhausted_deliveries_count;

        return [
            'id' => $this->resource->public_id,
            'application_id' => $this->resource->application->public_id,
            'application_name' => $this->resource->application->name,
            'event_type' => $this->resource->eventType->name,
            'source' => $this->resource->source->value,
            'deliveries' => [
                'total' => $total,
                'succeeded' => $succeeded,
                'pending' => $total - $succeeded - $exhausted,
                'exhausted' => $exhausted,
            ],
            'created_at' => self::utc($this->resource->created_at),
        ];
    }
}
