<?php

declare(strict_types=1);

use App\Support\Database\MonthlyPartitions;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
 * The one piece of infrastructure every high-volume table depends on: a
 * partition has to exist before a row can land in it, and there is no default
 * partition anywhere in this schema to catch a miss.
 *
 * These run against a partitioned table of their own rather than against
 * messages, and that is a correctness requirement rather than tidiness.
 * MonthlyPartitions works on the pgsql_admin connection, which RefreshDatabase
 * does not wrap in a transaction, so every CREATE and DROP here is permanent
 * for the rest of the suite. Pruning past a cutoff is the behaviour under test,
 * and doing that to messages would take the current month's partition with it —
 * leaving every later test that publishes an event with nowhere to put it.
 */

const PARTITION_PROBE = 'partition_probe';

beforeEach(function (): void {
    DB::connection('pgsql_admin')->statement(
        'CREATE TABLE IF NOT EXISTS '.PARTITION_PROBE.' (id bigint, created_at timestamp NOT NULL) '.
        'PARTITION BY RANGE (created_at)'
    );
});

afterEach(function (): void {
    DB::connection('pgsql_admin')->statement('DROP TABLE IF EXISTS '.PARTITION_PROBE.' CASCADE');
});

it('creates a partition covering exactly the given month', function (): void {
    MonthlyPartitions::createFor(PARTITION_PROBE, CarbonImmutable::create(2031, 6, 15));

    $bound = DB::selectOne(
        'select pg_get_expr(c.relpartbound, c.oid) as bound
         from pg_class c where c.relname = ?',
        [PARTITION_PROBE.'_2031_06']
    );

    expect($bound)->not->toBeNull()
        ->and($bound->bound)->toContain('2031-06-01')
        ->and($bound->bound)->toContain('2031-07-01');
});

it('does nothing the second time it is asked for a month it already made', function (): void {
    $month = CarbonImmutable::create(2031, 7, 1);

    MonthlyPartitions::createFor(PARTITION_PROBE, $month);

    expect(fn () => MonthlyPartitions::createFor(PARTITION_PROBE, $month))->not->toThrow(Throwable::class);
});

it('creates the given month and the number of months asked for ahead of it', function (): void {
    MonthlyPartitions::ensure(PARTITION_PROBE, CarbonImmutable::create(2032, 1, 20), 2);

    $found = DB::table('pg_class')
        ->whereIn('relname', [
            PARTITION_PROBE.'_2032_01',
            PARTITION_PROBE.'_2032_02',
            PARTITION_PROBE.'_2032_03',
        ])
        ->count();

    expect($found)->toBe(3);
});

it('drops a partition older than the cutoff and leaves the rest alone', function (): void {
    MonthlyPartitions::createFor(PARTITION_PROBE, CarbonImmutable::create(2033, 1, 1));
    MonthlyPartitions::createFor(PARTITION_PROBE, CarbonImmutable::create(2033, 6, 1));

    MonthlyPartitions::prune(PARTITION_PROBE, CarbonImmutable::create(2033, 4, 1));

    $remaining = DB::table('pg_class')
        ->whereIn('relname', [PARTITION_PROBE.'_2033_01', PARTITION_PROBE.'_2033_06'])
        ->pluck('relname')
        ->all();

    expect($remaining)->toBe([PARTITION_PROBE.'_2033_06']);
});

it('leaves a partition exactly at the cutoff month alone', function (): void {
    MonthlyPartitions::createFor(PARTITION_PROBE, CarbonImmutable::create(2034, 3, 1));

    MonthlyPartitions::prune(PARTITION_PROBE, CarbonImmutable::create(2034, 3, 15));

    $exists = DB::table('pg_class')->where('relname', PARTITION_PROBE.'_2034_03')->exists();

    expect($exists)->toBeTrue();
});
