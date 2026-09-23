<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PermissionCode;
use App\Models\Delivery;
use App\Models\User;
use App\Support\Identity\Permissions;

/**
 * Gates the attempt inspector (Step 14) and the live SSE stream (Step 15) —
 * the same permission MessagePolicy reads, since reading a delivery's own
 * attempt history, or the live feed of every attempt as it happens, is the
 * same fact as reading the message it belongs to, not a separately
 * grantable one.
 */
final readonly class DeliveryPolicy
{
    public function __construct(private Permissions $permissions) {}

    public function view(User $user, Delivery $delivery): bool
    {
        return $this->permissions->allow($user, PermissionCode::LedgerRead);
    }

    /**
     * The stream has no single Delivery to check "view" against — it reads
     * across all of a tenant's own deliveries — so it asks the "any" form
     * the same way messages.index and deliveries.attempts.index's own
     * MessagePolicy::viewAny() does.
     */
    public function viewAny(User $user): bool
    {
        return $this->permissions->allow($user, PermissionCode::LedgerRead);
    }
}
