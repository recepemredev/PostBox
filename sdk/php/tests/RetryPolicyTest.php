<?php

declare(strict_types=1);

use PostBox\RetryPolicy;

beforeEach(function (): void {
    $this->policy = new RetryPolicy(maxAttempts: 3, baseDelayMs: 500, factor: 2, ceilingMs: 8_000);
});

it('computes the delay before the next attempt as base times factor to the attempt minus one', function (): void {
    expect($this->policy->delayMs(1))->toBe(500)
        ->and($this->policy->delayMs(2))->toBe(1_000)
        ->and($this->policy->delayMs(3))->toBe(2_000);
});

it('clamps at the ceiling however high the attempt number climbs', function (): void {
    expect($this->policy->delayMs(10))->toBe(8_000);
});

it('lets a Retry-After value override the computed delay outright', function (): void {
    expect($this->policy->delayMs(1, retryAfterSeconds: 5))->toBe(5_000);
});

it('treats a transport failure, a 429 and every 5xx as retryable', function (?int $status): void {
    expect($this->policy->isRetryable($status))->toBeTrue();
})->with([null, 429, 500, 503]);

it('never treats a 4xx other than 429 as retryable', function (int $status): void {
    expect($this->policy->isRetryable($status))->toBeFalse();
})->with([400, 401, 402, 404, 409, 422]);

it('treats 200 as not retryable — a successful response never reaches the check', function (): void {
    expect($this->policy->isRetryable(200))->toBeFalse();
});
