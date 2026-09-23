<?php

declare(strict_types=1);

namespace App\Actions\Stream;

use App\Models\DeliveryAttempt;
use App\Support\Pagination\CursorPage;
use App\Support\Pagination\KeysetCursor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;

/**
 * The live stream's own read. It does not loop and does not sleep — it
 * answers one poll: the page of attempts after a cursor, whose own second
 * has fully elapsed past the watermark margin (D126). Keeping the loop out
 * is what makes the priority tests deterministic: they call poll() directly
 * with a pinned $now and assert row-for-row, with no HTTP and no wall-clock
 * flakiness — the same precedent DispatchOutbox and PublishMessage already
 * set for an injected clock.
 *
 * Tenant scoping needs no code here at all: BelongsToTenant's global scope
 * and Row Level Security both already apply to DeliveryAttempt, which is
 * the entire point of the three-layer design — a query never crosses the
 * tenant boundary because nothing here ever has the chance to forget to
 * filter.
 */
final readonly class StreamAttempts
{
    /**
     * @return CursorPage<DeliveryAttempt>
     */
    public function poll(?KeysetCursor $cursor, CarbonImmutable $now): CursorPage
    {
        $query = DeliveryAttempt::query()
            ->with(['delivery.message.eventType', 'endpoint'])
            ->where('created_at', '<=', $now->subSeconds(Config::integer('postbox.stream.watermark_seconds')))
            ->orderBy('created_at')
            ->orderBy('public_id');

        $cursor?->applyTo($query);

        return CursorPage::fetch(
            $query,
            Config::integer('postbox.stream.batch_size'),
            static fn (DeliveryAttempt $attempt): KeysetCursor => KeysetCursor::after($attempt->created_at, $attempt->public_id),
        );
    }
}
