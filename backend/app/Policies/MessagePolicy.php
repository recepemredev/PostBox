<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PermissionCode;
use App\Models\Message;
use App\Models\User;
use App\Support\Identity\Permissions;

/**
 * Nothing here compares tenants, for the same reason every other policy's
 * own docblock states: a Message reaching this policy has already come
 * through the global scope and Row Level Security.
 */
final readonly class MessagePolicy
{
    public function __construct(private Permissions $permissions) {}

    public function viewAny(User $user): bool
    {
        return $this->permissions->allow($user, PermissionCode::LedgerRead);
    }

    public function view(User $user, Message $message): bool
    {
        return $this->permissions->allow($user, PermissionCode::LedgerRead);
    }
}
