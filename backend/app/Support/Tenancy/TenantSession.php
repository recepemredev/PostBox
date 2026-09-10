<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use Illuminate\Database\DatabaseManager;

/**
 * The two PostgreSQL session variables the Row Level Security policies read.
 *
 * The application layer keeps its own idea of who is asking; this class keeps the
 * database's idea of it in step. Both variables are set at session level rather
 * than transaction level, because a request issues queries outside a transaction
 * as often as inside one — so whatever clears them has to be explicit, and that
 * is what the null argument is for.
 */
final readonly class TenantSession
{
    private const TENANT = 'postbox.tenant_id';

    private const USER = 'postbox.user_id';

    public function __construct(private DatabaseManager $database) {}

    public function bindTenant(?int $tenantId): void
    {
        $this->bind(self::TENANT, $tenantId);
    }

    /**
     * Bound after authentication and before a tenant is chosen: it is what lets a
     * user read their own membership rows, and nothing else, while no tenant is
     * current yet.
     */
    public function bindUser(?int $userId): void
    {
        $this->bind(self::USER, $userId);
    }

    private function bind(string $setting, ?int $value): void
    {
        /*
         * An unset variable and one reset to the empty string have to behave the
         * same way, so the policies read them through nullif(). Clearing it here
         * writes the empty string because set_config has no null.
         */
        $this->database->connection()->statement(
            'select set_config(?, ?, false)',
            [$setting, $value === null ? '' : (string) $value],
        );
    }
}
