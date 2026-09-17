<?php

declare(strict_types=1);

use App\Actions\Ingest\DispatchOutbox;
use App\Jobs\SendDelivery;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/*
 * `for update skip locked` is the half of the outbox claim no earlier test has
 * ever put under real contention. DispatchOutboxTest proves the lease with two
 * sequential calls on one connection — enough for "a delivery whose worker
 * never ran comes back", but it proves nothing about two dispatchers claiming
 * at the same moment, because a single connection can never hold a lock
 * against itself.
 *
 * RefreshDatabase's own ambient transaction is what makes a genuine
 * two-session version possible without a third connection. Postgres holds a
 * row lock taken inside a savepoint until the *top-level* transaction ends,
 * not until the savepoint itself closes — and under RefreshDatabase,
 * DispatchOutbox::claim()'s own DB::transaction() is exactly a savepoint. So
 * once a claim returns inside a test, the test's own session is still holding
 * FOR UPDATE on every row it claimed, for as long as the test runs. That is
 * "worker A has claimed a batch and is still working on it," for free.
 *
 * Worker B runs on pgsql_admin — a different session, which is the only thing
 * SKIP LOCKED cares about. It runs as the schema owner rather than the
 * application role, but FOR UPDATE SKIP LOCKED is not role-dependent, and
 * FORCE row-level security means the owner's visible row set under a bound
 * tenant is identical to the application role's. What runs on both sides is
 * the real DispatchOutbox action, never a hand-written second copy of the
 * claim query — a copy here would be a second definition of what "claim"
 * means and could silently drift from the one the application ships.
 */

beforeEach(function (): void {
    Queue::fake();
});

/**
 * The delivery public ids SendDelivery has been dispatched with so far, in
 * push order. Queue::fake() is a container binding, not a per-connection one,
 * so it sees every push from either session without being told which side
 * made it — the caller works that out from when it asks.
 *
 * @return list<string>
 */
function pushedDeliveryIds(): array
{
    return collect(Queue::pushedJobs()[SendDelivery::class] ?? [])
        ->pluck('job')
        ->map(fn (SendDelivery $job): string => $job->deliveryId)
        ->all();
}

/**
 * Runs the real DispatchOutbox action on a second, genuinely concurrent
 * session, and returns the delivery ids it alone handed to the queue.
 *
 * lock_timeout is the fail-fast device "lock contention does not deadlock"
 * needs: if SKIP LOCKED were ever dropped from the claim query, this session
 * would block on the rows the caller's own session is still holding, and
 * Postgres would raise 55P03 well inside the timeout rather than the test
 * hanging until CI kills it.
 *
 * The push list is sliced by count rather than diffed by value: the same
 * delivery id can legitimately appear twice across two calls (a rolled-back
 * claim followed by a real one reaches the same rows), and a value-based
 * diff would wrongly treat the second push as "already seen" and discard it.
 *
 * @return list<string>
 */
function rivalClaim(Tenant $tenant): array
{
    $before = pushedDeliveryIds();

    DB::connection('pgsql_admin')->statement("set lock_timeout = '2s'");
    DB::connection('pgsql_admin')->statement("set statement_timeout = '5s'");

    DB::setDefaultConnection('pgsql_admin');

    try {
        forTenant($tenant, fn (): int => app(DispatchOutbox::class)->handle());
    } finally {
        DB::setDefaultConnection('pgsql');
    }

    return array_slice(pushedDeliveryIds(), count($before));
}

it('hands two concurrent dispatchers disjoint sets of deliveries', function (): void {
    config(['postbox.outbox.batch_size' => 2]);

    [$tenant] = committedOutbox('Racer', deliveries: 4);

    forTenant($tenant, fn (): int => app(DispatchOutbox::class)->handle());
    $aIds = pushedDeliveryIds();

    $bIds = rivalClaim($tenant);

    expect($aIds)->toHaveCount(2)
        ->and($bIds)->toHaveCount(2)
        ->and(array_intersect($aIds, $bIds))->toBe([]);
});

it('claims every due delivery exactly once across two concurrent dispatchers', function (): void {
    config(['postbox.outbox.batch_size' => 2]);

    [$tenant, , $ids] = committedOutbox('Racer', deliveries: 4);

    forTenant($tenant, fn (): int => app(DispatchOutbox::class)->handle());
    $aIds = pushedDeliveryIds();

    $bIds = rivalClaim($tenant);

    // Nothing is claimed twice and nothing is left behind: the union of what
    // two concurrent dispatchers claimed is exactly the outbox they started
    // with. This is "no lost delivery under parallel workers" in its
    // positive form.
    sort($ids);
    $claimed = [...$aIds, ...$bIds];
    sort($claimed);

    expect($claimed)->toBe($ids);
});

it('does not block the second dispatcher on the rows the first is still holding', function (): void {
    config(['postbox.outbox.batch_size' => 2]);

    [$tenant] = committedOutbox('Racer', deliveries: 4);

    forTenant($tenant, fn (): int => app(DispatchOutbox::class)->handle());

    // The whole assertion is that this call returns at all. Two of the four
    // rows are still locked by this test's own, still-open transaction — if
    // SKIP LOCKED were ever dropped from the claim query, the rival would
    // queue behind those locks and rivalClaim()'s own lock_timeout would
    // raise 55P03 well before this expectation ever ran.
    $bIds = rivalClaim($tenant);

    expect($bIds)->toHaveCount(2);
});

it('gives a delivery back when the dispatcher that claimed it rolled back', function (): void {
    config(['postbox.outbox.batch_size' => 4]);

    [$tenant, , $ids] = committedOutbox('Racer', deliveries: 4);

    // Simulates a worker container killed between the claim and the moment
    // that claim would have committed. In production claim()'s own
    // DB::transaction() is the outermost one — once it returns, the lease is
    // durably committed, full stop. The only way to observe "never committed
    // at all" from a single test process is to wrap it in one more
    // transaction and abort that: claim()'s own transaction already returned
    // normally by this point, releasing its savepoint, but releasing a
    // savepoint does not survive a rollback to an earlier one — Postgres
    // undoes it, lock and lease write both, along with everything else this
    // outer transaction touched.
    //
    // What this cannot prove, and does not try to: the job this pass
    // dispatched still shows up in Queue::fake()'s own record, because
    // enqueueing is a PHP-level call, not a statement inside this SQL
    // transaction — exactly the reason production keeps the two separate
    // (architecture.md: "a crash between commit and enqueue costs latency,
    // not data"). What is provable, and is the whole point of this test, is
    // the database's own state: whether the row a crashed claim touched is
    // immediately available to somebody else, not merely available once a
    // lease expires.
    try {
        DB::transaction(function () use ($tenant): void {
            forTenant($tenant, fn (): int => app(DispatchOutbox::class)->handle());

            throw new RuntimeException('simulated crash after claim, before commit');
        });
    } catch (RuntimeException) {
        // Expected.
    }

    $bIds = rivalClaim($tenant);

    sort($ids);
    sort($bIds);
    expect($bIds)->toBe($ids);
});
