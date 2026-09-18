<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PermissionCode;
use App\Models\User;
use App\Support\Identity\Permissions;

final readonly class EventTypePolicy
{
    public function __construct(private Permissions $permissions) {}

    public function viewAny(User $user): bool
    {
        return $this->permissions->allow($user, PermissionCode::CatalogRead);
    }

    public function create(User $user): bool
    {
        return $this->permissions->allow($user, PermissionCode::CatalogManage);
    }
}
