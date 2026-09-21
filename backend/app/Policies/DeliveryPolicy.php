<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PermissionCode;
use App\Models\Delivery;
use App\Models\User;
use App\Support\Identity\Permissions;

/**
 * Gates the attempt inspector (Step 14) — the same permission MessagePolicy
 * reads, since reading a delivery's own attempt history is the same fact as
 * reading the message it belongs to, not a separately grantable one.
 */
final readonly class DeliveryPolicy
{
    public function __construct(private Permissions $permissions) {}

    public function view(User $user, Delivery $delivery): bool
    {
        return $this->permissions->allow($user, PermissionCode::LedgerRead);
    }
}
