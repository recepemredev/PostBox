<?php

declare(strict_types=1);

use App\Support\Tenancy\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The HMAC signing keys for an endpoint. There is more than one row per endpoint
 * during rotation: the new key is created live, the previous one is given a short
 * expiry, and until it passes a request is signed with both so a consumer can
 * cut over on their own schedule.
 *
 * The key column holds ciphertext — the model encrypts on write and the plaintext
 * is returned exactly once, at creation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('endpoint_secrets', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 40)->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('endpoint_id')->constrained()->cascadeOnDelete();
            $table->text('secret');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            // Signing a delivery loads the endpoint's live secrets to build the
            // signature header. That lookup is by endpoint, and it runs on every
            // attempt.
            $table->index('endpoint_id');
        });

        RowLevelSecurity::protect('endpoint_secrets');
    }

    public function down(): void
    {
        Schema::dropIfExists('endpoint_secrets');
    }
};
