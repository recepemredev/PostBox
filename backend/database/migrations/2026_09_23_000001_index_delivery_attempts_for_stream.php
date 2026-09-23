<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The live stream's own read (Step 15): a tenant's own delivery_attempts,
 * tailed by a keyset cursor on (created_at, public_id) — the same ordering
 * delivery_attempts_delivery_created_at_index already serves for one
 * delivery's own timeline, but here scoped to the whole tenant rather than
 * one delivery. Row Level Security puts tenant_id in front of every read
 * regardless, so the leading column is tenant_id, then the keyset's own
 * ordering, which is what lets the tail's WHERE clause (tenant scope,
 * watermark on created_at, cursor predicate on created_at/public_id) be
 * answered by a single index rather than a scan.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'CREATE INDEX delivery_attempts_tenant_created_at_index '.
            'ON delivery_attempts (tenant_id, created_at, public_id)'
        );
    }

    public function down(): void
    {
        DB::statement(
            'DROP INDEX IF EXISTS delivery_attempts_tenant_created_at_index'
        );
    }
};
