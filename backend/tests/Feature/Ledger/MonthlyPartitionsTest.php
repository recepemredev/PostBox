<?php

declare(strict_types=1);

use App\Support\Database\MonthlyPartitions;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
 * The one piece of infrastructure every high-volume table depends on: a
 * partition has to exist before a row can land in it, and there is no default
 * partition anywhere in this schema to catch a miss.
 */

it('creates a partition covering exactly the given month', function (): void {
    MonthlyPartitions::createFor('messages', CarbonImmutable::create(2031, 6, 15));

    $bound = DB::selectOne(
        "select pg_get_expr(c.relpartbound, c.oid) as bound
         from pg_class c where c.relname = 'messages_2031_06'"
    );

    expect($bound)->not->toBeNull()
        ->and($bound->bound)->toContain('2031-06-01')
        ->and($bound->bound)->toContain('2031-07-01');
});

it('does nothing the second time it is asked for a month it already made', function (): void {
    $month = CarbonImmutable::create(2031, 7, 1);

    MonthlyPartitions::createFor('messages', $month);

    expect(fn () => MonthlyPartitions::createFor('messages', $month))->not->toThrow(Throwable::class);
});

it('creates the given month and the number of months asked for ahead of it', function (): void {
    MonthlyPartitions::ensure('messages', CarbonImmutable::create(2032, 1, 20), 2);

    $found = DB::table('pg_class')
        ->whereIn('relname', ['messages_2032_01', 'messages_2032_02', 'messages_2032_03'])
        ->count();

    expect($found)->toBe(3);
});
