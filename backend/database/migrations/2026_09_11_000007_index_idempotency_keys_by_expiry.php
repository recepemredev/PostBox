<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The scheduled pruner's only read: this tenant's reservations whose window has
 * passed. The table carries one row per idempotent publish for the length of the
 * key's lifetime, so at any real publish rate it is not small, and without this
 * index every pass would scan all of a tenant's rows to find the few it deletes.
 *
 * tenant_id leads because Row Level Security puts it in front of every predicate
 * whether the query asked for it or not. It could not be built in the migration
 * that created the table: nothing read the column until the pruner existed, and
 * an index without a query to justify it is a write cost with no reader.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('idempotency_keys', function (Blueprint $table): void {
            $table->index(['tenant_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::table('idempotency_keys', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'expires_at']);
        });
    }
};
