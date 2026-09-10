<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Roles and permissions are shared by every tenant, so neither table is
 * tenant-owned and neither carries a policy. What is tenant-owned is the
 * membership that points at a role.
 *
 * The rows are written here rather than in a seeder. Without them nothing can be
 * authorized, so they are not sample data — they are as much a part of the schema
 * as the columns, and `migrate --force` on a fresh database has to produce a
 * working system without a second command anyone could forget.
 *
 * The codes are written out as literals instead of being read from the
 * PermissionCode enum. A migration is a record of what happened at a point in
 * time; one that iterates over today's enum would silently mean something
 * different next month.
 */
return new class extends Migration
{
    /**
     * Two permissions, because two are what the application checks today. A
     * permission with no call site is a promise the code has not made.
     *
     * @var array<string, list<string>>
     */
    private const ROLES = [
        'admin' => ['api_key.read', 'api_key.manage'],
        'viewer' => ['api_key.read'],
    ];

    /** @var array<string, string> */
    private const NAMES = [
        'admin' => 'Administrator',
        'viewer' => 'Viewer',
    ];

    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
        });

        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
        });

        Schema::create('permission_role', function (Blueprint $table): void {
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->primary(['permission_id', 'role_id']);
        });

        $this->writeRows();
    }

    public function down(): void
    {
        Schema::dropIfExists('permission_role');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('permissions');
    }

    private function writeRows(): void
    {
        $codes = array_values(array_unique(array_merge(...array_values(self::ROLES))));

        DB::table('permissions')->insert(array_map(
            static fn (string $code): array => ['code' => $code],
            $codes,
        ));

        DB::table('roles')->insert(array_map(
            static fn (string $slug): array => ['slug' => $slug, 'name' => self::NAMES[$slug]],
            array_keys(self::ROLES),
        ));

        /** @var array<string, int> $permissionIds */
        $permissionIds = DB::table('permissions')->pluck('id', 'code')->all();
        /** @var array<string, int> $roleIds */
        $roleIds = DB::table('roles')->pluck('id', 'slug')->all();

        $links = [];

        foreach (self::ROLES as $slug => $granted) {
            foreach ($granted as $code) {
                $links[] = ['role_id' => $roleIds[$slug], 'permission_id' => $permissionIds[$code]];
            }
        }

        DB::table('permission_role')->insert($links);
    }
};
