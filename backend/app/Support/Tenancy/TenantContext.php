<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Exceptions\MissingTenantContext;
use App\Models\Tenant;
use Closure;

/**
 * The tenant the current request, job or command is acting for.
 *
 * Everything that scopes to a tenant reads it from here: the global scope, the
 * write-time stamp, and — through TenantSession — the database itself. It is a
 * singleton with request lifetime; nothing outside this class may be the second
 * place a tenant is remembered.
 */
final class TenantContext
{
    private ?Tenant $tenant = null;

    public function __construct(private readonly TenantSession $session) {}

    public function set(Tenant $tenant): void
    {
        $this->tenant = $tenant;
        $this->session->bindTenant($tenant->id);
    }

    public function forget(): void
    {
        $this->tenant = null;
        $this->session->bindTenant(null);
    }

    public function current(): ?Tenant
    {
        return $this->tenant;
    }

    public function currentOrFail(): Tenant
    {
        return $this->tenant ?? throw MissingTenantContext::forRead();
    }

    public function has(): bool
    {
        return $this->tenant instanceof Tenant;
    }

    /**
     * Runs a callback with a different tenant current, and restores whatever was
     * current before — including nothing. This is the only supported way to act
     * for a tenant other than the one the request authenticated as.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function runFor(Tenant $tenant, Closure $callback): mixed
    {
        $previous = $this->tenant;

        $this->set($tenant);

        try {
            return $callback();
        } finally {
            $previous instanceof Tenant ? $this->set($previous) : $this->forget();
        }
    }
}
