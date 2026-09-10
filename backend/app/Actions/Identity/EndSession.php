<?php

declare(strict_types=1);

namespace App\Actions\Identity;

use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Contracts\Session\Session;

/**
 * Ends a dashboard session — all three parts of it. Forgetting the user without
 * invalidating the session leaves the old identifier able to authenticate again,
 * and invalidating without a fresh CSRF token leaves the next form unsubmittable.
 */
final readonly class EndSession
{
    public function __construct(
        private StatefulGuard $guard,
        private Session $session,
    ) {}

    public function handle(): void
    {
        $this->guard->logout();

        $this->session->invalidate();
        $this->session->regenerateToken();
    }
}
