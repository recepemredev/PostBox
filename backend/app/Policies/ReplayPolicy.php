<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PermissionCode;
use App\Models\User;
use App\Support\Identity\Permissions;

/**
 * Recovery has an actor — an operator, not a system — unlike the ingest
 * surface D37 built with none. Nothing here compares tenants, for the same
 * reason ApiKeyPolicy's own comment gives: the message and endpoint a replay
 * request names have already come through the tenant scope and route model
 * binding, so one from another tenant never reaches this far.
 */
final readonly class ReplayPolicy
{
    public function __construct(private Permissions $permissions) {}

    public function create(User $user): bool
    {
        return $this->permissions->allow($user, PermissionCode::DeliveryReplay);
    }
}
