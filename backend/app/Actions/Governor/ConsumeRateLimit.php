<?php

declare(strict_types=1);

namespace App\Actions\Governor;

use App\Models\Tenant;
use App\Support\Governor\LimitDecision;
use App\Support\Governor\RateLimit;
use App\Support\Governor\TokenBucket;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;

/**
 * Spends one token from a tenant's bucket, if it has one to spend.
 *
 * The bucket's shape comes from the tenant's plan, read from config rather than
 * the tenant row: capacity and refill rate are a product decision, the same one
 * max_payload_bytes already is, not a per-tenant value an operator edits. The
 * bucket itself is keyed by the tenant's public id, so it is one Redis key per
 * tenant regardless of how many applications or endpoints they run.
 */
final readonly class ConsumeRateLimit
{
    public function __construct(private TokenBucket $bucket) {}

    public function handle(Tenant $tenant, CarbonImmutable $now): LimitDecision
    {
        $limit = new RateLimit(
            capacity: Config::integer("postbox.governor.rate_limit.{$tenant->plan->value}.capacity"),
            refillPerSecond: Config::integer("postbox.governor.rate_limit.{$tenant->plan->value}.refill_per_second"),
        );

        $consumed = $this->bucket->consume("governor:bucket:{$tenant->public_id}", $limit, $now);

        return new LimitDecision(
            allowed: $consumed['allowed'],
            limit: $limit->capacity,
            remaining: $consumed['remaining'],
            resetSeconds: self::toSeconds($consumed['resetMs']),
            retryAfterSeconds: $consumed['allowed'] ? null : self::toSeconds($consumed['retryMs']),
        );
    }

    private static function toSeconds(int $ms): int
    {
        return (int) ceil($ms / 1000);
    }
}
