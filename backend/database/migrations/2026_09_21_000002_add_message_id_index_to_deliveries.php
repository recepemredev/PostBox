<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The message detail screen's own read: every delivery a message ever
 * opened, original and replayed alike. deliveries_original_fanout_unique
 * (2026_09_14_000002) only indexes the rows with no replay_id, so a message
 * that has ever been replayed has no index covering all of its deliveries —
 * every index on this table is justified by the query it serves
 * (conventions.md), and this is the query Step 14 adds.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deliveries', function (Blueprint $table): void {
            $table->index('message_id');
        });
    }

    public function down(): void
    {
        Schema::table('deliveries', function (Blueprint $table): void {
            $table->dropIndex(['message_id']);
        });
    }
};
