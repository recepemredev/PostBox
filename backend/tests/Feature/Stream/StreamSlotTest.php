<?php

declare(strict_types=1);

use App\Support\Stream\StreamSlots;
use Carbon\CarbonImmutable;

/**
 * "Disconnect releases resources" and the fan-out bound. The Lua script's
 * own score-based expiry is the backstop for a killed FPM child that never
 * called release() — a crash costs one slot for one lifetime rather than
 * forever.
 */
beforeEach(function (): void {
    $this->acme = tenantNamed('Acme');
    $this->globex = tenantNamed('Globex');
    $this->slots = app(StreamSlots::class);
    $this->now = CarbonImmutable::parse('2026-09-23 12:00:00');
    $this->max = config('postbox.stream.max_concurrent_per_tenant');
});

it('admits up to max_concurrent_per_tenant and refuses the next', function (): void {
    for ($i = 1; $i <= $this->max; $i++) {
        expect($this->slots->admit($this->acme, "conn-{$i}", $this->now))->toBeTrue();
    }

    expect($this->slots->admit($this->acme, 'conn-excess', $this->now))->toBeFalse()
        ->and($this->slots->active($this->acme, $this->now))->toBe($this->max);
});

it('a released slot frees capacity immediately', function (): void {
    for ($i = 1; $i <= $this->max; $i++) {
        $this->slots->admit($this->acme, "conn-{$i}", $this->now);
    }

    $this->slots->release($this->acme, 'conn-1');

    expect($this->slots->active($this->acme, $this->now))->toBe($this->max - 1)
        ->and($this->slots->admit($this->acme, 'conn-new', $this->now))->toBeTrue();
});

it('an expired slot frees capacity without a release — the killed-child case', function (): void {
    for ($i = 1; $i <= $this->max; $i++) {
        $this->slots->admit($this->acme, "conn-{$i}", $this->now);
    }

    $lifetimeSeconds = config('postbox.stream.max_lifetime_seconds');
    $past = $this->now->addSeconds($lifetimeSeconds + 6); // past ttl_ms = lifetime + 5s grace

    expect($this->slots->active($this->acme, $past))->toBe(0)
        ->and($this->slots->admit($this->acme, 'conn-after-expiry', $past))->toBeTrue();
});

it('one tenant\'s slots never count against another\'s', function (): void {
    for ($i = 1; $i <= $this->max; $i++) {
        $this->slots->admit($this->acme, "acme-{$i}", $this->now);
    }

    expect($this->slots->active($this->globex, $this->now))->toBe(0);

    for ($i = 1; $i <= $this->max; $i++) {
        expect($this->slots->admit($this->globex, "globex-{$i}", $this->now))->toBeTrue();
    }
});

it('the route answers 429 with Retry-After once the tenant\'s cap is already full', function (): void {
    $user = memberOf($this->acme);

    for ($i = 1; $i <= $this->max; $i++) {
        $this->slots->admit($this->acme, "conn-{$i}", CarbonImmutable::now());
    }

    $this->actingAs($user)
        ->getJson('/api/v1/stream')
        ->assertStatus(429)
        ->assertHeader('Retry-After')
        ->assertJsonStructure(['message']);
});
