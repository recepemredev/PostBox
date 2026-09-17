<?php

declare(strict_types=1);

use App\Enums\AttemptOutcome;
use App\Enums\DeliveryStatus;
use App\Models\Delivery;
use App\Models\DeliveryAttempt;
use App\Models\Tenant;
use App\Support\Delivery\HttpTransport;
use App\Support\Delivery\OutboundRequest;
use App\Support\Delivery\TransportResult;

/*
 * settleTo()'s own docblock states the guarantee this file proves: two
 * workers can legitimately hold attempts 1 and 2 of the same delivery at
 * once — a slow send and a lease that expired underneath it — and the first
 * to settle wins regardless of what the slower one's own outcome turns out
 * to be. DispatchOutboxTest and BreakerTransitionTest already established the
 * house style for this shape of race: sequential calls on one connection,
 * because the property under test is a predicate UPDATE, and a predicate
 * observed sequentially sees exactly what a concurrent one would.
 *
 * The interleaving is built from the one seam AttemptDelivery has:
 * HttpTransport::send(). The first of two expected calls runs a whole second
 * worker's attempt — reservation, record and settle — before returning the
 * first worker's own result. That places the second worker's settle exactly
 * between the first worker's reservation (already done, before send() is
 * ever reached) and its own settle (not yet reached, since attempt() has not
 * returned).
 *
 * What this file deliberately does not assert: that reserveAttemptNumber()'s
 * row lock is released before the network call. RefreshDatabase wraps the
 * whole test in a transaction, so a lock taken inside AttemptDelivery's own
 * savepoint is held until the test's transaction ends regardless — the
 * opposite of what production does. That property is visible structurally
 * (the DB::transaction() in reserveAttemptNumber() returns before attempt()
 * is ever called) and confirmed behaviourally by Phase 4's own multi-worker
 * drain, not provable by a suite that cannot let two sessions block on it.
 */

/**
 * Runs worker A's attempt, and — from inside A's own send() call, before A's
 * result ever comes back — runs worker B's entire attempt to completion.
 */
function raceTwoWorkers(Tenant $tenant, Delivery $delivery): void
{
    $calls = 0;

    $mock = Mockery::mock(HttpTransport::class);
    $mock->shouldReceive('send')->twice()->andReturnUsing(
        function (OutboundRequest $request) use (&$calls, $tenant, $delivery): TransportResult {
            $calls++;

            if ($calls === 1) {
                attemptDelivery($tenant, $delivery);

                return TransportResult::responded(200, [], 'ok', 5);
            }

            return TransportResult::responded(500, [], 'server_error', 5);
        },
    );

    app()->instance(HttpTransport::class, $mock);

    attemptDelivery($tenant, $delivery);
}

it('lets the first worker to settle decide when two workers hold attempts on the same delivery', function (): void {
    [$tenant, , $delivery] = publishedDelivery();
    config(['postbox.retry.max_attempts' => 2]);

    raceTwoWorkers($tenant, $delivery);

    $settled = freshDelivery($tenant, $delivery);

    // Worker B — attempt 2, a 500 with no attempts left — settled first and
    // exhausted the delivery. Worker A's own result, a 200, arrived after;
    // its settle found the row no longer Pending and wrote nothing.
    expect($settled->status)->toBe(DeliveryStatus::Exhausted)
        ->and($settled->exhausted_at)->not->toBeNull()
        ->and($settled->attempt_count)->toBe(2);
});

it('records both attempts even though only one settle lands', function (): void {
    [$tenant, , $delivery] = publishedDelivery();
    config(['postbox.retry.max_attempts' => 2]);

    raceTwoWorkers($tenant, $delivery);

    $attempts = forTenant($tenant, fn () => DeliveryAttempt::query()->orderBy('attempt_number')->get());

    expect($attempts)->toHaveCount(2)
        ->and($attempts[0]->attempt_number)->toBe(1)
        ->and($attempts[0]->outcome)->toBe(AttemptOutcome::Succeeded)
        ->and($attempts[0]->response_status)->toBe(200)
        ->and($attempts[1]->attempt_number)->toBe(2)
        ->and($attempts[1]->outcome)->toBe(AttemptOutcome::Failed)
        ->and($attempts[1]->response_status)->toBe(500);
});
