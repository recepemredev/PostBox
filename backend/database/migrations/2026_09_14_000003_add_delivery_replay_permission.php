<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Recovery's own permission: an operator, not a system, is doing this, and
 * unlike the ingest surface (D37 — "no policy on a surface that has no
 * actor") a replay has an actor to ask about. Granted to admin only, the same
 * restriction api_key.manage already carries — a viewer reads, an admin
 * changes something.
 *
 * A later migration rather than an edit to 2026_09_10_000002's own literal
 * list: that migration is a record of what the permission catalog was at
 * Step 2, and PermissionCatalogTest is what keeps this one from drifting away
 * from the PermissionCode enum it names.
 */
return new class extends Migration
{
    public function up(): void
    {
        $id = DB::table('permissions')->insertGetId(['code' => 'delivery.replay']);

        $admin = DB::table('roles')->where('slug', 'admin')->value('id');

        DB::table('permission_role')->insert(['permission_id' => $id, 'role_id' => $admin]);
    }

    public function down(): void
    {
        $id = DB::table('permissions')->where('code', 'delivery.replay')->value('id');

        DB::table('permission_role')->where('permission_id', $id)->delete();
        DB::table('permissions')->where('id', $id)->delete();
    }
};
