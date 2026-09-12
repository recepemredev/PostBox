<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The dead letter queue is not a second table. A delivery that has run out of
 * attempts is still the same obligation it always was — the same message, the
 * same endpoint, the same attempt history hanging off it — and `exhausted` was
 * already in the status enum and in deliveries_status_check from Step 3. A
 * dead_letters table would have been a copy of a row that already exists, kept
 * in step with it by hand.
 *
 * What was missing is only what the delivery path could not say before: when it
 * gave up, and what finally stopped it. Two columns, not a table.
 *
 * deliveries is not partitioned, so this is a plain ALTER, and
 * RowLevelSecurity::protect() already covers the table from its own migration —
 * a new column on a protected table inherits the policies.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deliveries', function (Blueprint $table): void {
            $table->timestamp('exhausted_at')->nullable()->after('last_attempted_at');
            $table->string('failure_reason')->nullable()->after('exhausted_at');
        });

        /*
         * "Exhausted" and "has an exhaustion timestamp" are the same fact, so
         * the database refuses to hold one without the other — the same shape
         * check delivery_attempts makes between an outcome and a response
         * status. failure_reason is deliberately outside it: a row can be
         * exhausted without a reason worth printing, but never without a time.
         */
        DB::statement(
            'ALTER TABLE deliveries ADD CONSTRAINT deliveries_exhausted_shape_check CHECK ('.
            "(status = 'exhausted' AND exhausted_at IS NOT NULL) OR ".
            "(status <> 'exhausted' AND exhausted_at IS NULL))"
        );

        /*
         * The dead letter listing's only read: exhausted deliveries, most
         * recently given up on first. Partial, so a table that is overwhelmingly
         * pending and succeeded rows keeps this to an index of the failures.
         */
        DB::statement(
            'CREATE INDEX deliveries_dead_lettered_index ON deliveries (exhausted_at DESC) '.
            "WHERE status = 'exhausted'"
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS deliveries_dead_lettered_index');
        DB::statement('ALTER TABLE deliveries DROP CONSTRAINT deliveries_exhausted_shape_check');

        Schema::table('deliveries', function (Blueprint $table): void {
            $table->dropColumn(['exhausted_at', 'failure_reason']);
        });
    }
};
