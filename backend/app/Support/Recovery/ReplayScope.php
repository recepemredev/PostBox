<?php

declare(strict_types=1);

namespace App\Support\Recovery;

use App\Models\Delivery;
use App\Models\Endpoint;
use App\Models\Message;
use Illuminate\Database\Eloquent\Builder;

/**
 * What a replay request names, and the one question every shape answers the
 * same way once it is built: which original deliveries qualify.
 *
 * A message scope (this class's only shape so far — a range scope arrives
 * with Step 9's own range replay) never touches a replay's own rows: the
 * fan-out it reads is exactly the one PublishMessage opened, never one an
 * earlier replay opened on its behalf. Replaying a replay is not a second
 * kind of recovery, it is the same message-scoped replay asked again.
 */
final readonly class ReplayScope
{
    private function __construct(
        private ?Message $message,
        private ?Endpoint $endpoint,
        private bool $requireMatch,
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
     * Whether an empty result means "nothing to recover" or "the caller named
     * something that is not there". Only true when an endpoint was named
     * explicitly — asking for all of a message's subscribers and finding none
     * is an honest answer about a message nobody was subscribed to.
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
     * @return array<string, int|null>
     */
    public function attributes(): array
    {
        return [
            'message_id' => $this->message?->id,
            'endpoint_id' => $this->endpoint?->id,
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
        // forMessage() is the only named constructor so far, and it never
        // passes null for $message — so Larastan narrows both properties to
        // never-null today. Step 9's own range scope, next commit, assigns
        // null for real and lifts this on its own.
        // @phpstan-ignore nullsafe.neverNull, nullsafe.neverNull
        return ['message', $this->message?->public_id ?? '', $this->endpoint?->public_id ?? ''];
    }

    /**
     * The original deliveries this scope names — never a replay's own rows.
     *
     * @return Builder<Delivery>
     */
    public function deliveries(): Builder
    {
        assert($this->message !== null);

        $query = Delivery::query()->whereNull('replay_id')->where('message_id', $this->message->id);

        if ($this->endpoint !== null) {
            $query->where('endpoint_id', $this->endpoint->id);
        }

        return $query;
    }
}
