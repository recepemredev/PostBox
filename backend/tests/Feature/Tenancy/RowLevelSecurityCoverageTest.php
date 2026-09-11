<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/*
 * The rule this test exists to enforce: a new tenant-owned table without
 * RowLevelSecurity::protect() in its migration is a defect, not an omission.
 * "Tenant-owned" is discovered the same way the database would answer it — a
 * tenant_id column — rather than trusted to a maintained list that could drift
 * from the schema. Partitions are excluded: a partition never carries Row
 * Level Security itself, only the parent it inherits policies from does.
 */

it('protects every table that carries a tenant_id column', function (): void {
    /** @var list<object{table_name: string}> $tables */
    $tables = DB::select(
        "select distinct c.table_name
         from information_schema.columns c
         join pg_class pc on pc.relname = c.table_name and pc.relnamespace = 'public'::regnamespace
         where c.table_schema = 'public'
           and c.column_name = 'tenant_id'
           and pc.relispartition = false
         order by c.table_name"
    );

    expect($tables)->not->toBeEmpty();

    foreach ($tables as $table) {
        $name = $table->table_name;

        $flags = DB::selectOne(
            'select relrowsecurity::text as row_security, relforcerowsecurity::text as forced '.
            "from pg_class where relname = ? and relnamespace = 'public'::regnamespace",
            [$name]
        );

        $hasPolicy = DB::table('pg_policies')
            ->where('tablename', $name)
            ->where('policyname', 'tenant_isolation')
            ->exists();

        expect($flags?->row_security)->toBe('true', "{$name} does not have Row Level Security enabled.")
            ->and($flags?->forced)->toBe('true', "{$name} does not FORCE Row Level Security.")
            ->and($hasPolicy)->toBeTrue("{$name} has no tenant_isolation policy.");
    }
});
