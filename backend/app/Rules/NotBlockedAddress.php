<?php

declare(strict_types=1);

namespace App\Rules;

use App\Exceptions\BlockedTarget;
use App\Support\Delivery\AddressGuard;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * CLAUDE.md: "Endpoint URLs are validated against private, loopback and
 * link-local ranges before every request, not only at registration." This is
 * the registration half, reusing the exact guard every delivery attempt
 * resolves against rather than a second definition of the disallowed ranges.
 *
 * The resolution done here is thrown away: DNS can change between now and
 * the first send, so AttemptDelivery resolves again, for real, before every
 * attempt (Step 6). This rule catches the common case — an operator pointing
 * an endpoint at a private address — at the moment they save it, without
 * pretending to be the guard that actually matters.
 */
final class NotBlockedAddress implements ValidationRule
{
    /**
     * @param  Closure(string, string|null=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        try {
            app(AddressGuard::class)->guard($value);
        } catch (BlockedTarget $exception) {
            $fail($exception->getMessage());
        }
    }
}
