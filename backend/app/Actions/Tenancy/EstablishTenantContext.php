<?php

declare(strict_types=1);

namespace App\Actions\Tenancy;

use App\Models\Concerns\TenantScope;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantSession;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Session\Session;

/**
 * Decides which tenant an authenticated dashboard request is acting for, and makes
 * that decision true for the rest of the request — in the application and in the
 * database alike.
 *
 * Both the login response and every request after it come through here, so the
 * choice is made in exactly one place.
 */
final readonly class EstablishTenantContext
{
    private const SESSION_KEY = 'active_tenant_id';

    public function __construct(
        private TenantContext $context,
        private TenantSession $database,
        private Session $session,
    ) {}

    /**
     * @throws AuthorizationException when the account belongs to no tenant at all
     */
    public function handle(User $user): Tenant
    {
        /*
         * Bound first, because the query below runs before any tenant is current
         * and would otherwise be answered with nothing.
         */
        $this->database->bindUser($user->id);

        $memberships = Membership::query()
            /*
             * There is no tenant to scope to yet — that is the question being
             * asked. The read is not unguarded, though: the membership table's
             * self-read policy still limits it to this user's own rows, which is
             * exactly the demonstration that the second layer is doing work.
             */
            ->withoutGlobalScope(TenantScope::class)
            ->where('user_id', $user->id)
            ->with('tenant')
            ->get();

        $membership = $memberships->firstWhere('tenant_id', $this->session->get(self::SESSION_KEY))
            ?? $memberships->first();

        if (! $membership instanceof Membership) {
            throw new AuthorizationException('This account is not a member of any tenant.');
        }

        $this->session->put(self::SESSION_KEY, $membership->tenant_id);
        $this->context->set($membership->tenant);

        return $membership->tenant;
    }
}
