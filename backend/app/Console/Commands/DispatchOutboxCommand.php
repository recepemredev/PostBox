<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Ingest\DispatchOutbox;
use App\Actions\Tenancy\RunForEachTenant;
use Illuminate\Console\Command;

/**
 * Runs the outbox dispatcher across every tenant. Why that is a loop rather than
 * one query is written down where the loop lives.
 */
final class DispatchOutboxCommand extends Command
{
    protected $signature = 'outbox:dispatch';

    protected $description = 'Claim due deliveries from the outbox and hand them to the queue.';

    public function handle(RunForEachTenant $tenants, DispatchOutbox $dispatch): int
    {
        $dispatched = $tenants->handle(fn (): int => $dispatch->handle());

        $this->info("outbox: {$dispatched} deliveries dispatched.");

        return self::SUCCESS;
    }
}
