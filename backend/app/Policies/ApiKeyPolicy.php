<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PermissionCode;
use App\Models\ApiKey;
use App\Models\User;
use App\Support\Identity\Permissions;

/**
 * Authorization asks what a user may do, never what they are called. The role
 * behind the answer can be recomposed in the database without a line of this
 * changing — which is the whole reason permissions are the unit here.
 *
 * Nothing in this policy compares tenants: the models it is asked about have
 * already come through the global scope and the database's policy, so a row from
 * another tenant never reaches it. Reading it as if it did is the mistake this
 * comment exists to prevent.
 */
final readonly class ApiKeyPolicy
{
    public function __construct(private Permissions $permissions) {}

    public function viewAny(User $user): bool
    {
        return $this->permissions->allow($user, PermissionCode::ApiKeyRead);
    }

    public function create(User $user): bool
    {
        return $this->permissions->allow($user, PermissionCode::ApiKeyManage);
    }

    public function delete(User $user, ApiKey $key): bool
    {
        return $this->permissions->allow($user, PermissionCode::ApiKeyManage);
    }
}
