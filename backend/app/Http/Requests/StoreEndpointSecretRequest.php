<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\EndpointSecret;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * No body: issuing a secret takes nothing from the caller but the endpoint in
 * the route, the same way StoreApiKeyRequest's own two fields are the only
 * ones an ApiKey needs and no fewer.
 */
final class StoreEndpointSecretRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('create', EndpointSecret::class);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [];
    }
}
