<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Endpoint;
use App\Rules\NotBlockedAddress;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

final class StoreEndpointRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('create', Endpoint::class);
    }

    /**
     * @return array<string, list<string|ValidationRule>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'url' => ['required', 'string', 'max:2048', 'url', new NotBlockedAddress],
        ];
    }
}
