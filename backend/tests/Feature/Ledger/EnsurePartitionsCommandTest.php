<?php

declare(strict_types=1);

use App\Support\Database\MonthlyPartitions;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
 * The command is a thin wrapper over MonthlyPartitions, run on a schedule. What
 * is worth testing here is that it reads the configured horizon and retention
 * rather than a value of its own, and applies both to every table it owns.
 */

it('opens the configured horizon and prunes past the configured retention', function (): void {
    config([
        'postbox.partitions.months_ahead' => 1,
        'postbox.partitions.retention_months.messages' => 1,
        'postbox.partitions.retention_months.delivery_attempts' => 1,
    ]);

    // Well past the one-month retention just configured, for both tables.
    MonthlyPartitions::createFor('messages', CarbonImmutable::now()->subMonths(3));
    MonthlyPartitions::createFor('delivery_attempts', CarbonImmutable::now()->subMonths(3));

    $this->artisan('partitions:ensure')->assertSuccessful();

    foreach (['messages', 'delivery_attempts'] as $table) {
        $nextMonth = $table.'_'.CarbonImmutable::now()->addMonth()->format('Y_m');
        $oldMonth = $table.'_'.CarbonImmutable::now()->subMonths(3)->format('Y_m');

        $names = DB::table('pg_class')->whereIn('relname', [$nextMonth, $oldMonth])->pluck('relname')->all();

        expect($names)->toBe([$nextMonth]);
    }
});
