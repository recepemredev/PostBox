<?php

declare(strict_types=1);

namespace App\Actions\Tenancy;

use App\Enums\RoleSlug;
use App\Models\Membership;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\DatabaseManager;

/**
 * Creates a tenant and the administrator who will run it.
 *
 * There is no public registration surface: PostBox is self-hosted, and an
 * unauthenticated endpoint that mints tenants is an attack surface a self-hosted
 * gateway has no reason to expose. Tenants are created by whoever runs the
 * instance, through the console command that wraps this.
 */
final readonly class CreateTenant
{
    public function __construct(
        private TenantContext $context,
        private DatabaseManager $database,
    ) {}

    public function handle(string $tenantName, string $userName, string $email, string $password): Membership
    {
        return $this->database->connection()->transaction(function () use ($tenantName, $userName, $email, $password): Membership {
            $tenant = Tenant::create(['name' => $tenantName]);

            $user = User::create([
                'name' => $userName,
                'email' => $email,
                'password' => $password,
            ]);

            $role = Role::query()->where('slug', RoleSlug::Admin->value)->sole();

            /*
             * The membership is the first tenant-owned row in the system, and it
             * is written for a tenant nobody is authenticated as. runFor() is how
             * that is done deliberately and briefly, instead of by handing the
             * writer a way to name a tenant on the row itself.
             */
            $membership = $this->context->runFor($tenant, static fn (): Membership => Membership::create([
                'user_id' => $user->id,
                'role_id' => $role->id,
            ]));

            // Set rather than loaded: the caller reports both, and lazy loading
            // is an exception in this application, not a convenience.
            $membership->setRelation('tenant', $tenant);
            $membership->setRelation('user', $user);

            return $membership;
        });
    }
}
