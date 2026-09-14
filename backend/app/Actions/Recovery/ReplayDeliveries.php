<?php

declare(strict_types=1);

namespace App\Actions\Recovery;

use App\Enums\DeliveryStatus;
use App\Exceptions\IdempotencyConflict;
use App\Exceptions\ReplayTargetNotFound;
use App\Models\Delivery;
use App\Models\Replay;
use App\Support\Idempotency\Fingerprint;
use App\Support\Recovery\ReplayResult;
use App\Support\Recovery\ReplayScope;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Opening new deliveries against what a scope names, without ever touching
 * what it names them against. A replay is a second obligation, never a
 * change to the first — the receipt and every delivery it opens commit
 * together, in the same transactional-outbox discipline PublishMessage's own
 * fan-out keeps, and nothing is enqueued here: the dispatcher picks a replay
 * delivery up exactly the way it picks up an original one.
 *
 * The idempotency reservation is simpler than PublishMessage's own, because
 * replays is not partitioned: the receipt row and the reservation are the
 * same row, rather than a message and a second table pointing at it.
 */
final readonly class ReplayDeliveries
{
    public function handle(ReplayScope $scope, ?string $idempotencyKey = null): ReplayResult
    {
        if ($idempotencyKey === null) {
            return new ReplayResult(DB::transaction(fn (): Replay => $this->write($scope)), duplicate: false);
        }

        $fingerprint = Fingerprint::of(...$scope->fingerprintParts());
        $reserved = $this->reservation($idempotencyKey);

        if ($reserved instanceof Replay) {
            return new ReplayResult($this->matching($reserved, $fingerprint), duplicate: true);
        }

        try {
            $replay = DB::transaction(fn (): Replay => $this->write($scope, $idempotencyKey, $fingerprint));
        } catch (UniqueConstraintViolationException $violation) {
            /*
             * The race: another request reserved this key between the read
             * above and this write. Its reservation is committed by the time
             * the violation reaches here, and everything this call had
             * written — the receipt and every delivery it opened — went back
             * with the transaction.
             */
            $winner = $this->reservation($idempotencyKey);

            if (! $winner instanceof Replay) {
                throw $violation;
            }

            return new ReplayResult($this->matching($winner, $fingerprint), duplicate: true);
        }

        return new ReplayResult($replay, duplicate: false);
    }

    /**
     * The receipt and its deliveries. Called inside a transaction, always.
     */
    private function write(ReplayScope $scope, ?string $idempotencyKey = null, ?Fingerprint $fingerprint = null): Replay
    {
        $originals = $scope->deliveries()->get();

        if ($originals->isEmpty() && $scope->requiresMatch()) {
            throw new ReplayTargetNotFound;
        }

        $replay = Replay::create([
            ...$scope->attributes(),
            'idempotency_key' => $idempotencyKey,
            'request_hash' => $fingerprint?->toString(),
            'delivery_count' => $originals->count(),
        ]);

        // Already in hand from the scope that just named them — lazy loading
        // throws outside production, and there is nothing to gain by asking
        // the database for what the caller already gave this action.
        $replay->setRelation('message', $scope->message());
        $replay->setRelation('endpoint', $scope->endpoint());

        foreach ($originals as $original) {
            Delivery::create([
                'message_id' => $original->message_id,
                'endpoint_id' => $original->endpoint_id,
                'replay_id' => $replay->id,
                'status' => DeliveryStatus::Pending,

                // The receipt's own moment rather than a second reading of
                // the clock, the same discipline PublishMessage keeps for a
                // fan-out's next_attempt_at: one replay has one moment.
                'next_attempt_at' => $replay->created_at,
            ]);
        }

        return $replay;
    }

    /**
     * The reservation this tenant holds for a key, if it holds one.
     */
    private function reservation(string $key): ?Replay
    {
        // Eager loaded so a call that returns an existing reservation can
        // still be handed straight to the resource — a lazy load here would
        // throw outside production.
        return Replay::query()->with(['message', 'endpoint'])->where('idempotency_key', $key)->first();
    }

    private function matching(Replay $reservation, Fingerprint $fingerprint): Replay
    {
        if (! $fingerprint->matches((string) $reservation->request_hash)) {
            throw new IdempotencyConflict('This Idempotency-Key was already used for a different replay request.');
        }

        return $reservation;
    }
}
