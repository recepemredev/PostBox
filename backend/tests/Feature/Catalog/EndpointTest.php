<?php

declare(strict_types=1);

use App\Enums\EndpointStatus;
use App\Models\Application;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * The status column is constrained in the database, and the constraint is written
 * as literals in the migration. This is the test that keeps those literals and
 * the EndpointStatus enum from drifting apart — the same trade the permission
 * catalog makes, for the same reason.
 */

beforeEach(function (): void {
    $this->tenant = tenantNamed('Acme');
    $this->application = forTenant($this->tenant, fn (): Application => Application::factory()->create());
});

it('constrains the status column to exactly the EndpointStatus enum', function (): void {
    $constraint = DB::selectOne(
        "select pg_get_constraintdef(oid) as definition
         from pg_constraint where conname = 'endpoints_status_check'"
    );

    preg_match_all("/'([^']+)'/", (string) $constraint->definition, $matches);

    $allowed = collect($matches[1])->sort()->values()->all();
    $declared = collect(EndpointStatus::cases())
        ->map(fn (EndpointStatus $case): string => $case->value)
        ->sort()
        ->values()
        ->all();

    expect($allowed)->toBe($declared);
});

it('refuses a status the enum does not name', function (): void {
    $insert = fn (): bool => DB::table('endpoints')->insert([
        'public_id' => 'ep_bad',
        'tenant_id' => $this->tenant->id,
        'application_id' => $this->application->id,
        'name' => 'broken',
        'url' => 'https://example.test/hook',
        'status' => 'paused',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => forTenant($this->tenant, $insert))->toThrow(QueryException::class);
});
