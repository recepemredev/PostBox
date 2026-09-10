<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use Illuminate\Support\Facades\DB;

/**
 * The database half of the tenant boundary, used from migrations.
 *
 * The application scope and the write-time stamp can both be forgotten by a
 * developer. This cannot: the policy is evaluated by PostgreSQL for the role the
 * application connects as, whatever query reaches it. FORCE is what extends that
 * to the role owning the table, so a migration cannot quietly read across tenants
 * either.
 */
final class RowLevelSecurity
{
    /**
     * The current tenant, as the policies see it. nullif() is what makes an unset
     * variable and one cleared to the empty string behave identically — without
     * it, clearing the variable would turn every query into a cast error instead
     * of an empty result.
     */
    public const TENANT = "nullif(current_setting('postbox.tenant_id', true), '')::bigint";

    public const USER = "nullif(current_setting('postbox.user_id', true), '')::bigint";

    /**
     * Confines every row of a tenant-owned table to the tenant that is current.
     *
     * With no tenant current the predicate is NULL, which is not true, so the
     * table reads as empty and refuses every write. Denying by default is the
     * point: a code path that forgets to establish a tenant fails visibly rather
     * than seeing everything.
     */
    public static function protect(string $table): void
    {
        $name = self::quote($table);

        DB::statement("ALTER TABLE {$name} ENABLE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$name} FORCE ROW LEVEL SECURITY");
        DB::statement(
            "CREATE POLICY tenant_isolation ON {$name} ".
            'USING (tenant_id = '.self::TENANT.') '.
            'WITH CHECK (tenant_id = '.self::TENANT.')'
        );
    }

    public static function quote(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }
}
