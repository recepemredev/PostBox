<?php

declare(strict_types=1);

use App\Actions\Resilience\AdmitEndpoint;
use App\Actions\Resilience\TransitionBreaker;
use App\Enums\BreakerState;
use App\Models\AuditLog;
use App\Models\Endpoint;
use App\Models\EndpointCircuitBreaker;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
 * TransitionBreaker's own docblock states the guarantee: two workers
 * proposing the same move have exactly one winner, and the loser gets false
 * rather than an exception. BreakerTransitionTest proves every legal and
 * illegal move through a single session; what it does not reach — and never
 * claimed to, once the comment it used to carry is corrected in this same
 * commit — is a genuine second session racing for the same move at the same
 * moment. This file is that second session, for both of TransitionBreaker's
 * write shapes (the first-trip insert, the compare-and-set claim) and for
 * AdmitEndpoint's own two lost-race branches, which read whatever the winner
 * left behind rather than assume which move it was.
 *
 * update()'s conditional writes fire no model events — updating() is not
 * available to hang a rival off — so the hook here is retrieved(), the
 * moment between a read and whatever conditional write follows it. A rival
 * committed through pgsql_admin at that moment is visible to the very next
 * statement this session issues, the same visibility committedTenant() and
 * committedOutbox() rely on elsewhere.
 */

/**
 * A breaker row already sitting at the given state, committed for real
 * through the schema owner's connection — the fixture the row-moved-between-
 * read-and-write races need, since a row built inside this test's own
 * ambient transaction would be invisible to a rival session entirely.
 */
function committedBreaker(Tenant $tenant, Endpoint $endpoint, BreakerState $state, ?CarbonImmutable $at = null): EndpointCircuitBreaker
{
    $at ??= CarbonImmutable::now();

    DB::setDefaultConnection('pgsql_admin');

    try {
        $factory = EndpointCircuitBreaker::factory()->for($endpoint);

        $breaker = forTenant($tenant, fn (): EndpointCircuitBreaker => match ($state) {
            BreakerState::Open => $factory->create(['opened_at' => $at, 'state_changed_at' => $at]),
            BreakerState::HalfOpen => $factory->halfOpen()->create(['probe_started_at' => $at, 'state_changed_at' => $at]),
            BreakerState::Closed => $factory->closed()->create(),
        });
    } finally {
        DB::setDefaultConnection('pgsql');
    }

    return $breaker;
}

it('tells the loser of a first trip that it did not open the breaker', function (): void {
    [$tenant, $endpoint] = committedOutbox('Racer', deliveries: 0);

    // No row exists yet, so the write TransitionBreaker::open() attempts is
    // an INSERT — creating() is where the rival belongs, the same position
    // QuotaUsage::creating() occupies in Governor's own first-row race.
    EndpointCircuitBreaker::creating(function () use ($tenant, $endpoint): void {
        $rival = DB::connection('pgsql_admin');
        $rival->statement('select set_config(?, ?, false)', ['postbox.tenant_id', (string) $tenant->id]);

        $rival->table('endpoint_circuit_breakers')->insert([
            'tenant_id' => $tenant->id,
            'endpoint_id' => $endpoint->id,
            'state' => BreakerState::Open->value,
            'state_changed_at' => now(),
            'opened_at' => now(),
            'probe_started_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    $won = forTenant(
        $tenant,
        fn (): bool => app(TransitionBreaker::class)->handle($endpoint, BreakerState::Open, CarbonImmutable::now()),
    );

    expect($won)->toBeFalse();

    forTenant($tenant, function (): void {
        expect(EndpointCircuitBreaker::query()->count())->toBe(1)
            // A losing call never reaches record() — the row and the audit
            // entry are always written together, and this call wrote neither.
            ->and(AuditLog::query()->count())->toBe(0);
    });
});

it('tells the loser it did not make the change when the row moved between the read and the write', function (): void {
    [$tenant, $endpoint] = committedOutbox('Racer', deliveries: 0);
    committedBreaker($tenant, $endpoint, BreakerState::Open);

    $fired = false;

    EndpointCircuitBreaker::retrieved(function (EndpointCircuitBreaker $breaker) use (&$fired, $tenant): void {
        if ($fired) {
            return;
        }
        $fired = true;

        // retrieved() fires after this session's own read already holds the
        // old values in memory, so our own $from stays Open — the rival's
        // move only changes what claim()'s conditional UPDATE finds when it
        // runs a moment later.
        $rival = DB::connection('pgsql_admin');
        $rival->statement('select set_config(?, ?, false)', ['postbox.tenant_id', (string) $tenant->id]);
        $rival->table('endpoint_circuit_breakers')->where('id', $breaker->id)->update([
            'state' => BreakerState::HalfOpen->value,
            'state_changed_at' => now(),
            'probe_started_at' => now(),
            'updated_at' => now(),
        ]);
    });

    $won = forTenant(
        $tenant,
        fn (): bool => app(TransitionBreaker::class)->handle($endpoint, BreakerState::HalfOpen, CarbonImmutable::now()),
    );

    expect($won)->toBeFalse();

    forTenant($tenant, function (): void {
        expect(AuditLog::query()->count())->toBe(0)
            ->and(EndpointCircuitBreaker::query()->sole()->state)->toBe(BreakerState::HalfOpen);
    });
});

it('catches up to the winner when another pass admitted the probe first', function (): void {
    config(['postbox.breaker.open_seconds' => 60, 'postbox.breaker.probe_timeout_seconds' => 300]);

    [$tenant, $endpoint] = committedOutbox('Racer', deliveries: 0);

    $now = CarbonImmutable::now();
    committedBreaker($tenant, $endpoint, BreakerState::Open, at: $now->subSeconds(120));

    // Two retrievals happen before AdmitEndpoint's own admission call ever
    // reaches TransitionBreaker: the endpoint's eager-loaded breaker relation
    // (AdmitEndpoint::handle()'s own read, which decides Open means "ask
    // whether the hold has passed"), then TransitionBreaker::current()'s
    // separate read inside the transition it attempts. The rival belongs on
    // the second: moving the row on the first would leave this session
    // reading half-open→half-open, an illegal move BreakerState itself
    // rejects, rather than the lost race this test means to prove.
    $retrievals = 0;

    EndpointCircuitBreaker::retrieved(function (EndpointCircuitBreaker $breaker) use (&$retrievals, $tenant, $now): void {
        $retrievals++;

        if ($retrievals !== 2) {
            return;
        }

        $rival = DB::connection('pgsql_admin');
        $rival->statement('select set_config(?, ?, false)', ['postbox.tenant_id', (string) $tenant->id]);
        $rival->table('endpoint_circuit_breakers')->where('id', $breaker->id)->update([
            'state' => BreakerState::HalfOpen->value,
            'state_changed_at' => $now,
            'probe_started_at' => $now,
            'updated_at' => $now,
        ]);
    });

    $admission = forTenant($tenant, fn () => app(AdmitEndpoint::class)->handle($endpoint, $now));

    // Not a fresh probe of our own — the rival's probe is already running,
    // and this pass defers to its timeout instead of starting a second one.
    // probe_started_at is a timestamp(0) column (BreakerTransitionTest makes
    // the same trade for opened_at), so the comparison is to whole-second
    // precision rather than a strict equalTo() a microsecond could tie on.
    expect($admission->allowsAll)->toBeFalse()
        ->and($admission->allowsProbe)->toBeFalse()
        ->and($admission->until?->getTimestamp())->toBe($now->addSeconds(300)->getTimestamp());
});

it('defers instead of admitting a second probe when another pass re-armed first', function (): void {
    config(['postbox.breaker.probe_timeout_seconds' => 300]);

    [$tenant, $endpoint] = committedOutbox('Racer', deliveries: 0);

    $now = CarbonImmutable::now();
    $staleProbeStart = $now->subSeconds(400); // past its own timeout already

    committedBreaker($tenant, $endpoint, BreakerState::HalfOpen, at: $staleProbeStart);

    // A stale in-memory read, taken before the rival's own re-arm — no hook
    // needed here, unlike the two tests above: fromHalfOpen() reads
    // probe_started_at off whatever relation the caller already loaded, so a
    // caller holding an old object is by itself the whole race.
    $stale = forTenant(
        $tenant,
        fn (): Endpoint => Endpoint::query()->with('breaker')->whereKey($endpoint->id)->firstOrFail(),
    );

    // Another pass re-armed the same row moments ago, well within its own
    // timeout — a real committed UPDATE, not a second committedBreaker()
    // call, which would attempt a second INSERT against the unique
    // constraint on (tenant_id, endpoint_id) instead of moving this one.
    $rearmedAt = $now->subSeconds(5);

    $rival = DB::connection('pgsql_admin');
    $rival->statement('select set_config(?, ?, false)', ['postbox.tenant_id', (string) $tenant->id]);
    $rival->table('endpoint_circuit_breakers')->where('endpoint_id', $endpoint->id)->update([
        'probe_started_at' => $rearmedAt,
        'state_changed_at' => $rearmedAt,
        'updated_at' => $rearmedAt,
    ]);

    $admission = forTenant($tenant, fn () => app(AdmitEndpoint::class)->handle($stale, $now));

    expect($admission->allowsAll)->toBeFalse()
        ->and($admission->allowsProbe)->toBeFalse()
        ->and($admission->until?->getTimestamp())->toBe($rearmedAt->addSeconds(300)->getTimestamp());
});
