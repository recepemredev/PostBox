<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The marker D76 calls "the part to get right": without it a test event sent
 * from the dashboard is indistinguishable from a producer's own traffic in
 * the very log whose job is to be trustworthy. `messages` is range-partitioned
 * by month (2026_09_11_000001) — ALTER TABLE against the parent here reaches
 * every partition, current and future, in one statement; no partition-by-
 * partition loop is needed the way one would be for an index.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            "ALTER TABLE messages ADD COLUMN source varchar(32) NOT NULL DEFAULT 'api'"
        );

        DB::statement(
            'ALTER TABLE messages ADD CONSTRAINT messages_source_check '.
            "CHECK (source IN ('api', 'dashboard_test'))"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE messages DROP COLUMN source');
    }
};
