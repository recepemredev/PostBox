<?php

declare(strict_types=1);

use App\Support\Tenancy\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * One row per Idempotency-Key a tenant has spent. The unique pair is the entire
 * guarantee: a producer that replays a request with the same key hits this
 * constraint before a second message is ever written. request_hash exists so a
 * replay carrying the same key but a different body can be told apart from a
 * genuine retry — what to do with that difference is Step 4's decision, this
 * table only keeps the fact.
 *
 * message_id carries no foreign key, matching every other reference into the
 * outbox chain: messages is partitioned, and it stays an application-level fact
 * for the same reason it is one on deliveries and delivery_attempts.
 *
 * This table is not partitioned — it is small relative to messages, and pruning
 * expired reservations is a plain per-row delete rather than a monthly range
 * concern. Building that cleanup is left to Step 4, alongside the ingest action
 * that is the only thing that ever reads or writes a row here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->char('request_hash', 64);
            $table->unsignedBigInteger('message_id');
            $table->timestamp('created_at');
            $table->timestamp('expires_at');

            $table->unique(['tenant_id', 'key']);
        });

        DB::statement(
            'ALTER TABLE idempotency_keys ADD CONSTRAINT idempotency_keys_expiry_check '.
            'CHECK (expires_at > created_at)'
        );

        RowLevelSecurity::protect('idempotency_keys');
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
