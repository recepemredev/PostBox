<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Support\Catalog\IssuedEndpointSecret;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The creation response, and the only representation that ever carries a
 * secret's plaintext. It reuses EndpointSecretResource rather than restating
 * its fields, the same way IssuedApiKeyResource reuses ApiKeyResource — so
 * the two cannot drift into describing the same row differently.
 *
 * @property-read IssuedEndpointSecret $resource
 */
final class IssuedEndpointSecretResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return array_merge(
            EndpointSecretResource::make($this->resource->secret)->toArray($request),
            [
                // Shown once. A client that loses it here has to issue
                // another secret — the row can decrypt its own value again,
                // but nothing in this API will ever hand it back out.
                'secret' => $this->resource->plaintext,
            ],
        );
    }
}
