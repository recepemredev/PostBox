<?php

declare(strict_types=1);

namespace App\Actions\Resilience;

use App\Actions\Audit\RecordAuditEntry;
use App\Enums\BreakerState;
use App\Exceptions\InvalidBreakerTransition;
use App\Models\Endpoint;
use App\Models\EndpointCircuitBreaker;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The only place a breaker's state actually changes, and the only place a
 * change is written to the audit log — the two always happen together, because
 * a breaker moving without a trace of why would be exactly the invisible
 * change CLAUDE.md's audit rule exists to rule out.
 *
 * Legality is asked of BreakerState, never re-decided here. What this class
 * owns is the race: a write only lands if the row is still in the state the
 * caller observed, so two workers proposing the same move — two failing
 * attempts opening a breaker at once, two dispatcher passes admitting a probe
 * at once — have exactly one winner. The loser gets false, not an exception;
 * losing a race is not the same defect as asking for an illegal move.
 */
final readonly class TransitionBreaker
{
    public function __construct(private RecordAuditEntry $auditor) {}

    /**
     * @return bool whether this call was the one that made the change
     */
    public function handle(Endpoint $endpoint, BreakerState $to, CarbonImmutable $now): bool
    {
        $breaker = $this->current($endpoint);
        $from = $breaker !== null ? $breaker->state : BreakerState::Closed;

        if (! $from->canTransitionTo($to)) {
            throw InvalidBreakerTransition::from($from, $to);
        }

        $won = $breaker === null
            ? $this->open($endpoint, $to, $now)
            : $this->claim($breaker, $from, $to, $now);

        if ($won) {
            $this->record($endpoint, $from, $to, $now);
        }

        return $won;
    }

    private function current(Endpoint $endpoint): ?EndpointCircuitBreaker
    {
        return EndpointCircuitBreaker::query()->where('endpoint_id', $endpoint->id)->first();
    }

    /**
     * An endpoint's first trip ever: there is no row to update, so the move is
     * an insert. Two workers racing to open the same endpoint's breaker for
     * the first time both attempt this; the loser takes the unique constraint
     * on (tenant_id, endpoint_id) instead of a row to contest, the same shape
     * ConsumeQuota's own first-row race takes.
     *
     * The only legal move into an empty row is closed-to-open — BreakerState
     * guarantees that before this is ever called — so $to is always Open here.
     */
    private function open(Endpoint $endpoint, BreakerState $to, CarbonImmutable $now): bool
    {
        try {
            DB::transaction(function () use ($endpoint, $to, $now): void {
                EndpointCircuitBreaker::create([
                    'endpoint_id' => $endpoint->id,
                    'state' => $to,
                    'state_changed_at' => $now,
                    'opened_at' => $now,
                    'probe_started_at' => null,
                ]);
            });

            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    /**
     * Every later transition: a conditional update guarded by the state this
     * call observed. If the row has since moved, another worker already
     * claimed it and this call has lost — not a defect, just a race decided.
     */
    private function claim(EndpointCircuitBreaker $breaker, BreakerState $from, BreakerState $to, CarbonImmutable $now): bool
    {
        $affected = EndpointCircuitBreaker::query()
            ->whereKey($breaker->getKey())
            ->where('state', $from)
            ->update([
                'state' => $to,
                'state_changed_at' => $now,
                // Half-open keeps the trip's original opened_at — it is still
                // the same failure that caused it. Open always starts a fresh
                // one, whether the previous state was closed or a probe that
                // just failed. Closed clears both: the shape check demands it,
                // and a closed breaker has no open trip left to describe.
                'opened_at' => match ($to) {
                    BreakerState::HalfOpen => $breaker->opened_at,
                    BreakerState::Open => $now,
                    BreakerState::Closed => null,
                },
                'probe_started_at' => $to === BreakerState::HalfOpen ? $now : null,
            ]);

        return $affected === 1;
    }

    private function record(Endpoint $endpoint, BreakerState $from, BreakerState $to, CarbonImmutable $now): void
    {
        $this->auditor->handle(
            action: self::action($to),
            entityType: 'endpoint',
            entityId: $endpoint->id,
            entityPublicId: $endpoint->public_id,
            changes: ['state' => ['before' => $from->value, 'after' => $to->value]],
        );

        Log::info('breaker.transitioned', [
            'endpoint_id' => $endpoint->public_id,
            'from' => $from->value,
            'to' => $to->value,
        ]);
    }

    private static function action(BreakerState $to): string
    {
        return match ($to) {
            BreakerState::Open => 'breaker.opened',
            BreakerState::HalfOpen => 'breaker.probe_admitted',
            BreakerState::Closed => 'breaker.closed',
        };
    }
}
