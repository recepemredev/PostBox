<?php

declare(strict_types=1);

namespace App\Support\Identity;

use App\Models\Tenant;
use App\Models\User;

/**
 * Who the dashboard is talking to: the person, the tenant they are acting for,
 * and what that combination is allowed to do. The three are always answered
 * together, because any two of them without the third is not an answer.
 *
 * @phpstan-type PermissionCodes list<string>
 */
final readonly class Identity
{
    /**
     * @param  list<string>  $permissions
     */
    public function __construct(
        public User $user,
        public Tenant $tenant,
        public array $permissions,
    ) {}
}
