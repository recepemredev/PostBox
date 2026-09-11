<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Config;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * The advertised payload ceiling, enforced where a producer can be told about it.
 *
 * The database holds a larger ceiling of its own, so this rule is what decides
 * every rejection in practice; the constraint behind it only ever catches a write
 * that did not come through here. The two are deliberately different numbers —
 * PostgreSQL measures the payload as it renders jsonb, which is wider than the
 * bytes a producer sent.
 */
final class WithinPayloadCeiling implements ValidationRule
{
    /**
     * @param  Closure(string, string|null=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $ceiling = Config::integer('postbox.ingest.max_payload_bytes');

        // The value has already passed the `array` rule, so it came out of the
        // request's own JSON and re-encoding it cannot fail.
        if (strlen((string) json_encode($value)) > $ceiling) {
            $fail("The :attribute must not be larger than {$ceiling} bytes.");
        }
    }
}
