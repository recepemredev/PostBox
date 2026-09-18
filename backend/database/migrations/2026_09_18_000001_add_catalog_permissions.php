<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Catalog's own permissions: viewing applications, endpoints, event types and
 * subscriptions is `catalog.read` (both roles), changing any of them is
 * `catalog.manage` (admin only) — the same read/manage split api_key.* already
 * draws. Two permissions split off that pair rather than folding into it,
 * because they gate something catalog.manage does not: an endpoint's signing
 * secret is a credential, and sending a test event produces real traffic a
 * viewer should not be able to cause.
 *
 * A later migration rather than an edit to 2026_09_10_000002's own literal
 * list, for the same reason 2026_09_14_000003 (delivery.replay) was: that
 * migration is a record of what the catalog was at Step 2, and
 * PermissionCatalogTest is what keeps this one from drifting away from the
 * PermissionCode enum it names.
 */
return new class extends Migration
{
    /** @var array<string, list<string>> */
    private const GRANTS = [
        'admin' => ['catalog.read', 'catalog.manage', 'endpoint_secret.manage', 'endpoint.test'],
        'viewer' => ['catalog.read'],
    ];

    public function up(): void
    {
        $roleIds = DB::table('roles')->pluck('id', 'slug')->all();

        $codes = array_values(array_unique(array_merge(...array_values(self::GRANTS))));

        DB::table('permissions')->insert(array_map(
            static fn (string $code): array => ['code' => $code],
            $codes,
        ));

        /** @var array<string, int> $permissionIds */
        $permissionIds = DB::table('permissions')->whereIn('code', $codes)->pluck('id', 'code')->all();

        $links = [];

        foreach (self::GRANTS as $slug => $granted) {
            foreach ($granted as $code) {
                $links[] = ['role_id' => $roleIds[$slug], 'permission_id' => $permissionIds[$code]];
            }
        }

        DB::table('permission_role')->insert($links);
    }

    public function down(): void
    {
        $codes = array_values(array_unique(array_merge(...array_values(self::GRANTS))));

        $permissionIds = DB::table('permissions')->whereIn('code', $codes)->pluck('id');

        DB::table('permission_role')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();
    }
};
