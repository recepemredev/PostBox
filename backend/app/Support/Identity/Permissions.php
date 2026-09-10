<?php

declare(strict_types=1);

namespace App\Support\Identity;

use App\Enums\PermissionCode;
use App\Models\Membership;
use App\Models\Permission;
use App\Models\User;
use App\Support\Tenancy\TenantContext;

/**
 * What a user may do in the tenant that is current.
 *
 * Policies ask this and nothing else — never "is this person an admin?". A role
 * is a bundle that can be recomposed in the database; a permission is the thing
 * the code actually depends on, so the code names permissions.
 *
 * The answer is loaded once per user and tenant and kept for the rest of the
 * request. A dashboard request asks it several times over — once per policy check
 * and once more to tell the client what to render — and with strict mode turning
 * an N+1 into an exception, the memoisation here is what keeps that honest.
 */
final class Permissions
{
    /** @var array<string, list<string>> */
    private array $loaded = [];

    public function __construct(private readonly TenantContext $context) {}

    public function allow(User $user, PermissionCode $permission): bool
    {
        return in_array($permission->value, $this->codesFor($user), true);
    }

    /**
     * @return list<string>
     */
    public function codesFor(User $user): array
    {
        $tenant = $this->context->currentOrFail();

        return $this->loaded[$user->id.':'.$tenant->id] ??= $this->load($user);
    }

    /**
     * The membership is looked up through the global scope, so the tenant that is
     * current is the only one it can come from.
     *
     * @return list<string>
     */
    private function load(User $user): array
    {
        $membership = Membership::query()
            ->where('user_id', $user->id)
            ->with('role.permissions')
            ->first();

        if (! $membership instanceof Membership) {
            return [];
        }

        return array_values($membership->role->permissions
            ->map(static fn (Permission $permission): string => $permission->code)
            ->all());
    }
}
