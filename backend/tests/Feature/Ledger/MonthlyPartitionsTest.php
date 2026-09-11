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

it('drops a partition older than the cutoff and leaves the rest alone', function (): void {
    MonthlyPartitions::createFor('messages', CarbonImmutable::create(2033, 1, 1));
    MonthlyPartitions::createFor('messages', CarbonImmutable::create(2033, 6, 1));

    MonthlyPartitions::prune('messages', CarbonImmutable::create(2033, 4, 1));

    $remaining = DB::table('pg_class')
        ->whereIn('relname', ['messages_2033_01', 'messages_2033_06'])
        ->pluck('relname')
        ->all();

    expect($remaining)->toBe(['messages_2033_06']);
});

it('leaves a partition exactly at the cutoff month alone', function (): void {
    MonthlyPartitions::createFor('messages', CarbonImmutable::create(2034, 3, 1));

    MonthlyPartitions::prune('messages', CarbonImmutable::create(2034, 3, 15));

    $exists = DB::table('pg_class')->where('relname', 'messages_2034_03')->exists();

    expect($exists)->toBeTrue();
});
