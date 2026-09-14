<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Endpoint;
use App\Models\Replay;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

final class ReplayMessageRequest extends FormRequest
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
            /*
             * Narrows the replay to one of the message's original
             * subscribers. Absent, every endpoint the message's own fan-out
             * reached is replayed. The lookup needs no tenant predicate of
             * its own, the same reason PublishMessageRequest's event_type
             * rule needs none: the application connects as a role Row Level
             * Security applies to, so it can only ever match this tenant's
             * own endpoint.
             */
            'endpoint' => ['nullable', 'string', 'exists:endpoints,public_id'],

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

    public function targetEndpoint(): ?Endpoint
    {
        return $this->filled('endpoint')
            ? Endpoint::query()->where('public_id', $this->string('endpoint')->value())->first()
            : null;
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
