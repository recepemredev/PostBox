<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Replay;
use App\Rules\ValidReplayCursor;
use App\Support\Recovery\ReplayCursor;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

final class ReplayRangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('create', Replay::class);
    }

    /**
     * @return array<string, list<string|ValidationRule>>
     */
    public function rules(): array
    {
        return [
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after:from'],

            // Opaque; a caller only ever carries one back from a previous
            // response, never composes one by hand.
            'cursor' => ['nullable', 'string', new ValidReplayCursor],

            'idempotency_key' => ['nullable', 'string', 'max:255', 'regex:/^[\x21-\x7e]+$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['idempotency_key' => 'Idempotency-Key header'];
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function range(): array
    {
        return [
            CarbonImmutable::parse($this->string('from')->value()),
            CarbonImmutable::parse($this->string('to')->value()),
        ];
    }

    /**
     * Decoded again rather than handed over from the rule that already
     * proved it decodes — the same trade PublishMessageRequest's own
     * event_type rule makes against PublishMessage's later lookup.
     */
    public function cursor(): ?ReplayCursor
    {
        return $this->filled('cursor') ? ReplayCursor::decode($this->string('cursor')->value()) : null;
    }

    public function idempotencyKey(): ?string
    {
        return $this->filled('idempotency_key')
            ? $this->string('idempotency_key')->value()
            : null;
    }

    /**
     * The same seam PublishMessageRequest uses: the key travels as a header,
     * and validation reads input.
     */
    protected function prepareForValidation(): void
    {
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
    }
}
