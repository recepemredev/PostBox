<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\Pagination\KeysetCursor;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * A cursor is opaque, so the only thing worth validating about one is that
 * it decodes at all. Shared by every keyset-paginated list — Recovery's
 * range replay and Ledger's message and attempt lists alike — since a
 * cursor's own validity never depends on what it pages.
 */
final class ValidKeysetCursor implements ValidationRule
{
    /**
     * @param  Closure(string, string|null=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // The 'string' rule alongside this one already refused anything
        // else; this is only ever reached with $value already a string.
        if (! is_string($value) || KeysetCursor::decode($value) === null) {
            $fail('The :attribute is not a valid pagination cursor.');
        }
    }
}
