<?php

declare(strict_types=1);

namespace App\Actions\Identity;

use App\Models\ApiKey;
use Carbon\CarbonImmutable;

/**
 * Revokes a key, and says when rather than deleting the row: the delivery history
 * that key produced still points at it, and an operator asking "what was calling
 * us last Tuesday?" deserves an answer.
 *
 * Revoking twice is the same as revoking once — the first time is the one that
 * counts, so a repeated call cannot quietly move the date forward.
 */
final readonly class RevokeApiKey
{
    public function handle(ApiKey $key): ApiKey
    {
        if ($key->revoked_at === null) {
            $key->forceFill(['revoked_at' => CarbonImmutable::now()])->save();
        }

        return $key;
    }
}
