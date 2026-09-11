<?php

declare(strict_types=1);

namespace App\Actions\Ingest;

use App\Enums\DeliveryStatus;
use App\Enums\EndpointStatus;
use App\Exceptions\IdempotencyConflict;
use App\Models\Application;
use App\Models\Delivery;
use App\Models\EndpointSubscription;
use App\Models\EventType;
use App\Models\IdempotencyKey;
use App\Models\Message;
use App\Support\Ingest\PublishedMessage;
use App\Support\Ingest\RequestFingerprint;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Accepting one event.
 *
 * The message and the deliveries it fans out to are written in a single
 * transaction, and nothing is enqueued here. That is the whole point of the
 * outbox: the moment the response is sent, the obligation to deliver is a
 * committed row rather than a queue entry that a crash could have swallowed. The
 * dispatcher reads those rows; the request path never talks to the queue.
 *
 * When the producer supplies an idempotency key, the reservation for it is
 * written inside that same transaction. It is the unique constraint on
 * (tenant_id, key) that does the work, not the read that precedes it — the read
 * is only a shortcut for the common case where the original is long committed.
 */
final readonly class PublishMessage
{
    /**
     * @param  array<array-key, mixed>  $payload  opaque to PostBox — it is stored
     *                                            and forwarded, never inspected
     */
    public function handle(
        Application $application,
        string $eventType,
        array $payload,
        ?string $idempotencyKey = null,
    ): PublishedMessage {
        /*
         * Already proven to exist by the form request, and re-read here rather
         * than handed over, so the action is callable on its own terms. Both
         * lookups hit the same unique index on (tenant_id, name).
         */
        $type = EventType::query()->where('name', $eventType)->firstOrFail();

        if ($idempotencyKey === null) {
            return new PublishedMessage(
                DB::transaction(fn (): Message => $this->write($application, $type, $payload)),
                replayed: false,
            );
        }

        $fingerprint = RequestFingerprint::of($application, $eventType, $payload);
        $reserved = $this->reservation($idempotencyKey);

        if ($reserved instanceof IdempotencyKey) {
            return new PublishedMessage($this->replay($reserved, $fingerprint), replayed: true);
        }

        try {
            $message = DB::transaction(function () use ($application, $type, $payload, $idempotencyKey, $fingerprint): Message {
                $message = $this->write($application, $type, $payload);

                $this->reserve($idempotencyKey, $fingerprint, $message);

                return $message;
            });
        } catch (UniqueConstraintViolationException $violation) {
            /*
             * The race, and the branch that makes the constraint worth having:
             * another request reserved this key between the read above and this
             * write. Its reservation is committed by the time the violation
             * reaches here, and everything this call had written — message and
             * deliveries alike — went back with the transaction.
             */
            $winner = $this->reservation($idempotencyKey);

            if (! $winner instanceof IdempotencyKey) {
                // Some other unique constraint, then. Not ours to swallow.
                throw $violation;
            }

            return new PublishedMessage($this->replay($winner, $fingerprint), replayed: true);
        }

        return new PublishedMessage($message, replayed: false);
    }

    /**
     * The message and its outbox rows. Called inside a transaction, always.
     *
     * @param  array<array-key, mixed>  $payload
     */
    private function write(Application $application, EventType $eventType, array $payload): Message
    {
        $message = Message::create([
            'application_id' => $application->id,
            'event_type_id' => $eventType->id,
            'payload' => $payload,
        ]);

        $this->openDeliveries($message, $application, $eventType);

        // The resource reads the event type's name, and lazy loading throws
        // outside production. It is already in hand, so this costs nothing.
        $message->setRelation('eventType', $eventType);

        return $message;
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

    /**
     * The reservation this tenant holds for a key, if it holds one.
     *
     * Expiry is deliberately not a condition here. While the row exists the key
     * is spent, and it stops existing when the pruner deletes it — so there is
     * one answer to whether a key has been used, rather than one that depends on
     * who is asking and when.
     */
    private function reservation(string $key): ?IdempotencyKey
    {
        return IdempotencyKey::query()->where('key', $key)->first();
    }

    private function reserve(string $key, RequestFingerprint $fingerprint, Message $message): void
    {
        IdempotencyKey::create([
            'key' => $key,
            'request_hash' => $fingerprint->toString(),
            'message_id' => $message->id,

            // Measured from the message rather than from the clock, and taken as
            // an immutable copy: created_at is the value the response reports,
            // and adding to a mutable Carbon in place would change it.
            'expires_at' => $message->created_at->toImmutable()
                ->addHours(Config::integer('postbox.ingest.idempotency.ttl_hours')),
        ]);
    }

    /**
     * The original message a spent key points at.
     *
     * The lookup is by primary key without the partition key beside it, so it
     * probes one index per live partition rather than one index. That is a
     * handful of probes against a table with a monthly retention window, and the
     * alternative — carrying created_at on the reservation purely to prune
     * partitions — would be a second copy of the message's own timestamp.
     */
    private function replay(IdempotencyKey $reservation, RequestFingerprint $fingerprint): Message
    {
        if (! $fingerprint->matches($reservation->request_hash)) {
            throw new IdempotencyConflict;
        }

        return Message::query()
            ->with('eventType')
            ->where('id', $reservation->message_id)
            ->firstOrFail();
    }
}
