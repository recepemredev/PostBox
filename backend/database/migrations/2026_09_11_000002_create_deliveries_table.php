<?php

declare(strict_types=1);

use App\Support\Tenancy\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The outbox's other half: one row per endpoint a message fans out to. A message
 * is written once; a delivery is what tracks a single endpoint's own progress
 * against it, which is why the pair is unique — the fan-out that creates these
 * rows must never create two for the same message and endpoint, on pain of a
 * duplicate send.
 *
 * message_id carries no foreign key. messages is partitioned, and a partitioned
 * table has no plain single-column unique key for a foreign key to target — only
 * (id, created_at) is unique, and carrying created_at here just to satisfy a
 * constraint was rejected in favour of an application-level reference, the same
 * choice delivery_attempts makes against this table's own id.
 *
 * This table is not itself partitioned: unlike messages and delivery_attempts, it
 * is a working queue that rows leave once delivered, not an ever-growing log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deliveries', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 40)->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('message_id');
            $table->foreignId('endpoint_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('pending');
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamps();

            // One delivery per message and endpoint. Also the index "this
            // message's deliveries" reads from — the dashboard's message detail.
            $table->unique(['message_id', 'endpoint_id']);

            // An endpoint's delivery history, newest first — read by the
            // dashboard and by the circuit breaker's rolling failure window.
            $table->index(['endpoint_id', 'created_at']);
        });

        DB::statement(
            'ALTER TABLE deliveries ADD CONSTRAINT deliveries_status_check '.
            "CHECK (status IN ('pending', 'succeeded', 'exhausted'))"
        );

        // The dispatcher's only read: pending deliveries due now, oldest first.
        // Partial, so a table that is mostly settled deliveries stays a small
        // index of the ones still in flight.
        DB::statement(
            'CREATE INDEX deliveries_pending_due_index ON deliveries (next_attempt_at) '.
            "WHERE status = 'pending'"
        );

        RowLevelSecurity::protect('deliveries');
    }

    public function down(): void
    {
        Schema::dropIfExists('deliveries');
    }
};
