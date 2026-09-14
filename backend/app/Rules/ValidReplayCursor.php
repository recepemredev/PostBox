<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\Recovery\ReplayCursor;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * A cursor is opaque, so the only thing worth validating about one is that it
 * decodes at all. ReplayRangeRequest validates the shape; ReplayController
 * decodes it again for the action to use, the same trade
 * PublishMessageRequest's own event_type rule makes against PublishMessage's
 * later lookup — the action stays callable on its own terms.
 */
final class ValidReplayCursor implements ValidationRule
{
    /**
     * @param  Closure(string, string|null=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // The 'string' rule alongside this one already refused anything
        // else; this is only ever reached with $value already a string.
        if (! is_string($value) || ReplayCursor::decode($value) === null) {
            $fail('The :attribute is not a valid replay cursor.');
        }
    }
}
