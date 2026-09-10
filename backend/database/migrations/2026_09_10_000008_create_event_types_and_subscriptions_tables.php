<?php

declare(strict_types=1);

use App\Support\Tenancy\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The events a tenant can publish, and the endpoints that have asked for each.
 *
 * An event type is named rather than given a ULID: the name is what a producer
 * puts in a request and what the SDK generates a type from, so it is the
 * identifier and it does not change once a consumer depends on it. It is
 * constrained to lowercase dotted segments for the same reason — a malformed name
 * would propagate into a signed payload and into generated code.
 *
 * A subscription is a plain join. It carries tenant_id like every tenant-owned
 * row so Row Level Security applies; the unique pair is (event_type, endpoint),
 * ordered so the same index serves the fan-out that walks from an event type to
 * its endpoints.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_types', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
        });

        // The name is a machine identifier, not a label: lowercase alphanumeric
        // segments joined by '.', '_' or '-', up to 100 characters.
        DB::statement(
            'ALTER TABLE event_types ADD CONSTRAINT event_types_name_check '.
            "CHECK (name ~ '^[a-z0-9]+([._-][a-z0-9]+)*\$' AND char_length(name) <= 100)"
        );

        RowLevelSecurity::protect('event_types');

        Schema::create('endpoint_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('endpoint_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_type_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            // One subscription per pair. Event type first, because the fan-out
            // reads this index from that side on every publish.
            $table->unique(['event_type_id', 'endpoint_id']);

            // The dashboard reads it from the other side: what is this endpoint
            // subscribed to?
            $table->index('endpoint_id');
        });

        RowLevelSecurity::protect('endpoint_subscriptions');
    }

    public function down(): void
    {
        Schema::dropIfExists('endpoint_subscriptions');
        Schema::dropIfExists('event_types');
    }
};
