<?php

declare(strict_types=1);

use App\Support\Tenancy\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('memberships', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained();
            $table->timestamps();

            // A person holds one role in a tenant. Scoped to the tenant, as every
            // unique constraint on a tenant-owned table is.
            $table->unique(['tenant_id', 'user_id']);

            // The unique constraint above already indexes (tenant_id, user_id),
            // which serves every tenant-scoped lookup. This one serves the query
            // that runs before a tenant is current: "which tenants is this user
            // in?", asked once per dashboard request.
            $table->index('user_id');
        });

        RowLevelSecurity::protect('memberships');

        /*
         * The one read that has to happen before a tenant exists to compare
         * against. It is a SELECT policy on purpose: read your own rows, and only
         * your own. Without FOR SELECT, PostgreSQL would use this predicate as the
         * check on writes as well, and a user could insert themselves into a
         * tenant they were never invited to.
         */
        DB::statement(
            'CREATE POLICY membership_self_read ON memberships '.
            'FOR SELECT USING (user_id = '.RowLevelSecurity::USER.')'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('memberships');
    }
};
