<?php

declare(strict_types=1);

use App\Enums\Plan;
use App\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * The plan column is constrained in the database, and the constraint is written
 * as literals in the migration. This is the test that keeps those literals and
 * the Plan enum from drifting apart — the same trade every other enum-backed
 * column in this schema makes.
 */

it('constrains the plan column to exactly the Plan enum', function (): void {
    $constraint = DB::selectOne(
        "select pg_get_constraintdef(oid) as definition
         from pg_constraint where conname = 'tenants_plan_check'"
    );

    preg_match_all("/'([^']+)'/", (string) $constraint->definition, $matches);

    $allowed = collect($matches[1])->sort()->values()->all();
    $declared = collect(Plan::cases())
        ->map(fn (Plan $case): string => $case->value)
        ->sort()
        ->values()
        ->all();

    expect($allowed)->toBe($declared);
});

it('refuses a plan the enum does not name', function (): void {
    $insert = fn (): bool => DB::table('tenants')->insert([
        'public_id' => 'ten_bad',
        'name' => 'Broken',
        'plan' => 'enterprise',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect($insert)->toThrow(QueryException::class);
});

it('defaults a new tenant to the free plan', function (): void {
    $tenant = tenantNamed('Acme');

    expect($tenant->plan)->toBe(Plan::Free);
});

it('lets a tenant be provisioned on the pro plan', function (): void {
    $tenant = Tenant::factory()->pro()->create(['name' => 'Globex']);

    expect($tenant->plan)->toBe(Plan::Pro);
});
