<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\IndexDeliveryAttemptsRequest;
use App\Http\Resources\DeliveryAttemptCollection;
use App\Models\Delivery;
use App\Models\DeliveryAttempt;
use App\Support\Pagination\CursorPage;
use App\Support\Pagination\KeysetCursor;

final class DeliveryAttemptController extends Controller
{
    /**
     * The retry timeline's own read (Step 14): one delivery's own attempts,
     * in order — the index delivery_attempts_delivery_created_at_index was
     * built for at Step 3, with this exact query already in its own
     * comment. A separate route from the message detail view for the same
     * reason Ledger's own message list is separate from Recovery's replay
     * actions: delivery_attempts is append-only and unbounded per
     * conventions.md, so it gets its own cursor rather than riding along
     * inside a message's own (bounded, unpaginated) delivery list.
     */
    public function index(IndexDeliveryAttemptsRequest $request, Delivery $delivery): DeliveryAttemptCollection
    {
        $query = DeliveryAttempt::query()
            ->with(['delivery', 'endpoint'])
            ->where('delivery_id', $delivery->id)
            ->orderBy('created_at')
            ->orderBy('public_id');

        $request->cursor()?->applyTo($query);

        $page = CursorPage::fetch(
            $query,
            $request->limit(),
            static fn (DeliveryAttempt $attempt): KeysetCursor => KeysetCursor::after($attempt->created_at, $attempt->public_id),
        );

        return DeliveryAttemptCollection::make($page);
    }
}
