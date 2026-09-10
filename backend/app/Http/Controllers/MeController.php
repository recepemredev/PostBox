<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Identity\DescribeIdentity;
use App\Http\Resources\IdentityResource;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * What the dashboard calls on load to find out whether it has a session, which
 * tenant it is in, and what to render.
 */
final class MeController extends Controller
{
    public function __invoke(Request $request, DescribeIdentity $describe): IdentityResource
    {
        $user = $request->user();

        // The route is behind `auth`, so this is a fact rather than a check.
        assert($user instanceof User);

        return IdentityResource::make($describe->handle($user));
    }
}
