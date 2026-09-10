<?php

declare(strict_types=1);

namespace App\Actions\Identity;

use App\Models\User;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Contracts\Session\Session;
use Illuminate\Validation\ValidationException;

/**
 * Exchanges a password for a dashboard session.
 */
final readonly class AuthenticateUser
{
    public function __construct(
        private StatefulGuard $guard,
        private Session $session,
    ) {}

    public function handle(string $email, string $password): User
    {
        if (! $this->guard->attempt(['email' => $email, 'password' => $password])) {
            /*
             * One message for a wrong password and for an address that has never
             * registered. Telling the two apart is an account enumeration oracle,
             * and the field it is attached to is the only hint a real user needs.
             */
            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records.',
            ]);
        }

        /*
         * The identifier the browser arrived with must not be the identifier it
         * leaves authenticated with, or a session fixed before login stays valid
         * after it.
         */
        $this->session->regenerate();

        $user = $this->guard->user();

        // The guard authenticated against this provider, so this cannot be
        // anything else; the check is here so the type is a fact, not a comment.
        assert($user instanceof User);

        return $user;
    }
}
