<?php

declare(strict_types=1);

namespace App\Actions\Recovery;

use App\Enums\DeliveryStatus;
use App\Exceptions\IdempotencyConflict;
use App\Exceptions\ReplayTargetNotFound;
use App\Models\Delivery;
use App\Models\Replay;
use App\Support\Idempotency\Fingerprint;
use App\Support\Recovery\ReplayCursor;
use App\Support\Recovery\ReplayResult;
use App\Support\Recovery\ReplayScope;
use Illuminate\Database\Eloquent\Collection;
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
            [$replay, $nextCursor] = DB::transaction(fn (): array => $this->write($scope));

            return new ReplayResult($replay, duplicate: false, nextCursor: $nextCursor);
        }

        $fingerprint = Fingerprint::of(...$scope->fingerprintParts());
        $reserved = $this->reservation($idempotencyKey);

        if ($reserved instanceof Replay) {
            return new ReplayResult($this->matching($reserved, $fingerprint), duplicate: true);
        }

        try {
            [$replay, $nextCursor] = DB::transaction(
                fn (): array => $this->write($scope, $idempotencyKey, $fingerprint),
            );
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

        return new ReplayResult($replay, duplicate: false, nextCursor: $nextCursor);
    }

    /**
     * The receipt and its deliveries, plus a cursor for whatever a range
     * scope's own batch ceiling left unread. Called inside a transaction,
     * always.
     *
     * @return array{0: Replay, 1: string|null}
     */
    private function write(ReplayScope $scope, ?string $idempotencyKey = null, ?Fingerprint $fingerprint = null): array
    {
        [$originals, $nextCursor] = $this->matchedDeliveries($scope);

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

        return [$replay, $nextCursor];
    }

    /**
     * What the scope names, bounded to its own batch ceiling when it has
     * one. A range scope's ceiling is fetched one row past the limit so this
     * can tell "exactly this many" from "at least this many" without a
     * second, separate count query — the extra row is trimmed back off
     * before it ever reaches a delivery this replay opens.
     *
     * @return array{0: Collection<int, Delivery>, 1: string|null}
     */
    private function matchedDeliveries(ReplayScope $scope): array
    {
        $limit = $scope->limit();
        $query = $scope->deliveries();

        if ($limit !== null) {
            $query->limit($limit + 1);
        }

        $matched = $query->get();

        if ($limit === null || $matched->count() <= $limit) {
            return [$matched, null];
        }

        $matched = $matched->slice(0, $limit)->values();

        $last = $matched->last();
        assert($last !== null);

        return [$matched, ReplayCursor::after($last)->encode()];
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
