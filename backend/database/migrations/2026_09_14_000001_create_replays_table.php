<?php

declare(strict_types=1);

use App\Support\Tenancy\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * One row per replay an operator has requested — the receipt, not the work
 * itself. The work is the ordinary delivery rows it opens (Step 9's next
 * migration); this table only ever answers "what was asked for, and when".
 *
 * idempotency_key is optional, the same choice D39 made for a producer's own
 * Idempotency-Key: an operator clicking once is not made to invent one, but a
 * script retrying a recovery call gets the same replay back rather than a
 * second one. Unique per tenant, matching idempotency_keys' own shape.
 *
 * request_hash is what tells a genuine retry from a key reused for a
 * different request — the same problem PublishMessage's own reservation
 * solves, and the same fingerprint mechanism (D77). Unlike idempotency_keys,
 * there is no separate reservation table: replays is not partitioned, so the
 * row that is the receipt can be the reservation as well, and the two never
 * need to be two tables kept in step.
 *
 * Exactly one of two shapes is legal, enforced at the database rather than
 * trusted to the action that writes it: a single message (optionally narrowed
 * to one endpoint among its original subscribers), or an endpoint's own
 * deliveries over a bounded range. The two never mix — a range without a
 * message names an endpoint and a window; a message without a range names
 * the message and, optionally, the endpoint.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('replays', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 40)->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('idempotency_key')->nullable();
            $table->char('request_hash', 64)->nullable();
            $table->unsignedBigInteger('message_id')->nullable();
            $table->foreignId('endpoint_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamp('range_from')->nullable();
            $table->timestamp('range_to')->nullable();
            $table->unsignedInteger('delivery_count')->default(0);
            $table->timestamp('created_at');

            $table->unique(['tenant_id', 'idempotency_key']);
        });

        DB::statement(
            'ALTER TABLE replays ADD CONSTRAINT replays_scope_shape_check CHECK ('.
            '(message_id IS NOT NULL AND range_from IS NULL AND range_to IS NULL) OR '.
            '(message_id IS NULL AND endpoint_id IS NOT NULL AND range_from IS NOT NULL AND range_to IS NOT NULL)'.
            ')'
        );

        DB::statement(
            'ALTER TABLE replays ADD CONSTRAINT replays_range_order_check '.
            'CHECK (range_to IS NULL OR range_to > range_from)'
        );

        // A hash exists exactly when a key does — the same discipline
        // deliveries_exhausted_shape_check holds status and exhausted_at to.
        DB::statement(
            'ALTER TABLE replays ADD CONSTRAINT replays_request_hash_shape_check CHECK ('.
            '(idempotency_key IS NULL AND request_hash IS NULL) OR '.
            '(idempotency_key IS NOT NULL AND request_hash IS NOT NULL)'.
            ')'
        );

        RowLevelSecurity::protect('replays');
    }

    public function down(): void
    {
        Schema::dropIfExists('replays');
    }
};
