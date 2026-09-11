<?php

declare(strict_types=1);

namespace App\Support\Database;

use App\Support\Tenancy\RowLevelSecurity;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Creates the monthly range partitions a high-volume table needs, ahead of the
 * rows that will land in them. There is no default partition anywhere in this
 * schema: a month with no partition refuses every write instead of quietly
 * collecting rows nobody planned for.
 *
 * Creating a partition is DDL — CREATE TABLE — so it always runs on the schema
 * owner's connection, never the application's, regardless of who is calling: a
 * migration is already on that connection, but the scheduled command that keeps
 * the horizon topped up is not, and it still needs to create tables.
 */
final class MonthlyPartitions
{
    /**
     * Ensures a partition exists for the month $from falls in, and for each of
     * the $monthsAhead months that follow it.
     */
    public static function ensure(string $table, CarbonInterface $from, int $monthsAhead): void
    {
        for ($offset = 0; $offset <= $monthsAhead; $offset++) {
            self::createFor($table, $from->copy()->addMonthsNoOverflow($offset));
        }
    }

    /**
     * One partition, spanning the calendar month $month falls in. Idempotent —
     * a partition already covering that month is left exactly as it is.
     */
    public static function createFor(string $table, CarbonInterface $month): void
    {
        $start = $month->copy()->startOfMonth();
        $end = $start->copy()->addMonthNoOverflow();

        DB::connection('pgsql_admin')->statement(
            'CREATE TABLE IF NOT EXISTS '.RowLevelSecurity::quote(self::partitionName($table, $start)).' '.
            'PARTITION OF '.RowLevelSecurity::quote($table).' '.
            'FOR VALUES FROM ('.self::quoteBound($start).') TO ('.self::quoteBound($end).')'
        );
    }

    public static function partitionName(string $table, CarbonInterface $month): string
    {
        return sprintf('%s_%s', $table, $month->format('Y_m'));
    }

    private static function quoteBound(CarbonInterface $point): string
    {
        return "'".$point->toDateTimeString()."'::timestamp";
    }
}
