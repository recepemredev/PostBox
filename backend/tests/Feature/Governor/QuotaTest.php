<?php

declare(strict_types=1);

use App\Actions\Governor\ConsumeQuota;
use App\Models\QuotaUsage;
use App\Models\Tenant;
use App\Support\Governor\LimitDecision;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
 * The quota is cumulative and lives in Postgres, on purpose apart from the
 * token bucket: it is a billable fact, and Redis being allowed to lose it is
 * not. The last test in this file is the one that matters most — the same
 * race Step 4's idempotency reservation proved, here against the conditional
 * UPDATE that is this table's whole guarantee.
 */

function consumeQuota(Tenant $tenant, CarbonImmutable $now): LimitDecision
{
    return forTenant($tenant, fn (): LimitDecision => app(ConsumeQuota::class)->handle($tenant, $now));
}

it('accumulates usage across messages within a period', function (): void {
    $tenant = tenantNamed('Acme');
    $now = CarbonImmutable::now();

    $first = consumeQuota($tenant, $now);
    $second = consumeQuota($tenant, $now);

    expect($first->allowed)->toBeTrue()
        ->and($first->remaining)->toBe($first->limit - 1)
        ->and($second->allowed)->toBeTrue()
        ->and($second->remaining)->toBe($first->limit - 2);
});

it('denies a message once the period ceiling is spent', function (): void {
    config(['postbox.governor.quota.free.messages_per_period' => 2]);

    $tenant = tenantNamed('Acme');
    $now = CarbonImmutable::now();

    consumeQuota($tenant, $now)->allowed;
    consumeQuota($tenant, $now)->allowed;
    $exhausted = consumeQuota($tenant, $now);

    expect($exhausted->allowed)->toBeFalse()
        ->and($exhausted->remaining)->toBe(0);

    forTenant($tenant, fn () => expect(QuotaUsage::query()->sole()->used)->toBe(2));
});

it('resets at the calendar month boundary', function (): void {
    config(['postbox.governor.quota.free.messages_per_period' => 2]);

    $tenant = tenantNamed('Acme');
    $firstMonth = CarbonImmutable::create(2026, 1, 15, 12);
    $nextMonth = CarbonImmutable::create(2026, 2, 1, 0);

    consumeQuota($tenant, $firstMonth);
    consumeQuota($tenant, $firstMonth);
    $exhausted = consumeQuota($tenant, $firstMonth);

    expect($exhausted->allowed)->toBeFalse();

    $fresh = consumeQuota($tenant, $nextMonth);

    expect($fresh->allowed)->toBeTrue()
        ->and($fresh->remaining)->toBe(1);

    forTenant($tenant, fn () => expect(QuotaUsage::query()->count())->toBe(2));
});

it('keeps two tenants usage separate', function (): void {
    config(['postbox.governor.quota.free.messages_per_period' => 1]);

    $acme = tenantNamed('Acme');
    $globex = tenantNamed('Globex');
    $now = CarbonImmutable::now();

    consumeQuota($acme, $now);

    expect(consumeQuota($acme, $now)->allowed)->toBeFalse()
        ->and(consumeQuota($globex, $now)->allowed)->toBeTrue();
});

it('reports seconds remaining until the next calendar month', function (): void {
    $tenant = tenantNamed('Acme');
    $now = CarbonImmutable::create(2026, 3, 30, 12);

    $decision = consumeQuota($tenant, $now);

    // 1 day and 12 hours from noon on the 30th to midnight on April 1st.
    expect($decision->resetSeconds)->toBe(36 * 60 * 60)
        ->and($decision->retryAfterSeconds)->toBeNull();
});

/*
 * The race.
 *
 * RefreshDatabase wraps the test in a transaction on the application
 * connection, so a second session cannot normally see anything the test set
 * up. The tenant is therefore committed for real through the owner's
 * connection — committedTenant(), the same trick Step 4's idempotency race
 * uses — and removed once the test's own transaction has rolled back.
 */

it('counts both sides of two requests racing to be the first of a period', function (): void {
    config(['postbox.governor.quota.free.messages_per_period' => 5]);

    $tenant = committedTenant('Racer');
    $now = CarbonImmutable::now();
    $period = $now->startOfMonth()->toDateString();

    /*
     * Our own row does not exist yet, so the conditional UPDATE inside
     * ConsumeQuota misses and it falls back to creating one. Hanging the
     * rival off QuotaUsage's own creating event lands it exactly there: the
     * rival commits its row for the same tenant and period a moment before
     * our INSERT statement runs, so ours is the one that hits the unique
     * constraint.
     */
    QuotaUsage::creating(function () use ($tenant, $period): void {
        $rival = DB::connection('pgsql_admin');

        // Row Level Security is FORCE, so the rival session says which
        // tenant it acts for exactly as the application does.
        $rival->statement('select set_config(?, ?, false)', ['postbox.tenant_id', (string) $tenant->id]);

        $rival->table('quota_usage')->insert([
            'tenant_id' => $tenant->id,
            'period_start' => $period,
            'used' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    $decision = consumeQuota($tenant, $now);

    expect($decision->allowed)->toBeTrue()
        ->and($decision->remaining)->toBe(3); // limit 5, used 2: the rival's row plus ours

    forTenant($tenant, function (): void {
        expect(QuotaUsage::query()->count())->toBe(1)
            ->and(QuotaUsage::query()->sole()->used)->toBe(2);
    });
});
