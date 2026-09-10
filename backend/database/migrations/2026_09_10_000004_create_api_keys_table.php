<?php

declare(strict_types=1);

use App\Support\Tenancy\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_keys', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 40)->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');

            // Unique across the whole table rather than within the tenant: two
            // tenants holding the same credential is the one collision that must
            // be impossible, and a tenant-scoped constraint would permit it.
            $table->string('token_hash', 64)->unique();

            // The last four characters of the secret, so a person can tell two
            // keys apart in a list without the key ever being shown again.
            $table->char('last_four', 4);

            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();

            // Who issued it. Nullable because a key outlives the person: deleting
            // an employee's account must not take a producer's credential with it.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // Serves the only list this table has: a tenant's keys, newest first.
            $table->index(['tenant_id', 'created_at']);
        });

        RowLevelSecurity::protect('api_keys');
    }

    public function down(): void
    {
        Schema::dropIfExists('api_keys');
    }
};
