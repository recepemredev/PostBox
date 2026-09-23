<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Delivery;
use App\Rules\ValidKeysetCursor;
use App\Support\Pagination\KeysetCursor;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * The live SSE stream. Last-Event-ID is merged into validated input in
 * prepareForValidation() so the same ValidKeysetCursor rule every other
 * cursor-paginated list already uses applies here too — the same move Step
 * 12 recorded for Idempotency-Key (D99): a header that is really an input.
 */
final class StreamRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('viewAny', Delivery::class);
    }

    /**
     * @return array<string, list<string|ValidationRule>>
     */
    public function rules(): array
    {
        return [
            'cursor' => ['nullable', 'string', new ValidKeysetCursor],
        ];
    }

    /**
     * The cursor travels as the Last-Event-ID header on a reconnect; a
     * ?cursor= query parameter is accepted as the fallback the header
     * cannot express on a first connect, since there is nothing to resume
     * from yet.
     */
    protected function prepareForValidation(): void
    {
        $lastEventId = $this->header('Last-Event-ID');

        if ($lastEventId !== null) {
            $this->merge(['cursor' => $lastEventId]);
        }
    }

    /**
     * Null for "no cursor at all" (a first connect with nothing to resume).
     * A tampered value never reaches here — ValidKeysetCursor already
     * refused it as a 422, per KeysetCursor's own "opaque, and a caller
     * that hand-edits one gets a validation failure" contract.
     */
    public function cursor(): ?KeysetCursor
    {
        return $this->filled('cursor') ? KeysetCursor::decode($this->string('cursor')->value()) : null;
    }
}
