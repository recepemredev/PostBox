<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The operations screen's own read permission (Step 17): queue depth and
 * circuit breaker state, for an operator deciding whether the system is
 * healthy right now. Split off rather than folded into ledger.read the same
 * way that permission was itself split from catalog.read
 * (2026_09_21_000001) — reading what a delivery did is a different fact
 * from reading whether the delivery *path itself* is currently healthy,
 * even though both are read-only facts a viewer needs.
 *
 * Granted to both roles, the same line D79 already draws for Recovery and
 * 2026_09_21_000001 draws for the ledger: a viewer can read what is
 * happening without being able to make anything happen. Nothing this
 * permission gates can mutate a breaker or a queue — it is exactly as
 * inert as ledger.read's own inspector.
 */
return new class extends Migration
{
    private const string CODE = 'operations.read';

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
