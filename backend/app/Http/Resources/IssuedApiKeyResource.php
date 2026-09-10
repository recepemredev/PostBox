<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Support\Identity\IssuedApiKey;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The creation response, and the only representation that ever carries a secret.
 * It reuses the ordinary key resource rather than restating its fields, so the two
 * cannot drift into describing the same row differently.
 *
 * @property-read IssuedApiKey $resource
 */
final class IssuedApiKeyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return array_merge(
            ApiKeyResource::make($this->resource->key)->toArray($request),
            [
                // Shown once. It is not stored in this shape anywhere, so a client
                // that loses it here has to create another key.
                'token' => $this->resource->token,
            ],
        );
    }
}
