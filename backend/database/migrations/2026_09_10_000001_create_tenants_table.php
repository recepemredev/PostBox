<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The one table in the boundary that is not behind it.
 *
 * A Row Level Security policy compares a row against the tenant that is current;
 * a tenant row is what "current" points at, and it has to be readable at the
 * moment a request is still deciding which tenant it is for. Nothing reaches this
 * table except through a membership or an API key, and no endpoint lists it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 40)->unique();
            $table->string('name');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
