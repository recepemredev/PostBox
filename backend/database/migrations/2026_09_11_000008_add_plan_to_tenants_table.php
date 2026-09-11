<?php

declare(strict_types=1);

use App\Enums\Plan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * What Governor enforces for a tenant. The values are written out rather than
 * read from Plan: a migration records what the constraint was at this point in
 * time, and a test asserts the enum has not since drifted from it — the same
 * trade every other enum-backed column in this schema makes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->string('plan', 20)->default(Plan::Free->value)->after('name');
        });

        DB::statement(
            'ALTER TABLE tenants ADD CONSTRAINT tenants_plan_check '.
            "CHECK (plan IN ('free', 'pro'))"
        );
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn('plan');
        });
    }
};
