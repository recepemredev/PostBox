<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Replay does not touch the original delivery — it opens a new one against
 * the same message and endpoint, which is what makes "does not mutate the
 * original" true by construction rather than by discipline, the same
 * argument D59 already made for the dead letter queue.
 *
 * That collides with Step 3's own unique constraint: (message_id, endpoint_id)
 * was unique across the whole table, because until now there was never a
 * second reason for the pair to repeat. It still has to hold for the fan-out
 * PublishMessage produces — one endpoint reaching for the same message twice
 * at publish time is exactly the duplicate-send bug that constraint exists to
 * catch — so it is narrowed to the rows that are nobody's replay rather than
 * dropped. A replay is free to share a pair with its original, and free to
 * be replayed again itself; only two originals for the same pair are refused.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deliveries', function (Blueprint $table): void {
            $table->foreignId('replay_id')->nullable()->after('endpoint_id')->constrained()->cascadeOnDelete();

            $table->dropUnique(['message_id', 'endpoint_id']);
        });

        DB::statement(
            'CREATE UNIQUE INDEX deliveries_original_fanout_unique ON deliveries (message_id, endpoint_id) '.
            'WHERE replay_id IS NULL'
        );

        // A replay's own deliveries, for ReplayResource and for the count a
        // replay's receipt reports.
        DB::statement(
            'CREATE INDEX deliveries_replay_id_index ON deliveries (replay_id) WHERE replay_id IS NOT NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS deliveries_replay_id_index');
        DB::statement('DROP INDEX IF EXISTS deliveries_original_fanout_unique');

        Schema::table('deliveries', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('replay_id');

            $table->unique(['message_id', 'endpoint_id']);
        });
    }
};
