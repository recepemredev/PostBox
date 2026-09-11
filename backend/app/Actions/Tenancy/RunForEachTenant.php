<?php

declare(strict_types=1);

namespace App\Actions\Tenancy;

use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Closure;

/**
 * Runs the same piece of background work once per tenant.
 *
 * This is not a convenience wrapper around a loop — it is the only shape
 * cross-tenant work can take here. Row Level Security is declared FORCE and
 * every policy compares a row against the tenant that is current, so no role and
 * no connection can express "every row of this table, everywhere". Scheduled
 * work therefore establishes a tenant, does its pass, and moves on, through the
 * same context a request uses.
 *
 * The work reports a count and the counts are added up, because that is what
 * both callers have to say for themselves: how many rows their pass touched.
 */
final readonly class RunForEachTenant
{
    public function __construct(private TenantContext $context) {}

    /**
     * @param  Closure(Tenant): int  $work
     * @return int the sum of what the work reported across every tenant
     */
    public function handle(Closure $work): int
    {
        $total = 0;

        Tenant::query()->orderBy('id')->each(
            function (Tenant $tenant) use ($work, &$total): void {
                $total += $this->context->runFor($tenant, fn (): int => $work($tenant));
            },
        );

        return $total;
    }
}
