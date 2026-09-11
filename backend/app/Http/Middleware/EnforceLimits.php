<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Actions\Governor\ConsumeQuota;
use App\Actions\Governor\ConsumeRateLimit;
use App\Exceptions\QuotaExhausted;
use App\Exceptions\RateLimitExceeded;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Governor's seat on the ingest surface, ahead of everything Ingest does: a
 * token bucket and a period quota, checked in that order because the bucket is
 * the cheaper of the two to ask, and neither mechanism aware the other exists.
 *
 * One moment is read here and handed to both, the same discipline
 * PublishMessage keeps for a message's own created_at: a request is charged
 * against quota for the same instant its rate limit was judged against, rather
 * than two clock reads that could disagree.
 */
final readonly class EnforceLimits
{
    public function __construct(
        private TenantContext $context,
        private ConsumeRateLimit $rateLimit,
        private ConsumeQuota $quota,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $this->context->currentOrFail();
        $now = CarbonImmutable::now();

        $rate = $this->rateLimit->handle($tenant, $now);

        if (! $rate->allowed) {
            throw new RateLimitExceeded($rate);
        }

        $quota = $this->quota->handle($tenant, $now);

        if (! $quota->allowed) {
            throw new QuotaExhausted($rate, $quota);
        }

        $response = $next($request);

        foreach ([...$rate->headers('RateLimit'), ...$quota->headers('Quota')] as $name => $value) {
            $response->headers->set($name, $value);
        }

        return $response;
    }
}
