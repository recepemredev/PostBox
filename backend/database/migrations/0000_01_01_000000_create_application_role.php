<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/*
 * Row Level Security is only a boundary if the connection that serves requests
 * cannot step over it. The role the Postgres image creates is a superuser, and a
 * superuser bypasses every policy — FORCE ROW LEVEL SECURITY does not stop one.
 * So the schema keeps its owner, and the application gets a second role that owns
 * nothing and holds neither SUPERUSER nor BYPASSRLS.
 *
 * This is a migration rather than an entry in the image's init directory because
 * that directory only runs when a data volume is first created. CI runs Postgres
 * as a service container with no volume, so a role created there would exist in
 * development and be missing in CI. A migration runs in both.
 *
 * It is numbered before the framework's own migrations so that the default
 * privileges below are in place before the first table exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        $role = $this->quoteIdentifier($this->applicationRole());
        $database = $this->quoteIdentifier($this->databaseName());

        $this->writeRole();

        DB::statement("GRANT CONNECT ON DATABASE {$database} TO {$role}");
        DB::statement("GRANT USAGE ON SCHEMA public TO {$role}");

        /*
         * What already exists — at this point only the migration repository, which
         * the health check reads to answer "is a migration pending?".
         */
        DB::statement("GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO {$role}");
        DB::statement("GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO {$role}");

        /*
         * And everything the migrations after this one create. Written once here
         * rather than as a GRANT repeated in every migration that adds a table —
         * a grant that is easy to forget is a grant that will be forgotten.
         */
        DB::statement("ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO {$role}");
        DB::statement("ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO {$role}");
    }

    public function down(): void
    {
        $role = $this->quoteIdentifier($this->applicationRole());
        $database = $this->quoteIdentifier($this->databaseName());

        DB::statement("ALTER DEFAULT PRIVILEGES IN SCHEMA public REVOKE ALL ON TABLES FROM {$role}");
        DB::statement("ALTER DEFAULT PRIVILEGES IN SCHEMA public REVOKE ALL ON SEQUENCES FROM {$role}");
        DB::statement("REVOKE ALL ON ALL TABLES IN SCHEMA public FROM {$role}");
        DB::statement("REVOKE ALL ON ALL SEQUENCES IN SCHEMA public FROM {$role}");
        DB::statement("REVOKE ALL ON SCHEMA public FROM {$role}");
        DB::statement("REVOKE ALL ON DATABASE {$database} FROM {$role}");

        /*
         * The role itself is a cluster object: in development the same role serves
         * both the application database and the test database. Dropping it while
         * rolling back one of them would break the other, so the rollback gives
         * back every privilege it granted and leaves the role in place.
         */
    }

    /**
     * A role is cluster-wide, so creating it is written to be safe to repeat: the
     * second database in the cluster runs this same migration.
     */
    private function writeRole(): void
    {
        $name = $this->applicationRole();
        $role = $this->quoteIdentifier($name);
        $password = $this->quoteLiteral($this->applicationPassword());

        $attributes = 'LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS NOREPLICATION';

        $exists = DB::selectOne('select 1 from pg_roles where rolname = ?', [$name]) !== null;

        DB::statement($exists
            ? "ALTER ROLE {$role} WITH {$attributes} PASSWORD {$password}"
            : "CREATE ROLE {$role} WITH {$attributes} PASSWORD {$password}");
    }

    /**
     * The role and its password come from the connection the application uses,
     * not from a second read of the environment. There is one place they are
     * configured and this migration is downstream of it.
     */
    private function applicationRole(): string
    {
        return Config::string('database.connections.pgsql.username');
    }

    private function applicationPassword(): string
    {
        $password = Config::string('database.connections.pgsql.password');

        if ($password === '') {
            throw new RuntimeException('DB_APP_PASSWORD is empty: the application role would be unable to log in.');
        }

        return $password;
    }

    private function databaseName(): string
    {
        return (string) DB::connection()->getDatabaseName();
    }

    private function quoteIdentifier(string $value): string
    {
        return '"'.str_replace('"', '""', $value).'"';
    }

    private function quoteLiteral(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }
};
