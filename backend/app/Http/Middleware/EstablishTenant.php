<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Actions\Tenancy\EstablishTenantContext;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Puts the dashboard's tenant in place for the request. It runs after `auth`, so
 * the only question left is which of the user's tenants this request is for.
 */
final readonly class EstablishTenant
{
    public function __construct(private EstablishTenantContext $establish) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User) {
            $this->establish->handle($user);
        }

        return $next($request);
    }
}
