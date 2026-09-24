<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Tenancy\RunForEachTenant;
use App\Models\EndpointCircuitBreaker;
use App\Models\Tenant;
use Illuminate\Console\Command;

/**
 * Which endpoints currently have a tripped breaker, for an operator with a
 * shell and no dashboard yet — this is Step 8's answer to what
 * DeadLetterQueueCommand already is for exhausted deliveries. It reads and
 * prints; nothing here opens, closes or probes anything.
 *
 * A breaker that closed again still has a row — closing does not delete it,
 * the same way exhaustion does not move a delivery — but a closed row has
 * nothing left for an operator to act on, so this only lists open and
 * half-open ones.
 *
 * There is no Action behind this, for the same reason DeadLetterQueueCommand
 * has none: listing a scope has no business logic to wrap.
 */
final class BreakerCommand extends Command
{
    protected $signature = 'postbox:breakers {--limit=50 : How many to show per tenant}';

    protected $description = 'List the endpoints whose circuit breaker is currently open or half-open.';

    public function handle(RunForEachTenant $tenants): int
    {
        $limit = max(1, (int) $this->option('limit'));

        $listed = $tenants->handle(function (Tenant $tenant) use ($limit): int {
            $rows = $this->rowsFor($limit);

            if ($rows === []) {
                return 0;
            }

            $this->line($tenant->name.' ('.$tenant->public_id.')');
            $this->table(['Endpoint', 'State', 'Opened at', 'Probe started at'], $rows);

            return count($rows);
        });

        $this->info("breakers: {$listed} tripped breakers listed.");

        return self::SUCCESS;
    }

    /**
     * The most recently changed first — an operator asking "what just
     * tripped" wants that one at the top.
     *
     * @return list<array{string, string, string, string}>
     */
    private function rowsFor(int $limit): array
    {
        $breakers = EndpointCircuitBreaker::query()
            ->tripped()
            ->with('endpoint')
            ->limit($limit)
            ->get();

        return array_values($breakers->map(fn (EndpointCircuitBreaker $breaker): array => [
            $breaker->endpoint->public_id,
            $breaker->state->value,
            $breaker->opened_at?->toIso8601String() ?? '',
            $breaker->probe_started_at?->toIso8601String() ?? '',
        ])->all());
    }
}
