<?php

declare(strict_types=1);

use App\Support\Tenancy\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * A destination an application delivers to. It carries the target URL and the
 * operator's enabled/disabled switch; the signing secrets, the event
 * subscriptions and the circuit breaker state each live in their own table so an
 * endpoint row stays a description of intent rather than a mix of intent and
 * runtime state.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('endpoints', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 40)->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('url');
            $table->string('status')->default('enabled');
            $table->timestamps();

            // The dashboard list this table has: an application's endpoints,
            // newest first. The fan-out join in Step 4 reaches an endpoint by its
            // key through a subscription, not through this index.
            $table->index(['application_id', 'created_at']);
        });

        // The status column is a closed set. The values are written out rather
        // than read from EndpointStatus: a migration records what the constraint
        // was at this point in time, and a test asserts the enum has not since
        // drifted from it.
        DB::statement(
            'ALTER TABLE endpoints ADD CONSTRAINT endpoints_status_check '.
            "CHECK (status IN ('enabled', 'disabled'))"
        );

        RowLevelSecurity::protect('endpoints');
    }

    public function down(): void
    {
        Schema::dropIfExists('endpoints');
    }
};
