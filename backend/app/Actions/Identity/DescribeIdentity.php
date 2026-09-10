<?php

declare(strict_types=1);

namespace App\Actions\Identity;

use App\Models\User;
use App\Support\Identity\Identity;
use App\Support\Identity\Permissions;
use App\Support\Tenancy\TenantContext;

/**
 * Answers "who am I, where am I, and what may I do here?" in one object. The
 * dashboard needs all three to render anything, and asking them separately is how
 * a client ends up showing a control the server will refuse.
 */
final readonly class DescribeIdentity
{
    public function __construct(
        private TenantContext $context,
        private Permissions $permissions,
    ) {}

    public function handle(User $user): Identity
    {
        return new Identity(
            $user,
            $this->context->currentOrFail(),
            $this->permissions->codesFor($user),
        );
    }
}
