<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsTimestamps;
use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What a producer gets back for an accepted event: a receipt, not an echo.
 *
 * The payload is deliberately absent. A replay has to reproduce this body byte
 * for byte, and the payload is the one field that could not — it goes out to the
 * producer as the array their request parsed into, and comes back from storage
 * as PostgreSQL renders jsonb, which orders object keys by length. Two spellings
 * of the same document, and only the second one is reproducible. Echoing up to
 * 256 KiB back down the ingest hot path bought nothing to begin with: the
 * producer has the payload already, and the dashboard reads it from storage like
 * every other reader.
 *
 * The deliveries the message opened are absent for a different reason. How many
 * endpoints were subscribed at that moment is an operational fact belonging to
 * the dashboard, and a producer that branched on it would be coupling itself to
 * another tenant user's subscription choices.
 *
 * @property-read Message $resource
 */
final class MessageResource extends JsonResource
{
    use FormatsTimestamps;

    /**
     * @return array<string, string|null>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->public_id,
            'event_type' => $this->resource->eventType->name,
            'created_at' => self::utc($this->resource->created_at),
        ];
    }
}
