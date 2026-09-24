<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PermissionCode;
use App\Models\User;
use App\Support\Identity\Permissions;

/**
 * Gates the operations screen (Step 17). There is no single breaker to check
 * "view" against — the read is an aggregate across every endpoint's own — so
 * this only ever answers the "any" form, the same shape DeliveryPolicy takes
 * for the live stream.
 */
final readonly class EndpointCircuitBreakerPolicy
{
    public function __construct(private Permissions $permissions) {}

    public function viewAny(User $user): bool
    {
        return $this->permissions->allow($user, PermissionCode::OperationsRead);
    }
}
