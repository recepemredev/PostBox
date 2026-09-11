<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Ingest\DispatchOutbox;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Runs the outbox dispatcher across every tenant.
 *
 * The loop is not an implementation detail to be optimised away later. Row Level
 * Security is declared FORCE and every policy compares a row against the tenant
 * that is current, so there is no connection and no role on which "every pending
 * delivery, everywhere" is a query that can be written. Background work that
 * spans tenants therefore spans them one at a time, through the same context a
 * request establishes.
 */
final class DispatchOutboxCommand extends Command
{
    protected $signature = 'outbox:dispatch';

    protected $description = 'Claim due deliveries from the outbox and hand them to the queue.';

    public function handle(TenantContext $context, DispatchOutbox $dispatch): int
    {
        $dispatched = 0;

        Tenant::query()->orderBy('id')->each(
            function (Tenant $tenant) use ($context, $dispatch, &$dispatched): void {
                $dispatched += $context->runFor($tenant, fn (): int => $dispatch->handle());
            },
        );

        $this->info("outbox: {$dispatched} deliveries dispatched.");

        return self::SUCCESS;
    }
}
