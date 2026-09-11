<?php

declare(strict_types=1);

use App\Support\Tenancy\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * How much of a calendar month a tenant has spent. One row per tenant per
 * period, created the moment a tenant's first message of that period is
 * accepted — there is no seeded row waiting for a period that has not started.
 *
 * The unique pair is what makes a concurrent increment safe: two requests
 * racing to be the first of a period both attempt an insert, and the loser
 * gets a constraint violation rather than a second row splitting the count.
 * used is checked non-negative for the same reason a partitioned table's
 * status columns carry a CHECK — the ceiling this table enforces should not
 * depend on every caller incrementing it correctly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quota_usage', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->date('period_start');
            $table->unsignedInteger('used')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'period_start']);
        });

        DB::statement(
            'ALTER TABLE quota_usage ADD CONSTRAINT quota_usage_used_check CHECK (used >= 0)'
        );

        RowLevelSecurity::protect('quota_usage');
    }

    public function down(): void
    {
        Schema::dropIfExists('quota_usage');
    }
};
