<?php

declare(strict_types=1);

use App\Support\Tenancy\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The unit a tenant organises their integrations by. A producer publishes into an
 * application; endpoints, secrets and messages all hang beneath one. It carries no
 * delivery behaviour itself — it is a grouping, and the parent in every route
 * under /v1/apps/{app}.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('applications', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 40)->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();

            // An operator refers to an application by name in the dashboard and in
            // a support conversation; two with the same name in one tenant is a
            // mistake rather than a use case. Scoped to the tenant, as every
            // unique constraint on a tenant-owned table is.
            $table->unique(['tenant_id', 'name']);

            // Serves the only list this table has: a tenant's applications, newest
            // first. The unique above indexes (tenant_id, name), which cannot
            // answer the ordering.
            $table->index(['tenant_id', 'created_at']);
        });

        RowLevelSecurity::protect('applications');
    }

    public function down(): void
    {
        Schema::dropIfExists('applications');
    }
};
