<?php

declare(strict_types=1);

use App\Enums\PermissionCode;
use App\Enums\RoleSlug;
use App\Support\Identity\Permissions;
use Illuminate\Support\Facades\DB;

/*
 * What a user may do in the tenant that is current. Every policy in the
 * application asks this and nothing else.
 */

it('answers a permission question once and remembers the answer', function (): void {
    $tenant = tenantNamed('Acme');
    $user = memberOf($tenant);

    forTenant($tenant, function () use ($user): void {
        $permissions = app(Permissions::class);

        DB::enableQueryLog();

        foreach (range(1, 5) as $ignored) {
            expect($permissions->allow($user, PermissionCode::ApiKeyManage))->toBeTrue();
        }

        // The membership, its role and that role's permissions — loaded once,
        // however many times a request asks the question.
        expect(DB::getQueryLog())->toHaveCount(3);

        DB::disableQueryLog();
    });
});

it('grants a viewer reading and nothing more', function (): void {
    $tenant = tenantNamed('Acme');
    $viewer = memberOf($tenant, RoleSlug::Viewer);

    forTenant($tenant, function () use ($viewer): void {
        $permissions = app(Permissions::class);

        expect($permissions->allow($viewer, PermissionCode::ApiKeyRead))->toBeTrue()
            ->and($permissions->allow($viewer, PermissionCode::ApiKeyManage))->toBeFalse();
    });
});

it('grants nothing to a user with no membership in the current tenant', function (): void {
    $acme = tenantNamed('Acme');
    $globex = tenantNamed('Globex');
    $user = memberOf($acme);

    forTenant($globex, function () use ($user): void {
        expect(app(Permissions::class)->codesFor($user))->toBe([]);
    });
});
