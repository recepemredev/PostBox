<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\DeliveryStatus;
use App\Models\Endpoint;
use App\Models\EventType;
use App\Models\Message;
use App\Rules\ValidKeysetCursor;
use App\Support\Pagination\KeysetCursor;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Gate;

/**
 * The message list's own filters: endpoint, event type, status and a time
 * range, each optional. "Status" has no column of its own to filter on —
 * there is no MessageStatus (a message is not the thing that succeeds or
 * fails, a delivery is) — so it is answered by whether the message has a
 * delivery in that state, the same reading MessageFilters::apply() applies.
 */
final class IndexMessagesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('viewAny', Message::class);
    }

    /**
     * @return array<string, list<string|ValidationRule>>
     */
    public function rules(): array
    {
        return [
            'endpoint' => ['nullable', 'string', 'exists:endpoints,public_id'],
            'event_type' => ['nullable', 'string', 'exists:event_types,name'],

            // A plain 'in:' list rather than Rule::enum(): that helper
            // implements the older Rule contract, not ValidationRule, so it
            // does not fit the one shape every rule in this class carries
            // (the same choice UpdateEndpointRequest already made).
            'status' => ['nullable', 'in:'.implode(',', array_column(DeliveryStatus::cases(), 'value'))],

            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after:from'],

            // Opaque; a caller only ever carries one back from a previous
            // response, never composes one by hand.
            'cursor' => ['nullable', 'string', new ValidKeysetCursor],

            'limit' => ['nullable', 'integer', 'min:1', 'max:'.Config::integer('postbox.ledger.max_page_size')],
        ];
    }

    /**
     * No tenant predicate of its own, the same reason ReplayMessageRequest's
     * own endpoint rule needs none: the application connects as a role Row
     * Level Security applies to, so `exists` can only ever match this
     * tenant's own endpoint.
     */
    public function targetEndpoint(): ?Endpoint
    {
        return $this->filled('endpoint')
            ? Endpoint::query()->where('public_id', $this->string('endpoint')->value())->first()
            : null;
    }

    public function targetEventType(): ?EventType
    {
        return $this->filled('event_type')
            ? EventType::query()->where('name', $this->string('event_type')->value())->first()
            : null;
    }

    public function status(): ?DeliveryStatus
    {
        return $this->filled('status') ? DeliveryStatus::from($this->string('status')->value()) : null;
    }

    public function from(): ?CarbonImmutable
    {
        return $this->filled('from') ? CarbonImmutable::parse($this->string('from')->value()) : null;
    }

    public function to(): ?CarbonImmutable
    {
        return $this->filled('to') ? CarbonImmutable::parse($this->string('to')->value()) : null;
    }

    /**
     * Decoded again rather than handed over from the rule that already
     * proved it decodes — the same trade ReplayRangeRequest's own cursor
     * rule makes.
     */
    public function cursor(): ?KeysetCursor
    {
        return $this->filled('cursor') ? KeysetCursor::decode($this->string('cursor')->value()) : null;
    }

    public function limit(): int
    {
        $max = Config::integer('postbox.ledger.max_page_size');
        $requested = $this->filled('limit') ? $this->integer('limit') : Config::integer('postbox.ledger.default_page_size');

        return min($requested, $max);
    }
}
