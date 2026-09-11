<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Ingest\PruneIdempotencyKeys;
use App\Actions\Tenancy\RunForEachTenant;
use Illuminate\Console\Command;

final class PruneIdempotencyKeysCommand extends Command
{
    protected $signature = 'idempotency:prune';

    protected $description = 'Drop idempotency reservations whose window has passed.';

    public function handle(RunForEachTenant $tenants, PruneIdempotencyKeys $prune): int
    {
        $dropped = $tenants->handle(fn (): int => $prune->handle());

        $this->info("idempotency: {$dropped} reservations dropped.");

        return self::SUCCESS;
    }
}
