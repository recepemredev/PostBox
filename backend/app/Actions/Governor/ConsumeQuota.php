<?php

declare(strict_types=1);

namespace App\Actions\Governor;

use App\Models\QuotaUsage;
use App\Models\Tenant;
use App\Support\Governor\LimitDecision;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Counts one message against a tenant's period quota — cumulative rather than
 * instantaneous, the other half of what Governor enforces, kept in its own
 * storage and its own arithmetic deliberately apart from the token bucket.
 *
 * The period is derived from the clock the caller hands in, never read fresh
 * here: the same discipline the rate limiter keeps, and what lets a test pin a
 * moment and get a deterministic period back.
 *
 * A tenant's first message of a period has no row to increment, so the
 * conditional UPDATE that handles every later message in that period misses on
 * purpose and falls back to inserting the row. What follows is the same shape
 * PublishMessage's own idempotency reservation takes, for the same reason: the
 * unique constraint on (tenant_id, period_start) is the guarantee, and the read
 * that precedes a write is only ever a shortcut for the common case.
 */
final readonly class ConsumeQuota
{
    public function handle(Tenant $tenant, CarbonImmutable $now): LimitDecision
    {
        $period = $now->startOfMonth()->toDateString();
        $limit = Config::integer("postbox.governor.quota.{$tenant->plan->value}.messages_per_period");

        $used = $this->consume($period, $limit);

        return new LimitDecision(
            allowed: $used !== null,
            limit: $limit,
            remaining: $used !== null ? max(0, $limit - $used) : 0,
            resetSeconds: self::secondsUntilNextPeriod($now),
        );
    }

    /**
     * The tenant's count after this message was admitted, or null if the
     * period's ceiling was already spent.
     */
    private function consume(string $period, int $limit): ?int
    {
        if (($used = $this->incrementIfUnderLimit($period, $limit)) !== null) {
            return $used;
        }

        try {
            /*
             * A single statement wrapped in a transaction purely so a caller
             * already inside one — every test in this suite, through
             * RefreshDatabase, and any future caller that wraps Governor in
             * its own unit of work — gets a savepoint rather than a poisoned
             * one: Postgres aborts the whole enclosing transaction on a
             * constraint violation, not just the statement that caused it, so
             * the retry below needs a connection the violation has not left
             * unusable.
             */
            DB::transaction(function () use ($period): void {
                QuotaUsage::create(['period_start' => $period, 'used' => 1]);
            });

            return 1;
        } catch (UniqueConstraintViolationException) {
            /*
             * The row already existed — either a rival request just created it
             * for this period's first message, or it was there all along and
             * the increment above genuinely found it at the ceiling. Either
             * way, the increment is what answers the question now: it commits
             * a rival's late first message, and it still refuses a tenant that
             * really has spent the period.
             */
            return $this->incrementIfUnderLimit($period, $limit);
        }
    }

    /**
     * One statement: the WHERE and the increment happen together, so two
     * requests racing for the last unit of a period's quota cannot both see
     * room and both succeed.
     */
    private function incrementIfUnderLimit(string $period, int $limit): ?int
    {
        $affected = QuotaUsage::query()
            ->where('period_start', $period)
            ->where('used', '<', $limit)
            ->increment('used');

        if ($affected === 0) {
            return null;
        }

        /*
         * A second statement, purely for the number a response reports — the
         * increment above already committed the fact that matters. The same
         * trade PublishMessage's own reservation read makes.
         */
        /** @var int $used */
        $used = QuotaUsage::query()->where('period_start', $period)->value('used');

        return $used;
    }

    private static function secondsUntilNextPeriod(CarbonImmutable $now): int
    {
        return (int) $now->diffInSeconds($now->startOfMonth()->addMonthNoOverflow());
    }
}
