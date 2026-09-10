<?php

declare(strict_types=1);

use App\Enums\PermissionCode;
use App\Enums\RoleSlug;
use App\Models\Permission;
use App\Models\Role;

/*
 * Permissions exist twice: as rows a role is composed from, and as enum cases the
 * policies name. That is a deliberate trade — typed authorization on one side,
 * data-driven roles on the other — and this is the test that pays for it.
 */

it('keeps the permission table and the permission enum identical', function (): void {
    $stored = Permission::query()->pluck('code')->sort()->values()->all();

    $declared = collect(PermissionCode::cases())
        ->map(fn (PermissionCode $case): string => $case->value)
        ->sort()
        ->values()
        ->all();

    expect($stored)->toBe($declared);
});

it('gives every role a name and at least one permission', function (): void {
    $roles = Role::query()->with('permissions')->get();

    $declared = collect(RoleSlug::cases())
        ->map(fn (RoleSlug $case): string => $case->value)
        ->sort()
        ->values()
        ->all();

    expect($roles->pluck('slug')->sort()->values()->all())->toBe($declared);

    $roles->each(function (Role $role): void {
        expect($role->name)->not->toBeEmpty()
            ->and($role->permissions)->not->toBeEmpty();
    });
});
