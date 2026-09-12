<?php

declare(strict_types=1);

use App\Support\Tenancy\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Whether an endpoint that keeps failing has been cut off from traffic. A row
 * exists only for an endpoint whose breaker has actually opened at least
 * once — the same lazy-row shape quota_usage takes, for the same reason: a
 * breaker that has never tripped has nothing to say beyond the closed default,
 * so there is nothing to seed ahead of a failure.
 *
 * endpoint_id carries no foreign key by convention alone: endpoints is not
 * partitioned, so a real FK would be possible here, but every other reference
 * this schema draws toward the delivery path is application-level (D34), and a
 * breaker row is transient state about an endpoint in exactly that sense — it
 * is deleted along with the endpoint it belongs to regardless, which the FK
 * below still enforces at the constraint level even though the reference is
 * not repeated elsewhere.
 *
 * The shape CHECK ties state to the two moments that matter: open has an
 * opened_at and no live probe; half-open has both, because a probe can only be
 * admitted from an open breaker whose opened_at survives into it; closed has
 * neither, because closing clears the whole history of the trip that caused it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('endpoint_circuit_breakers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('endpoint_id')->constrained()->cascadeOnDelete();
            $table->string('state')->default('closed');
            $table->timestamp('state_changed_at');
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('probe_started_at')->nullable();
            $table->timestamps();

            // One breaker per endpoint. The dispatcher's admission read and
            // RecordAttemptOutcome's write both key on this pair.
            $table->unique(['tenant_id', 'endpoint_id']);
        });

        // Written out rather than read from BreakerState, the same discipline
        // endpoints_status_check keeps: a migration records what the
        // constraint was at this point in time, and a test asserts the enum
        // has not since drifted from it.
        DB::statement(
            'ALTER TABLE endpoint_circuit_breakers ADD CONSTRAINT endpoint_circuit_breakers_state_check '.
            "CHECK (state IN ('closed', 'open', 'half_open'))"
        );

        DB::statement(
            'ALTER TABLE endpoint_circuit_breakers ADD CONSTRAINT endpoint_circuit_breakers_shape_check CHECK ('.
            "(state = 'closed' AND opened_at IS NULL AND probe_started_at IS NULL) OR ".
            "(state = 'open' AND opened_at IS NOT NULL AND probe_started_at IS NULL) OR ".
            "(state = 'half_open' AND opened_at IS NOT NULL AND probe_started_at IS NOT NULL))"
        );

        RowLevelSecurity::protect('endpoint_circuit_breakers');
    }

    public function down(): void
    {
        Schema::dropIfExists('endpoint_circuit_breakers');
    }
};
