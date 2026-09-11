<?php

declare(strict_types=1);

namespace App\Actions\Ingest;

use App\Models\IdempotencyKey;
use Carbon\CarbonImmutable;

/**
 * Drops the reservations whose window has passed, for the tenant that is
 * current.
 *
 * Deleting the row is what expiry means. Nothing reads expires_at to decide
 * whether a key still counts — while the row is there the key is spent, and once
 * it is gone the same key publishes a new message. Keeping the decision in one
 * place is why a producer cannot get two different answers depending on which
 * code path asked.
 *
 * The message a reservation pointed at is not touched. The ledger has a
 * retention policy of its own, and it is not this pass's business.
 */
final readonly class PruneIdempotencyKeys
{
    /**
     * @return int how many reservations were dropped
     */
    public function handle(): int
    {
        $dropped = IdempotencyKey::query()
            ->where('expires_at', '<=', CarbonImmutable::now())
            ->delete();

        // Eloquent types a mass delete as mixed; what it returns is the number
        // of rows it removed.
        assert(is_int($dropped));

        return $dropped;
    }
}
