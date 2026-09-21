<?php

declare(strict_types=1);

namespace App\Support\Ledger;

use App\Enums\DeliveryStatus;
use App\Http\Requests\IndexMessagesRequest;
use App\Models\Message;
use App\Support\Pagination\KeysetCursor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * What a message list request narrows to. Built from the Form Request that
 * already resolved and validated every part of it, the same division of
 * labour ReplayScope draws between a Form Request's own validation and the
 * query it hands off to build — a query object precedented there, not a
 * speculative one built here for a single caller.
 */
final readonly class MessageFilters
{
    private function __construct(
        private ?int $endpointId,
        private ?int $eventTypeId,
        private ?DeliveryStatus $status,
        private ?CarbonImmutable $from,
        private ?CarbonImmutable $to,
        private ?KeysetCursor $cursor,
        private int $limit,
    ) {}

    public static function fromRequest(IndexMessagesRequest $request): self
    {
        return new self(
            $request->targetEndpoint()?->id,
            $request->targetEventType()?->id,
            $request->status(),
            $request->from(),
            $request->to(),
            $request->cursor(),
            $request->limit(),
        );
    }

    public function limit(): int
    {
        return $this->limit;
    }

    /**
     * @param  Builder<Message>  $query
     * @return Builder<Message>
     */
    public function apply(Builder $query): Builder
    {
        $query
            ->when(
                $this->endpointId !== null,
                fn (Builder $q): Builder => $q->whereHas(
                    'deliveries',
                    fn (Builder $d): Builder => $d->where('endpoint_id', $this->endpointId),
                ),
            )
            ->when($this->eventTypeId !== null, fn (Builder $q): Builder => $q->where('event_type_id', $this->eventTypeId))
            ->when(
                $this->status !== null,
                fn (Builder $q): Builder => $q->whereHas(
                    'deliveries',
                    fn (Builder $d): Builder => $d->where('status', $this->status),
                ),
            )
            ->when($this->from !== null, fn (Builder $q): Builder => $q->where('created_at', '>=', $this->from))
            ->when($this->to !== null, fn (Builder $q): Builder => $q->where('created_at', '<=', $this->to))
            ->orderBy('created_at')
            ->orderBy('public_id');

        if ($this->cursor !== null) {
            $this->cursor->applyTo($query);
        }

        return $query;
    }
}
