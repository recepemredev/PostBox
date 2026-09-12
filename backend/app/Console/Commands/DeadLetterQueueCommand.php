<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Tenancy\RunForEachTenant;
use App\Models\Delivery;
use App\Models\Tenant;
use Illuminate\Console\Command;

/**
 * What is in the dead letter queue, for an operator with a shell and no
 * dashboard yet. It reads and prints; nothing here replays, requeues or deletes
 * — a message stays dead-lettered until Step 9 gives an operator a way to send
 * it again deliberately.
 *
 * There is no Action behind this. An Action is where business logic lives, and
 * listing a scope has none: wrapping one query in a class so that the command
 * could call it would be the speculative layer CLAUDE.md forbids until a second
 * caller actually exists.
 */
final class DeadLetterQueueCommand extends Command
{
    protected $signature = 'postbox:dlq {--limit=50 : How many to show per tenant}';

    protected $description = 'List the deliveries that ran out of attempts.';

    public function handle(RunForEachTenant $tenants): int
    {
        $limit = max(1, (int) $this->option('limit'));

        $listed = $tenants->handle(function (Tenant $tenant) use ($limit): int {
            $rows = $this->rowsFor($limit);

            if ($rows === []) {
                return 0;
            }

            $this->line($tenant->name.' ('.$tenant->public_id.')');
            $this->table(['Delivery', 'Endpoint', 'Attempts', 'Exhausted at', 'Reason'], $rows);

            return count($rows);
        });

        $this->info("dlq: {$listed} dead-lettered deliveries listed.");

        return self::SUCCESS;
    }

    /**
     * The newest failures first — the same order the partial index on
     * (exhausted_at) is built in, and the order an operator asking "what just
     * broke" wants them in.
     *
     * @return list<array{string, string, int, string, string}>
     */
    private function rowsFor(int $limit): array
    {
        $deliveries = Delivery::query()
            ->deadLettered()
            ->with('endpoint')
            ->orderByDesc('exhausted_at')
            ->limit($limit)
            ->get();

        return array_values($deliveries->map(fn (Delivery $delivery): array => [
            $delivery->public_id,
            $delivery->endpoint->public_id,
            $delivery->attempt_count,
            $delivery->exhausted_at?->toIso8601String() ?? '',
            $delivery->failure_reason ?? '',
        ])->all());
    }
}
