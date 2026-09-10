<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Identity\AuthenticateUser;
use App\Actions\Identity\DescribeIdentity;
use App\Actions\Identity\EndSession;
use App\Actions\Tenancy\EstablishTenantContext;
use App\Http\Requests\LoginRequest;
use App\Http\Resources\IdentityResource;
use Illuminate\Http\Response;

final class SessionController extends Controller
{
    public function store(
        LoginRequest $request,
        AuthenticateUser $authenticate,
        EstablishTenantContext $establish,
        DescribeIdentity $describe,
    ): IdentityResource {
        $user = $authenticate->handle(
            $request->string('email')->value(),
            $request->string('password')->value(),
        );

        // The tenant middleware does this for every later request; login is the
        // one request that arrives before it has run.
        $establish->handle($user);

        return IdentityResource::make($describe->handle($user));
    }

    public function destroy(EndSession $endSession): Response
    {
        $endSession->handle();

        return response()->noContent();
    }
}
