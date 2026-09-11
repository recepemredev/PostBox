<?php

declare(strict_types=1);

use App\Support\Tenancy\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * A record of who changed what, kept because a change made through the
 * dashboard is otherwise invisible the moment it is overwritten. Insert-only is
 * enforced by a trigger rather than by convention: a BEFORE UPDATE OR DELETE
 * trigger fires for every role, including the schema owner, so this is a
 * stronger guarantee than Row Level Security alone would be — RLS is still
 * applied on top of it, the same as every other tenant-owned table.
 *
 * action and entity_type are free text rather than a closed enum: an audit
 * trail is written by features that do not exist yet (Step 8's breaker
 * transitions, Step 13's endpoint and secret management), and declaring their
 * vocabulary now would be inventing it ahead of a real use case. Both are held
 * to the same lowercase-dotted shape event_types.name uses, so the trail stays
 * consistent even as what it records grows. entity_id has no foreign key for a
 * plainer reason than elsewhere in this schema: it names a row in whichever
 * table entity_type says, so a single column could never reference just one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_log', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // Who made the change. Nullable for a system-initiated entry — a
            // circuit breaker opening on its own has no person behind it. Never
            // deleted with the user: an audit trail has to outlive the account.
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('action');
            $table->string('entity_type');
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('entity_public_id')->nullable();
            $table->jsonb('changes')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->timestamp('created_at');

            // An entity's own history, newest first — an endpoint's or a
            // secret's audit trail on its own detail page.
            $table->index(['entity_type', 'entity_id', 'created_at']);
        });

        DB::statement(
            'ALTER TABLE audit_log ADD CONSTRAINT audit_log_action_check '.
            "CHECK (action ~ '^[a-z0-9]+([._-][a-z0-9]+)*\$')"
        );

        DB::statement(
            'ALTER TABLE audit_log ADD CONSTRAINT audit_log_entity_type_check '.
            "CHECK (entity_type ~ '^[a-z0-9]+([._-][a-z0-9]+)*\$')"
        );

        // OR REPLACE on both: migrate:fresh drops every table between test runs
        // but, on PostgreSQL, does not drop functions — a plain CREATE would
        // fail the second time this migration ever runs in a given database.
        DB::statement(
            'CREATE OR REPLACE FUNCTION audit_log_reject_mutation() RETURNS trigger '.
            'LANGUAGE plpgsql AS $body$ '.
            'BEGIN '.
            "RAISE EXCEPTION 'audit_log is insert-only: % is not permitted', TG_OP; ".
            'END; '.
            '$body$'
        );

        DB::statement(
            'CREATE OR REPLACE TRIGGER audit_log_is_immutable '.
            'BEFORE UPDATE OR DELETE ON audit_log '.
            'FOR EACH ROW EXECUTE FUNCTION audit_log_reject_mutation()'
        );

        RowLevelSecurity::protect('audit_log');
    }

    public function down(): void
    {
        // Drop order matters: the function is an independent object the trigger
        // merely points at, so dropping the table alone would leave it behind.
        // CASCADE takes the trigger with it; the table itself is untouched by
        // that and is dropped in the statement right after.
        DB::statement('DROP FUNCTION IF EXISTS audit_log_reject_mutation() CASCADE');
        Schema::dropIfExists('audit_log');
    }
};
