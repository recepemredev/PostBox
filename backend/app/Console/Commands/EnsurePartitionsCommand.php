<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Database\MonthlyPartitions;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;

/**
 * Keeps the partition horizon open ahead of the rows that will need it, and
 * drops partitions whose retention window has passed. Runs on a schedule, on
 * the scheduler's one instance — nothing here coordinates against a second
 * instance running concurrently, because nothing is meant to run one.
 */
final class EnsurePartitionsCommand extends Command
{
    protected $signature = 'partitions:ensure';

    protected $description = 'Open upcoming monthly partitions and prune ones past their retention window.';

    /** @var list<string> */
    private const TABLES = ['messages', 'delivery_attempts'];

    public function handle(): int
    {
        $now = CarbonImmutable::now();
        $monthsAhead = Config::integer('postbox.partitions.months_ahead');

        foreach (self::TABLES as $table) {
            MonthlyPartitions::ensure($table, $now, $monthsAhead);

            $retentionMonths = Config::integer("postbox.partitions.retention_months.{$table}");
            MonthlyPartitions::prune($table, $now->subMonths($retentionMonths));

            $this->info(sprintf(
                '%s: horizon open through +%d months, retained %d months back.',
                $table,
                $monthsAhead,
                $retentionMonths,
            ));
        }

        return self::SUCCESS;
    }
}
