<?php

declare(strict_types=1);

use App\Enums\AttemptOutcome;
use App\Support\Resilience\JitterSource;
use App\Support\Resilience\RetryPolicy;
use Carbon\CarbonImmutable;

/*
 * The retry schedule, asserted as a sequence rather than as a property. This is
 * the test the jitter interface exists for: with the spread pinned, "base 10s,
 * factor 3, ceiling 10 minutes" is a fixed list of delays that can be checked
 * against the arithmetic on paper, and a change to any one of the five numbers
 * that define the schedule shows up here as a different list.
 *
 * The policy is exercised directly. It has one public method and no state of
 * its own, and reaching it through a delivery would be asserting the schedule
 * through a second thing that could fail.
 */

/**
 * The policy, with the spread of the equal-jitter delay pinned.
 */
function policyWithJitter(float $fraction): RetryPolicy
{
    $jitter = Mockery::mock(JitterSource::class);
    $jitter->shouldReceive('fraction')->andReturn($fraction);

    return new RetryPolicy($jitter);
}

/**
 * The whole schedule in milliseconds, one entry per attempt that is followed
 * by another. Milliseconds because that is the unit the policy works in — a
 * delay asserted in seconds would be a rounded version of the answer.
 *
 * @return list<int>
 */
function scheduleMs(float $fraction): array
{
    $policy = policyWithJitter($fraction);
    $now = CarbonImmutable::now();

    $delays = [];

    for ($attempt = 1; $attempt <= config()->integer('postbox.retry.max_attempts'); $attempt++) {
        $decision = $policy->decide(AttemptOutcome::Timeout, null, $attempt, $now);

        if (! $decision->shouldRetry) {
            break;
        }

        $delays[] = (int) $now->diffInMilliseconds($decision->nextAttemptAt, absolute: true);
    }

    return $delays;
}

beforeEach(function (): void {
    // Round numbers, so the expected sequences below can be read straight off
    // the arithmetic: 10s growing threefold, capped at ten minutes.
    config()->set('postbox.retry.max_attempts', 6);
    config()->set('postbox.retry.base_delay_ms', 10_000);
    config()->set('postbox.retry.growth_factor', 3);
    config()->set('postbox.retry.ceiling_ms', 600_000);
});

it('produces the exact schedule when the jitter sits at its midpoint', function (): void {
    // temp:  10s · 30s · 90s · 270s · 600s (the ceiling, not 810s)
    // delay: temp/2 + 0.5 · temp/2 — three quarters of each
    expect(scheduleMs(0.5))->toBe([7_500, 22_500, 67_500, 202_500, 450_000]);
});

it('bottoms out at half the exponential when the jitter is zero', function (): void {
    expect(scheduleMs(0.0))->toBe([5_000, 15_000, 45_000, 135_000, 300_000]);
});

it('tops out at the full exponential when the jitter is one', function (): void {
    expect(scheduleMs(1.0))->toBe([10_000, 30_000, 90_000, 270_000, 600_000]);
});

it('clamps at the ceiling however high the attempt number climbs', function (): void {
    config()->set('postbox.retry.max_attempts', 1_000);

    $now = CarbonImmutable::now();
    $decision = policyWithJitter(1.0)->decide(AttemptOutcome::Timeout, null, 900, $now);

    expect($decision->shouldRetry)->toBeTrue()
        ->and((int) $now->diffInMilliseconds($decision->nextAttemptAt, absolute: true))->toBe(600_000);
});

it('stops at the last attempt rather than scheduling one more', function (): void {
    $decision = policyWithJitter(0.5)->decide(AttemptOutcome::Timeout, null, 6, CarbonImmutable::now());

    expect($decision->shouldRetry)->toBeFalse()
        ->and($decision->nextAttemptAt)->toBeNull()
        ->and($decision->reason)->toBe('exhausted after 6 attempts; last outcome: timeout');
});

it('schedules one delay fewer than it allows attempts', function (): void {
    expect(scheduleMs(0.5))->toHaveCount(config()->integer('postbox.retry.max_attempts') - 1);
});
