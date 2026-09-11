<?php

declare(strict_types=1);

namespace App\Actions\Ingest;

use App\Enums\DeliveryStatus;
use App\Enums\EndpointStatus;
use App\Models\Application;
use App\Models\Delivery;
use App\Models\EndpointSubscription;
use App\Models\EventType;
use App\Models\Message;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Accepting one event.
 *
 * The message and the deliveries it fans out to are written in a single
 * transaction, and nothing is enqueued here. That is the whole point of the
 * outbox: the moment the response is sent, the obligation to deliver is a
 * committed row rather than a queue entry that a crash could have swallowed. The
 * dispatcher reads those rows; the request path never talks to the queue.
 */
final readonly class PublishMessage
{
    /**
     * @param  array<array-key, mixed>  $payload  opaque to PostBox — it is stored
     *                                            and forwarded, never inspected
     */
    public function handle(Application $application, string $eventType, array $payload): Message
    {
        /*
         * Already proven to exist by the form request, and re-read here rather
         * than handed over, so the action is callable on its own terms. Both
         * lookups hit the same unique index on (tenant_id, name).
         */
        $type = EventType::query()->where('name', $eventType)->firstOrFail();

        return DB::transaction(function () use ($application, $type, $payload): Message {
            $message = Message::create([
                'application_id' => $application->id,
                'event_type_id' => $type->id,
                'payload' => $payload,
            ]);

            $this->openDeliveries($message, $application, $type);

            // The resource reads the event type's name, and lazy loading throws
            // outside production. It is already in hand, so this costs nothing.
            $message->setRelation('eventType', $type);

            return $message;
        });
    }

    /**
     * One delivery per endpoint of this application that is both subscribed to
     * the event and switched on. The read starts from the subscription side,
     * which is the order the unique index on (event_type_id, endpoint_id) was
     * built for.
     *
     * They are created one at a time rather than bulk inserted: a mass insert
     * bypasses the model, and both the tenant stamp and the public identifier are
     * model behaviour. Rebuilding either here would be a second definition of a
     * rule that already has one, for a loop whose length is the number of
     * endpoints an application has.
     */
    private function openDeliveries(Message $message, Application $application, EventType $eventType): void
    {
        $subscriptions = EndpointSubscription::query()
            ->where('event_type_id', $eventType->id)
            ->whereHas('endpoint', fn (Builder $endpoint): Builder => $endpoint
                ->where('application_id', $application->id)
                ->where('status', EndpointStatus::Enabled))
            ->get();

        foreach ($subscriptions as $subscription) {
            Delivery::create([
                'message_id' => $message->id,
                'endpoint_id' => $subscription->endpoint_id,
                'status' => DeliveryStatus::Pending,

                // Due immediately. The message's own timestamp rather than a
                // second reading of the clock, so one publish has one moment.
                'next_attempt_at' => $message->created_at,
            ]);
        }
    }
}
