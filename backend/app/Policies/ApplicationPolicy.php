<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PermissionCode;
use App\Models\Application;
use App\Models\User;
use App\Support\Identity\Permissions;

/**
 * Nothing here compares tenants: an Application reaching this policy has
 * already come through the global scope and Row Level Security, so a row
 * from another tenant never reaches it — the same reasoning ApiKeyPolicy's
 * own docblock states.
 */
final readonly class ApplicationPolicy
{
    public function __construct(private Permissions $permissions) {}

    public function viewAny(User $user): bool
    {
        return $this->permissions->allow($user, PermissionCode::CatalogRead);
    }

    public function view(User $user, Application $application): bool
    {
        return $this->permissions->allow($user, PermissionCode::CatalogRead);
    }

    public function create(User $user): bool
    {
        return $this->permissions->allow($user, PermissionCode::CatalogManage);
    }

    public function update(User $user, Application $application): bool
    {
        return $this->permissions->allow($user, PermissionCode::CatalogManage);
    }
}
