<?php

declare(strict_types=1);

namespace App\Actions\Catalog;

use App\Models\Endpoint;
use App\Models\EventType;
use Illuminate\Support\Facades\DB;

/**
 * Brings an endpoint's subscriptions to exactly the given set of event type
 * names — the same "resolve by name, do not trust an id the caller hands
 * over" shape PublishMessage's own event type lookup takes. Names not
 * already subscribed are added; subscriptions to a name no longer in the
 * list are removed. Calling this twice with the same names changes nothing
 * the second time.
 */
final readonly class SyncSubscriptions
{
    /**
     * @param  list<string>  $eventTypeNames
     */
    public function handle(Endpoint $endpoint, array $eventTypeNames): void
    {
        $desiredIds = EventType::query()->whereIn('name', $eventTypeNames)->pluck('id');

        DB::transaction(function () use ($endpoint, $desiredIds): void {
            $currentIds = $endpoint->subscriptions()->pluck('event_type_id');

            $toRemove = $currentIds->diff($desiredIds);
            $toAdd = $desiredIds->diff($currentIds);

            if ($toRemove->isNotEmpty()) {
                $endpoint->subscriptions()->whereIn('event_type_id', $toRemove)->delete();
            }

            foreach ($toAdd as $eventTypeId) {
                $endpoint->subscriptions()->create(['event_type_id' => $eventTypeId]);
            }
        });
    }
}
