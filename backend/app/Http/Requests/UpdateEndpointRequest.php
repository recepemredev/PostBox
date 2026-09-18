<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\EndpointStatus;
use App\Rules\NotBlockedAddress;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

final class UpdateEndpointRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('update', $this->route('endpoint'));
    }

    /**
     * @return array<string, list<string|ValidationRule>>
     */
    public function rules(): array
    {
        return [
            // Every field is a partial update: an operator flipping the
            // status switch should not have to resend the name and URL too.
            'name' => ['sometimes', 'string', 'max:255'],
            'url' => ['sometimes', 'string', 'max:2048', 'url', new NotBlockedAddress],

            // A plain 'in:' list rather than Rule::enum(): that helper
            // implements the older Rule contract, not ValidationRule, so it
            // does not fit the one shape every rule in this class carries.
            'status' => ['sometimes', 'in:'.implode(',', array_column(EndpointStatus::cases(), 'value'))],
        ];
    }

    /**
     * @return array{name?: string, url?: string, status?: EndpointStatus}
     */
    public function changes(): array
    {
        $changes = [];

        if ($this->filled('name')) {
            $changes['name'] = $this->string('name')->value();
        }

        if ($this->filled('url')) {
            $changes['url'] = $this->string('url')->value();
        }

        if ($this->filled('status')) {
            $changes['status'] = EndpointStatus::from($this->string('status')->value());
        }

        return $changes;
    }
}
