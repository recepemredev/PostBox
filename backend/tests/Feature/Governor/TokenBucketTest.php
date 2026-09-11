<?php

declare(strict_types=1);

use App\Actions\Governor\ConsumeRateLimit;
use App\Models\Tenant;
use App\Support\Governor\LimitDecision;
use Carbon\CarbonImmutable;

/*
 * The bucket is one atomic Lua script, so what is worth asserting here is
 * arithmetic under a controlled clock rather than Redis's own atomicity — a
 * single script cannot interleave with itself, which is exactly why it is
 * built as one. Exhausting a bucket with rapid, back-to-back calls is the
 * closest a synchronous test gets to concurrent requests, and it is enough:
 * the script either grants a token or it does not, with no interleaving for
 * two calls to race over.
 *
 * Every test uses the free plan's numbers — capacity 20, refill 10/second —
 * because they are the real committed config, not a test-only override, and
 * because 100ms per token keeps the arithmetic exact.
 */

function consumeRateLimit(Tenant $tenant, CarbonImmutable $now): LimitDecision
{
    return app(ConsumeRateLimit::class)->handle($tenant, $now);
}

it('grants exactly capacity tokens in the same instant, then refuses the next', function (): void {
    $tenant = tenantNamed('Acme');
    $now = CarbonImmutable::now();

    for ($i = 0; $i < 20; $i++) {
        $decision = consumeRateLimit($tenant, $now);

        expect($decision->allowed)->toBeTrue()
            ->and($decision->remaining)->toBe(19 - $i);
    }

    $denied = consumeRateLimit($tenant, $now);

    expect($denied->allowed)->toBeFalse()
        ->and($denied->remaining)->toBe(0)
        // One token is 100ms away; Retry-After is expressed in whole seconds,
        // so a 100ms wait still rounds up to the smallest unit that header has.
        ->and($denied->retryAfterSeconds)->toBe(1);
});

it('reports the plan capacity as the limit regardless of what remains', function (): void {
    $tenant = tenantNamed('Acme');

    $decision = consumeRateLimit($tenant, CarbonImmutable::now());

    expect($decision->limit)->toBe(20);
});

it('refills at the configured rate under a controlled clock', function (): void {
    $tenant = tenantNamed('Acme');
    $start = CarbonImmutable::now();

    for ($i = 0; $i < 20; $i++) {
        consumeRateLimit($tenant, $start);
    }

    // At 10 tokens/second, 250ms buys 2 whole tokens; this call spends one of
    // them and leaves the other banked, rather than losing the 50ms remainder.
    $decision = consumeRateLimit($tenant, $start->addMilliseconds(250));

    expect($decision->allowed)->toBeTrue()
        ->and($decision->remaining)->toBe(1);
});

it('never grows the bucket past capacity no matter how much time passes', function (): void {
    $tenant = tenantNamed('Acme');
    $now = CarbonImmutable::now();

    consumeRateLimit($tenant, $now);

    $decision = consumeRateLimit($tenant, $now->addHour());

    // Uncapped, an hour at 10/s would bank 36,000 tokens on top of the 19
    // left. Capped, it can only climb back to 20 before this call spends one.
    expect($decision->allowed)->toBeTrue()
        ->and($decision->remaining)->toBe(19);
});

it('keeps two tenants in separate buckets', function (): void {
    $acme = tenantNamed('Acme');
    $globex = tenantNamed('Globex');
    $now = CarbonImmutable::now();

    for ($i = 0; $i < 20; $i++) {
        consumeRateLimit($acme, $now);
    }

    expect(consumeRateLimit($acme, $now)->allowed)->toBeFalse()
        ->and(consumeRateLimit($globex, $now)->allowed)->toBeTrue();
});

it('gives a pro tenant a larger bucket than a free one', function (): void {
    $pro = Tenant::factory()->pro()->create(['name' => 'Initech']);

    $decision = consumeRateLimit($pro, CarbonImmutable::now());

    expect($decision->limit)->toBe(200);
});
