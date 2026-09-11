<?php

declare(strict_types=1);

namespace App\Support\Database;

use App\Support\Tenancy\RowLevelSecurity;
use Carbon\CarbonImmutable;
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

    /**
     * Drops every partition of $table whose month is strictly before $before.
     * The current month is never a candidate unless $before is itself pushed
     * into the future, which is a misconfiguration this does not guard
     * against — the retention figure is a deployment constant, not input.
     */
    public static function prune(string $table, CarbonInterface $before): void
    {
        $cutoff = $before->copy()->startOfMonth();

        foreach (self::partitionsOf($table) as $name => $month) {
            if ($month->lt($cutoff)) {
                DB::connection('pgsql_admin')->statement('DROP TABLE IF EXISTS '.RowLevelSecurity::quote($name));
            }
        }
    }

    public static function partitionName(string $table, CarbonInterface $month): string
    {
        return sprintf('%s_%s', $table, $month->format('Y_m'));
    }

    /**
     * The partitions $table actually has right now, keyed by name, each
     * resolved back to the month it covers.
     *
     * @return array<string, CarbonImmutable>
     */
    private static function partitionsOf(string $table): array
    {
        /** @var list<object{name: string}> $rows */
        $rows = DB::connection('pgsql_admin')->select(
            'select child.relname as name '.
            'from pg_inherits '.
            'join pg_class parent on pg_inherits.inhparent = parent.oid '.
            'join pg_class child on pg_inherits.inhrelid = child.oid '.
            'where parent.relname = ?',
            [$table]
        );

        /** @var array<string, CarbonImmutable> $months */
        $months = [];

        foreach ($rows as $row) {
            $month = self::monthFromPartitionName($table, $row->name);

            if ($month instanceof CarbonImmutable) {
                $months[$row->name] = $month;
            }
        }

        return $months;
    }

    private static function monthFromPartitionName(string $table, string $partitionName): ?CarbonImmutable
    {
        $prefix = $table.'_';

        if (! str_starts_with($partitionName, $prefix)) {
            return null;
        }

        $parsed = CarbonImmutable::createFromFormat('Y_m', substr($partitionName, strlen($prefix)));

        return $parsed instanceof CarbonImmutable ? $parsed->startOfMonth() : null;
    }

    private static function quoteBound(CarbonInterface $point): string
    {
        return "'".$point->toDateTimeString()."'::timestamp";
    }
}
