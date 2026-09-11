<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Rules\WithinPayloadCeiling;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * There is no authorize() here, and the omission is deliberate. A policy answers
 * "may this actor do this to this resource", and the ingest surface has no actor:
 * an API key produces a tenant, not a person. What stands in for authorization is
 * the tenant boundary itself — the application in the route resolves through the
 * tenant scope, so another tenant's application is a 404 before this class is
 * ever constructed, and the event type below is only findable within the tenant
 * for the same reason.
 */
final class PublishMessageRequest extends FormRequest
{
    /**
     * @return array<string, list<string|ValidationRule>>
     */
    public function rules(): array
    {
        return [
            /*
             * An event type is registered before it can be published, rather than
             * created here: a typo would otherwise become a permanent event type
             * that no endpoint is subscribed to, and the message would be accepted
             * and delivered nowhere. The lookup needs no tenant predicate of its
             * own — the application connects as a role Row Level Security applies
             * to, so it can only ever match a row in the current tenant.
             */
            'event_type' => ['required', 'string', 'exists:event_types,name'],

            'payload' => ['required', 'array', new WithinPayloadCeiling],

            /*
             * Optional: a producer that does not care about replaying is not made
             * to invent a key. Bounded by what the column holds, and confined to
             * visible ASCII so nothing a producer chooses can put a control
             * character into storage or into a log line.
             */
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

    public function idempotencyKey(): ?string
    {
        return $this->filled('idempotency_key')
            ? $this->string('idempotency_key')->value()
            : null;
    }

    /**
     * The key travels as a header and validation reads input, so it is put where
     * the rules can see it. Merging also overwrites anything a producer sent in
     * the body under that name: there is one place this value comes from.
     */
    protected function prepareForValidation(): void
    {
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
    }
}
