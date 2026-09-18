<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Endpoint;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

final class SendTestEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('sendTestEvent', $this->route('endpoint'));
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'event_type' => [
                'required',
                'string',
                'exists:event_types,name',
                /*
                 * Silently opening zero deliveries would defeat the whole
                 * point of the action ("prove an endpoint works without
                 * leaving the dashboard") — an unsubscribed type is a 422
                 * rather than a test event nobody notices did nothing.
                 */
                function (string $attribute, mixed $value, Closure $fail): void {
                    $endpoint = $this->route('endpoint');
                    assert($endpoint instanceof Endpoint);

                    $subscribed = $endpoint->subscriptions()
                        ->whereHas('eventType', fn ($query) => $query->where('name', $value))
                        ->exists();

                    if (! $subscribed) {
                        $fail('The endpoint is not subscribed to this event type.');
                    }
                },
            ],
        ];
    }

    public function eventType(): string
    {
        return $this->string('event_type')->value();
    }
}
