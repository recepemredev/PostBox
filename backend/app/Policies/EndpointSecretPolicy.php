<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PermissionCode;
use App\Models\EndpointSecret;
use App\Models\User;
use App\Support\Identity\Permissions;

/**
 * A signing secret is a credential, not merely a catalog fact — reading its
 * metadata (never the plaintext, which no policy could reveal even if it
 * wanted to) still needs catalog.read, but issuing or revoking one needs
 * endpoint_secret.manage, split from catalog.manage the same way
 * ApiKeyPolicy splits api_key.read from api_key.manage.
 */
final readonly class EndpointSecretPolicy
{
    public function __construct(private Permissions $permissions) {}

    public function viewAny(User $user): bool
    {
        return $this->permissions->allow($user, PermissionCode::CatalogRead);
    }

    public function create(User $user): bool
    {
        return $this->permissions->allow($user, PermissionCode::EndpointSecretManage);
    }

    public function delete(User $user, EndpointSecret $secret): bool
    {
        return $this->permissions->allow($user, PermissionCode::EndpointSecretManage);
    }
}
