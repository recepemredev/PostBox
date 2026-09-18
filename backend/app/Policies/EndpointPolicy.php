<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PermissionCode;
use App\Models\Endpoint;
use App\Models\User;
use App\Support\Identity\Permissions;

/**
 * Nothing here compares tenants, for the same reason ApiKeyPolicy's own
 * docblock states: an Endpoint reaching this policy has already come through
 * the global scope and Row Level Security.
 *
 * sendTestEvent is its own permission (endpoint.test) rather than folded
 * into update: a viewer can read an endpoint's configuration under
 * catalog.read, but causing real outbound traffic is a different kind of
 * consequence and D79 draws exactly this line for Recovery's own actions.
 */
final readonly class EndpointPolicy
{
    public function __construct(private Permissions $permissions) {}

    public function viewAny(User $user): bool
    {
        return $this->permissions->allow($user, PermissionCode::CatalogRead);
    }

    public function view(User $user, Endpoint $endpoint): bool
    {
        return $this->permissions->allow($user, PermissionCode::CatalogRead);
    }

    public function create(User $user): bool
    {
        return $this->permissions->allow($user, PermissionCode::CatalogManage);
    }

    public function update(User $user, Endpoint $endpoint): bool
    {
        return $this->permissions->allow($user, PermissionCode::CatalogManage);
    }

    public function sendTestEvent(User $user, Endpoint $endpoint): bool
    {
        return $this->permissions->allow($user, PermissionCode::EndpointTest);
    }
}
