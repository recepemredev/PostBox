<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase {
        migrateFreshUsing as frameworkMigrateFreshUsing;
    }

    /**
     * The schema is built by the owning role, on the connection reserved for
     * migrations. The test itself then queries through the default connection —
     * the application role, the one Row Level Security applies to. Keeping the
     * two apart is what makes an isolation test mean anything: if the suite ran
     * its queries as the owner, every policy in the database would be inert and
     * the tests would still pass.
     *
     * @return array<string, mixed>
     */
    protected function migrateFreshUsing(): array
    {
        return array_merge($this->frameworkMigrateFreshUsing(), [
            '--database' => 'pgsql_admin',
        ]);
    }
}
