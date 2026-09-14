<?php

declare(strict_types=1);

namespace App\Support\Recovery;

use App\Models\Delivery;
use App\Models\Endpoint;
use App\Models\Message;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Config;

/**
 * What a replay request names, and the one question both shapes answer the
 * same way once built: which original deliveries qualify.
 *
 * Neither shape ever touches a replay's own rows. A message scope reads
 * exactly the fan-out PublishMessage opened; a range scope reads exhausted
 * originals only — the dead letter queue an outage actually left behind, not
 * a delivery still trying on its own retry schedule. Replaying a replay is
 * not a second kind of recovery in either shape, it is the same request
 * asked again.
 */
final readonly class ReplayScope
{
    private function __construct(
        private ?Message $message,
        private ?Endpoint $endpoint,
        private bool $requireMatch,
        private ?CarbonImmutable $from = null,
        private ?CarbonImmutable $to = null,
        private ?ReplayCursor $cursor = null,
    ) {}

    /**
     * A single message, either to every endpoint its original fan-out
     * reached or, when $endpoint is named, to that one alone.
     */
    public static function forMessage(Message $message, ?Endpoint $endpoint = null): self
    {
        return new self($message, $endpoint, requireMatch: $endpoint !== null);
    }

    /**
     * One endpoint's own exhausted deliveries, bounded to a time window —
     * the outage an operator is recovering from, not the endpoint's whole
     * history. Never requires a match: a window with nothing exhausted in it
     * is a legitimate, honest answer, not a caller's mistake.
     */
    public static function forRange(
        Endpoint $endpoint,
        CarbonImmutable $from,
        CarbonImmutable $to,
        ?ReplayCursor $cursor = null,
    ): self {
        return new self(null, $endpoint, requireMatch: false, from: $from, to: $to, cursor: $cursor);
    }

    /**
     * Whether an empty result means "nothing to recover" or "the caller named
     * something that is not there". Only true for a message scope with an
     * endpoint named explicitly — asking for all of a message's subscribers,
     * or asking what a window recovered, and finding nothing is each its own
     * honest answer.
     */
    public function requiresMatch(): bool
    {
        return $this->requireMatch;
    }

    /**
     * The models this scope was built from, so a caller that already holds
     * them — the receipt this scope produces, above all — can attach them as
     * loaded relations instead of paying for a lazy load strict mode would
     * refuse to make silently, the same trade PublishMessage's own
     * setRelation() call makes for a fresh message's event type.
     */
    public function message(): ?Message
    {
        return $this->message;
    }

    public function endpoint(): ?Endpoint
    {
        return $this->endpoint;
    }

    /**
     * The batch ceiling a range scope is bounded to. Null for a message
     * scope, which is bounded by its own subscriber count instead and is
     * never paginated — a message fans out to as many endpoints as it always
     * did, not to an operator-configured batch size.
     */
    public function limit(): ?int
    {
        return $this->from !== null ? Config::integer('postbox.replay.max_deliveries_per_request') : null;
    }

    /**
     * @return array<string, CarbonImmutable|int|null>
     */
    public function attributes(): array
    {
        return [
            'message_id' => $this->message?->id,
            'endpoint_id' => $this->endpoint?->id,
            'range_from' => $this->from,
            'range_to' => $this->to,
        ];
    }

    /**
     * What "the same replay request" means for this scope's own idempotency
     * reservation. Tagged with a discriminator so a message scope's digest can
     * never collide with a range scope's, whatever either one's own parts are.
     *
     * @return list<string>
     */
    public function fingerprintParts(): array
    {
        if ($this->from !== null) {
            return [
                'range',
                (string) $this->endpoint?->public_id,
                $this->from->toAtomString(),
                (string) $this->to?->toAtomString(),
                (string) $this->cursor?->encode(),
            ];
        }

        return ['message', (string) $this->message?->public_id, (string) $this->endpoint?->public_id];
    }

    /**
     * The original deliveries this scope names — never a replay's own rows.
     *
     * @return Builder<Delivery>
     */
    public function deliveries(): Builder
    {
        return $this->from !== null ? $this->rangeDeliveries() : $this->messageDeliveries();
    }

    /**
     * @return Builder<Delivery>
     */
    private function messageDeliveries(): Builder
    {
        assert($this->message !== null);

        $query = Delivery::query()->whereNull('replay_id')->where('message_id', $this->message->id);

        if ($this->endpoint !== null) {
            $query->where('endpoint_id', $this->endpoint->id);
        }

        return $query;
    }

    /**
     * @return Builder<Delivery>
     */
    private function rangeDeliveries(): Builder
    {
        assert($this->endpoint !== null && $this->to !== null);

        $query = Delivery::query()
            ->whereNull('replay_id')
            ->where('endpoint_id', $this->endpoint->id)
            ->deadLettered()
            ->whereBetween('exhausted_at', [$this->from, $this->to])
            ->orderBy('exhausted_at')
            ->orderBy('id');

        if ($this->cursor !== null) {
            $cursor = $this->cursor;

            // Keyset pagination: strictly after the last row the previous
            // page ended on, in (exhausted_at, id) order. A plain
            // exhausted_at > cursor would skip every row that shares its
            // second; id is the tiebreaker that makes a page boundary land
            // between rows rather than through a tied instant.
            $query->where(function (Builder $outer) use ($cursor): void {
                $outer->where('exhausted_at', '>', $cursor->exhaustedAt)
                    ->orWhere(function (Builder $inner) use ($cursor): void {
                        $inner->where('exhausted_at', $cursor->exhaustedAt)->where('id', '>', $cursor->id);
                    });
            });
        }

        return $query;
    }
}
