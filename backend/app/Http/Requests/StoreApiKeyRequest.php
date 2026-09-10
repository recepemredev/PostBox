<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\ApiKey;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

final class StoreApiKeyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('create', ApiKey::class);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],

            // Optional, because most keys are meant to outlive the person who
            // created them; bounded when given, because a key that expires in the
            // past is a support ticket rather than a credential.
            'expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }
}
