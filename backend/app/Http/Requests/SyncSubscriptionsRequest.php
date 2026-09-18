<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

final class SyncSubscriptionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('update', $this->route('endpoint'));
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'event_types' => ['present', 'array'],
            'event_types.*' => ['string', 'exists:event_types,name'],
        ];
    }

    /**
     * @return list<string>
     */
    public function eventTypeNames(): array
    {
        /** @var list<string> $names */
        $names = array_values($this->array('event_types'));

        return $names;
    }
}
