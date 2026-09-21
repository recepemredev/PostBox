<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Ledger's own read permission: messages, deliveries and their attempts
 * were migrated in Step 3 with no HTTP surface at all, so nothing has ever
 * granted access to them. Split off from catalog.read rather than folded
 * into it, the same way Step 13 split endpoint_secret.manage and
 * endpoint.test off catalog.manage (2026_09_18_000001) — reading what
 * happened to a delivery is a different fact from reading an application's
 * configuration, even though both roles need it.
 *
 * Granted to both roles: D79 already draws this line for Recovery's own
 * actions — "a viewer can read what happened but cannot cause anything to
 * happen" — and delivery.replay (2026_09_14_000003) stays admin-only, so a
 * viewer can read the inspector this migration unlocks without being able
 * to act from it.
 *
 * A later migration rather than an edit to 2026_09_10_000002's own literal
 * list, for the reason every permission migration since has been: that one
 * is a record of what the catalog was at Step 2, and PermissionCatalogTest
 * is what keeps this one from drifting away from the PermissionCode enum.
 */
return new class extends Migration
{
    private const string CODE = 'ledger.read';

    public function up(): void
    {
        $permissionId = DB::table('permissions')->insertGetId(['code' => self::CODE]);

        $roleIds = DB::table('roles')->whereIn('slug', ['admin', 'viewer'])->pluck('id');

        $links = [];

        foreach ($roleIds as $roleId) {
            $links[] = ['role_id' => $roleId, 'permission_id' => $permissionId];
        }

        DB::table('permission_role')->insert($links);
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')->where('code', self::CODE)->value('id');

        DB::table('permission_role')->where('permission_id', $permissionId)->delete();
        DB::table('permissions')->where('id', $permissionId)->delete();
    }
};
