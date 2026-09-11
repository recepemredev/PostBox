<?php

declare(strict_types=1);

use App\Models\QuotaUsage;
use App\Models\Tenant;

/*
 * Governor sits in front of everything Ingest does. What is worth proving here
 * is the ordering and the separation: a rate-limited request never touches
 * quota, a quota-exhausted one still reports the rate limit token it
 * genuinely spent, and a plan's numbers actually reach the wire.
 */

beforeEach(function (): void {
    $this->tenant = tenantNamed('Acme');
    $this->token = issueKeyFor($this->tenant, memberOf($this->tenant))->token;

    [$this->application, $this->eventType] = registerProducer($this->tenant);
});

it('reports both limits on a successful response', function (): void {
    config([
        'postbox.governor.rate_limit.free.capacity' => 20,
        'postbox.governor.quota.free.messages_per_period' => 5,
    ]);

    publishEvent(invoicePaid())
        ->assertCreated()
        ->assertHeader('RateLimit-Limit', '20')
        ->assertHeader('RateLimit-Remaining', '19')
        ->assertHeader('Quota-Limit', '5')
        ->assertHeader('Quota-Remaining', '4');
});

it('refuses with 429 and Retry-After once the bucket is empty', function (): void {
    config([
        'postbox.governor.rate_limit.free.capacity' => 2,
        'postbox.governor.rate_limit.free.refill_per_second' => 1,
        'postbox.governor.quota.free.messages_per_period' => 1000,
    ]);

    publishEvent(invoicePaid())->assertCreated();
    publishEvent(invoicePaid())->assertCreated();

    publishEvent(invoicePaid())
        ->assertStatus(429)
        ->assertHeader('Retry-After')
        ->assertHeader('RateLimit-Remaining', '0');
});

it('refuses with 402 and no Retry-After once the quota is spent', function (): void {
    config([
        'postbox.governor.rate_limit.free.capacity' => 100,
        'postbox.governor.quota.free.messages_per_period' => 2,
    ]);

    publishEvent(invoicePaid())->assertCreated();
    publishEvent(invoicePaid())->assertCreated();

    publishEvent(invoicePaid())
        ->assertStatus(402)
        ->assertHeader('Quota-Remaining', '0')
        ->assertHeaderMissing('Retry-After');
});

it('gives a pro tenant a larger rate limit than a free one', function (): void {
    $pro = Tenant::factory()->pro()->create(['name' => 'Initech']);
    $token = issueKeyFor($pro, memberOf($pro))->token;
    [$application] = registerProducer($pro);

    publishEvent(invoicePaid(), token: $token, applicationId: $application->public_id)
        ->assertHeader('RateLimit-Limit', '200');
});

it('does not consume quota when the rate limit already refused the request', function (): void {
    config([
        'postbox.governor.rate_limit.free.capacity' => 1,
        'postbox.governor.rate_limit.free.refill_per_second' => 1,
        'postbox.governor.quota.free.messages_per_period' => 1000,
    ]);

    publishEvent(invoicePaid())->assertCreated();
    publishEvent(invoicePaid())->assertStatus(429);

    forTenant($this->tenant, fn () => expect(QuotaUsage::query()->sole()->used)->toBe(1));
});

it('still reports the rate limit token it spent when the quota then refuses', function (): void {
    config([
        'postbox.governor.rate_limit.free.capacity' => 100,
        'postbox.governor.quota.free.messages_per_period' => 1,
    ]);

    publishEvent(invoicePaid())->assertCreated();

    publishEvent(invoicePaid())
        ->assertStatus(402)
        ->assertHeader('RateLimit-Remaining', '98');
});

it('consumes quota even when the request body fails validation', function (): void {
    config(['postbox.governor.quota.free.messages_per_period' => 5]);

    publishEvent(['event_type' => 'invoice.paid'])->assertUnprocessable();

    forTenant($this->tenant, fn () => expect(QuotaUsage::query()->sole()->used)->toBe(1));
});
