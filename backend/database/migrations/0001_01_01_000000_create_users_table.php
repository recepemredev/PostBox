<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Only the users table. The framework's skeleton also creates a sessions table
 * and a password reset table; sessions live in Redis here, and there is no
 * password reset flow to store tokens for. A table the application can never read
 * is a question a reviewer has to ask and an answer the schema should not owe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 40)->unique();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
