<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Rules\ValidKeysetCursor;
use App\Support\Pagination\KeysetCursor;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Gate;

/**
 * The retry timeline's own read: no filter beyond the delivery already named
 * in the route, since one delivery's own attempt history is already bounded
 * by its own retry schedule (at most RetryPolicy's own max_attempts rows) —
 * cursor pagination exists here for the same append-only-table reason
 * conventions.md names, not because a real page is ever likely to run past
 * one.
 */
final class IndexDeliveryAttemptsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('view', $this->route('delivery'));
    }

    /**
     * @return array<string, list<string|ValidationRule>>
     */
    public function rules(): array
    {
        return [
            'cursor' => ['nullable', 'string', new ValidKeysetCursor],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.Config::integer('postbox.ledger.max_page_size')],
        ];
    }

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
